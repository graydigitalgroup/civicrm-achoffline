<?php

use Civi\Api4\Contribution;
use CRM_ACHOffline_BAO_NsfReversal as NsfReversal;
use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Reverse (NSF / returned payment) one or more ACH contributions.
 *
 * Opened from the SearchKit task (ids posted) or a contribution row link (id).
 */
class CRM_ACHOffline_Form_ReverseNsf extends CRM_Core_Form {

  /**
   * @var int[]
   */
  protected array $eligibleIds = [];

  protected int $firstReturnCount = 0;

  public function preProcess(): void {
    $this->setTitle(E::ts('Reverse (NSF / Returned Payment)'));

    $ids = CRM_Utils_Request::retrieve('ids', 'CommaSeparatedIntegers', $this)
      ?: CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $ids = array_filter(array_map('intval', explode(',', (string) $ids)));
    if (!$ids) {
      CRM_Core_Error::statusBounce(E::ts('No contributions selected.'));
    }

    $contributions = Contribution::get(FALSE)
      ->addSelect('id', 'contact_id.display_name', 'total_amount', 'currency', 'receive_date',
        'contribution_status_id:name', 'contribution_status_id:label', 'ACH_Processor_Data.Bank_Account',
        'ACH_Processor_Data.NSF_Reissued_From')
      ->addWhere('id', 'IN', $ids)
      ->execute();

    $rows = [];
    foreach ($contributions as $contribution) {
      $skipReason = NsfReversal::getIneligibleReason($contribution);
      $isRepeatReturn = NsfReversal::isRepeatReturn($contribution);
      if ($skipReason === NULL) {
        $this->eligibleIds[] = (int) $contribution['id'];
        if (!$isRepeatReturn) {
          $this->firstReturnCount++;
        }
      }
      $rows[] = [
        'id' => $contribution['id'],
        'contact' => $contribution['contact_id.display_name'],
        'amount' => CRM_Utils_Money::format($contribution['total_amount'], $contribution['currency']),
        'receive_date' => CRM_Utils_Date::customFormat($contribution['receive_date']),
        'status' => $contribution['contribution_status_id:label'],
        'skip_reason' => $skipReason,
        'repeat_return' => $skipReason === NULL && $isRepeatReturn,
      ];
    }
    $this->assign('rows', $rows);
    $this->assign('eligibleCount', count($this->eligibleIds));
    $this->assign('firstReturnCount', $this->firstReturnCount);
  }

  public function buildQuickForm(): void {
    if (!$this->eligibleIds) {
      $this->addButtons([['type' => 'cancel', 'name' => E::ts('Close')]]);
      return;
    }

    $this->add('text', 'fee_amount', E::ts('NSF Fee'), ['size' => 8], TRUE);
    $this->addRule('fee_amount', E::ts('Enter a valid amount.'), 'money');
    $this->add('select', 'reason', E::ts('Return Reason'),
      ['' => E::ts('- select -')] + NsfReversal::getReturnReasonOptions(), TRUE);

    $this->addFormRule([self::class, 'formRule']);
    $this->addButtons([
      ['type' => 'submit', 'name' => E::ts('Reverse'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  public function setDefaultValues(): array {
    if ($this->firstReturnCount === 0) {
      return ['fee_amount' => '0'];
    }
    $fee = \Civi::settings()->get('achoffline_nsf_fee_amount');
    return ['fee_amount' => ($fee === NULL || $fee === '') ? '0' : $fee];
  }

  public static function formRule(array $values): array|bool {
    $errors = [];
    $fee = CRM_Utils_Rule::cleanMoney($values['fee_amount'] ?? '');
    if (!is_numeric($fee) || (float) $fee < 0) {
      $errors['fee_amount'] = E::ts('NSF fee must be 0 or more.');
    }
    return $errors ?: TRUE;
  }

  public function postProcess(): void {
    $values = $this->exportValues();
    $results = civicrm_api4('Contribution', 'reverseNsf', [
      'checkPermissions' => FALSE,
      'where' => [['id', 'IN', $this->eligibleIds]],
      'feeAmount' => (float) CRM_Utils_Rule::cleanMoney($values['fee_amount']),
      'reason' => $values['reason'],
    ]);

    $reversed = $skipped = [];
    $errors = [];
    foreach ($results as $row) {
      if (!empty($row['error'])) {
        $errors[] = E::ts('#%1: %2', [1 => $row['original_id'], 2 => $row['error']]);
      }
      elseif (!empty($row['skipped'])) {
        $skipped[] = $row['original_id'];
      }
      else {
        $reversed[] = E::ts('#%1 reissued as #%2', [1 => $row['original_id'], 2 => $row['new_id']]);
      }
    }

    if ($reversed) {
      CRM_Core_Session::setStatus(implode('<br/>', $reversed), E::ts('Reversed %1 contribution(s)', [1 => count($reversed)]), 'success');
    }
    if ($skipped) {
      CRM_Core_Session::setStatus(E::ts('Skipped: #%1', [1 => implode(', #', $skipped)]), E::ts('Skipped'), 'info');
    }
    if ($errors) {
      CRM_Core_Session::setStatus(implode('<br/>', $errors), E::ts('Errors'), 'error');
    }
  }

}
