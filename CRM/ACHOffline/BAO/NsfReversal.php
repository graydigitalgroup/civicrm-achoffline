<?php

use Civi\ACHOffline\Event\ContributionReissuedEvent;
use Civi\ACHOffline\Event\ContributionReversedEvent;
use Civi\Api4\Contribution;
use Civi\Api4\FinancialItem;
use Civi\Api4\LineItem;
use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Reverse an ACH contribution that the bank returned (e.g. insufficient funds)
 * and reissue it as an open, collectible contribution.
 *
 * The original is cancelled (core unwinds any recorded money) and a new Pending
 * contribution is created carrying the original line items plus, when
 * configured, an NSF-fee line. Keeping the balance on a fresh Pending record —
 * rather than editing the settled original — stays inside core's supported
 * financial operations (a contribution with a payment can't have its amount
 * changed) and lets staff collect it by any method.
 *
 * Consumers that track their own per-line records (soft credits, bill-to
 * bridges) react to the two events fired at the end; this class references none
 * of them.
 */
class CRM_ACHOffline_BAO_NsfReversal {

  /**
   * Statuses that already represent a reversed/closed contribution — running
   * the reversal against one of these is a no-op so the task is safe to re-run.
   */
  private const TERMINAL_STATUSES = ['Cancelled', 'Chargeback', 'Refunded', 'Failed'];

  /**
   * Options for the reversed-contribution status setting. Both let core unwind
   * recorded financials on the Completed -> reversed transition.
   */
  public static function getReversalStatusOptions(): array {
    return [
      'Cancelled' => E::ts('Cancelled'),
      'Chargeback' => E::ts('Chargeback'),
    ];
  }

  /**
   * Reverse and reissue a single contribution.
   *
   * @return array{original_id:int,new_id:?int,was_paid:bool,skipped:bool}
   *
   * @throws \CRM_Core_Exception
   */
  public static function reverse(int $contributionID): array {
    $original = Contribution::get(FALSE)
      ->addSelect('*', 'contribution_status_id:name', 'ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first();

    if (empty($original)) {
      throw new CRM_Core_Exception(E::ts('Contribution %1 not found.', [1 => $contributionID]));
    }

    // Safe to re-run: a contribution already reversed is left untouched.
    if (in_array($original['contribution_status_id:name'], self::TERMINAL_STATUSES, TRUE)) {
      return ['original_id' => $contributionID, 'new_id' => NULL, 'was_paid' => FALSE, 'skipped' => TRUE];
    }

    $wasPaid = in_array($original['contribution_status_id:name'], ['Completed', 'Partially paid'], TRUE);

    $transaction = new CRM_Core_Transaction();
    try {
      $reissue = self::reissue($original);

      // Cancel the original. On a Completed/Partially paid contribution core
      // records the reversing financial entries as part of this transition.
      $reversalStatus = \Civi::settings()->get('achoffline_nsf_reversal_status') ?: 'Cancelled';
      Contribution::update(FALSE)
        ->addValue('contribution_status_id:name', $reversalStatus)
        ->addValue('cancel_date', date('Y-m-d H:i:s'))
        ->addValue('cancel_reason', E::ts('Reversed (NSF / returned payment); reissued as contribution #%1.', [1 => $reissue['id']]))
        ->addWhere('id', '=', $contributionID)
        ->execute();

      // Reissue is announced BEFORE reversal so a consumer can copy per-line
      // records (soft credits, bridges) from the original onto the new
      // contribution while the original still has them, then react to the
      // original's reversal (which may remove those records).
      \Civi::dispatcher()->dispatch(
        ContributionReissuedEvent::NAME,
        new ContributionReissuedEvent($contributionID, $reissue['id'], $reissue['lineItemMap'], $reissue['feeLineItemId'])
      );
      \Civi::dispatcher()->dispatch(
        ContributionReversedEvent::NAME,
        new ContributionReversedEvent($contributionID, $wasPaid, 'nsf')
      );
    }
    catch (\Throwable $e) {
      $transaction->rollback();
      throw $e;
    }
    $transaction->commit();

    return ['original_id' => $contributionID, 'new_id' => $reissue['id'], 'was_paid' => $wasPaid, 'skipped' => FALSE];
  }

  /**
   * Create the open replacement contribution: clone the original's core fields
   * and line items, then append the NSF-fee line when one is configured.
   *
   * @return array{id:int,lineItemMap:array<int,int>,feeLineItemId:?int}
   *
   * @throws \CRM_Core_Exception
   */
  private static function reissue(array $original): array {
    $fee = self::getFeeAmount();
    $originalTotal = (float) $original['total_amount'];
    $newTotal = $originalTotal + $fee;

    $values = [
      'contact_id'              => $original['contact_id'],
      'financial_type_id'       => $original['financial_type_id'],
      'currency'                => $original['currency'],
      'contribution_recur_id'   => $original['contribution_recur_id'] ?? NULL,
      'payment_instrument_id'   => $original['payment_instrument_id'] ?? NULL,
      'campaign_id'             => $original['campaign_id'] ?? NULL,
      'is_test'                 => $original['is_test'] ?? FALSE,
      'total_amount'            => $newTotal,
      'contribution_status_id:name' => 'Pending',
      'receive_date'            => date('Y-m-d H:i:s'),
      'source'                  => E::ts('NSF reissue of contribution #%1', [1 => $original['id']]),
      // Marker the recurring cron guard keys on; also carries the bank account
      // forward so the reissue can be redrafted against the same account.
      'ACH_Processor_Data.NSF_Reissued_From' => $original['id'],
      'ACH_Processor_Data.Bank_Account'      => $original['ACH_Processor_Data.Bank_Account'] ?? NULL,
    ];

    $new = Contribution::create(FALSE)
      ->setValues($values)
      ->execute()
      ->first();
    $newID = (int) $new['id'];

    // Remove the default line item + financial item that Contribution.create
    // generated for the total, so we can copy the originals cleanly.
    self::cleanUpInitialCreation($newID);

    $lineItemMap = self::copyLineItems((int) $original['id'], $newID);

    $feeLineItemId = NULL;
    if ($fee > 0) {
      $feeLineItemId = self::addFeeLineItem($newID, $original, $fee);
    }

    return ['id' => $newID, 'lineItemMap' => $lineItemMap, 'feeLineItemId' => $feeLineItemId];
  }

  /**
   * Copy the source contribution's line items (and their financial items) onto
   * the new contribution, returning an old-line-item-id => new-line-item-id map.
   *
   * @return array<int,int>
   *
   * @throws \CRM_Core_Exception
   */
  private static function copyLineItems(int $fromContributionID, int $toContributionID): array {
    $map = [];
    $lineItems = LineItem::get(FALSE)
      ->addWhere('contribution_id', '=', $fromContributionID)
      ->execute();

    foreach ($lineItems as $lineItem) {
      $financialItems = FinancialItem::get(FALSE)
        ->addWhere('entity_id', '=', $lineItem['id'])
        ->addWhere('entity_table', '=', 'civicrm_line_item')
        ->execute();

      $oldID = $lineItem['id'];
      unset($lineItem['id']);

      // A contribution-level line points its entity_id at the contribution; a
      // membership (or other entity) line keeps its own entity_id and only
      // re-homes the contribution_id.
      if (($lineItem['entity_table'] ?? '') === 'civicrm_contribution') {
        $lineItem['entity_id'] = $toContributionID;
      }
      $lineItem['contribution_id'] = $toContributionID;

      $newLineItem = LineItem::create(FALSE)
        ->setValues($lineItem)
        ->execute()
        ->first();
      $map[$oldID] = $newLineItem['id'];

      foreach ($financialItems as $financialItem) {
        unset($financialItem['id']);
        $financialItem['entity_id'] = $newLineItem['id'];
        $financialItem['created_date'] = date('Y-m-d H:i:s');
        $financialItem['transaction_date'] = date('Y-m-d H:i:s');
        FinancialItem::create(FALSE)
          ->setValues($financialItem)
          ->execute();
      }
    }

    return $map;
  }

  /**
   * Add the NSF-fee line item and its financial item to the new contribution.
   *
   * @throws \CRM_Core_Exception
   */
  private static function addFeeLineItem(int $contributionID, array $original, float $fee): int {
    $financialTypeID = (int) (\Civi::settings()->get('achoffline_nsf_fee_financial_type_id')
      ?: $original['financial_type_id']);
    $label = \Civi::settings()->get('achoffline_nsf_fee_label') ?: E::ts('NSF / Returned Payment Fee');

    $lineItem = LineItem::create(FALSE)
      ->addValue('entity_table', 'civicrm_contribution')
      ->addValue('entity_id', $contributionID)
      ->addValue('contribution_id', $contributionID)
      ->addValue('label', $label)
      ->addValue('qty', 1)
      ->addValue('unit_price', $fee)
      ->addValue('line_total', $fee)
      ->addValue('financial_type_id', $financialTypeID)
      ->execute()
      ->first();

    $incomeAccountID = CRM_Financial_BAO_FinancialAccount::getFinancialAccountForFinancialTypeByRelationship(
      $financialTypeID,
      'Income Account is'
    );

    FinancialItem::create(FALSE)
      ->addValue('transaction_date', date('Y-m-d H:i:s'))
      ->addValue('contact_id', $original['contact_id'])
      ->addValue('amount', $fee)
      ->addValue('currency', $original['currency'])
      ->addValue('financial_account_id', $incomeAccountID)
      ->addValue('status_id:name', 'Unpaid')
      ->addValue('entity_table', 'civicrm_line_item')
      ->addValue('entity_id', $lineItem['id'])
      ->addValue('description', $label)
      ->execute();

    return (int) $lineItem['id'];
  }

  /**
   * Delete the line items (and their financial items) auto-created for a
   * freshly-created contribution.
   *
   * @throws \CRM_Core_Exception
   */
  private static function cleanUpInitialCreation(int $contributionID): void {
    $lineItems = LineItem::get(FALSE)
      ->addSelect('id')
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute();
    foreach ($lineItems as $lineItem) {
      FinancialItem::delete(FALSE)
        ->addWhere('entity_id', '=', $lineItem['id'])
        ->addWhere('entity_table', '=', 'civicrm_line_item')
        ->execute();
    }
    LineItem::delete(FALSE)
      ->addWhere('contribution_id', '=', $contributionID)
      ->execute();
  }

  /**
   * Configured NSF fee as a float; 0 when unset or non-positive.
   */
  private static function getFeeAmount(): float {
    $raw = \Civi::settings()->get('achoffline_nsf_fee_amount');
    if ($raw === NULL || $raw === '') {
      return 0.0;
    }
    $amount = (float) \Civi::format()->machineMoney($raw);
    return $amount > 0 ? $amount : 0.0;
  }

}
