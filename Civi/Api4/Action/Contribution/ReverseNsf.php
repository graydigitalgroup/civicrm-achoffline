<?php

namespace Civi\Api4\Action\Contribution;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use CRM_ACHOffline_BAO_NsfReversal as NsfReversal;
use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Reverse ACH contributions returned by the bank (e.g. insufficient funds).
 *
 * For each selected contribution: cancel the original (core unwinds any recorded
 * money) and reissue it as an open Pending contribution carrying the original
 * line items plus, when configured, an NSF-fee line. Runs per row so one bad
 * record doesn't sink the batch, and is safe to re-run (already-reversed rows
 * are skipped).
 *
 * @method $this setFeeAmount(float|null $feeAmount)
 * @method float|null getFeeAmount()
 * @method $this setReason(string|null $reason)
 * @method string|null getReason()
 */
class ReverseNsf extends AbstractBatchAction {

  /**
   * NSF fee to add to each reissue.
   *
   * Leave unset to use the configured NSF fee setting; 0 adds no fee line.
   * Ignored for a contribution that is itself a reissue (repeat return): it
   * keeps the original fee line and gets no new fee.
   *
   * @var float|null
   */
  protected $feeAmount;

  /**
   * Bank return reason (e.g. an R-code such as R01).
   *
   * Recorded on the cancelled original and passed to event consumers.
   *
   * @var string|null
   */
  protected $reason;

  public function _run(Result $result) {
    $feeAmount = ($this->feeAmount === NULL || $this->feeAmount === '') ? NULL : (float) $this->feeAmount;
    if ($feeAmount !== NULL && $feeAmount < 0) {
      throw new \CRM_Core_Exception(E::ts('NSF fee amount cannot be negative.'));
    }

    foreach ($this->getBatchRecords() as $record) {
      $id = (int) $record['id'];
      try {
        $result[] = NsfReversal::reverse($id, $feeAmount, $this->reason) + ['error' => NULL];
      }
      catch (\Throwable $e) {
        \Civi::log()->error('ACHOffline: NSF reversal failed', [
          'contribution_id' => $id,
          'error' => $e->getMessage(),
        ]);
        $result[] = [
          'original_id' => $id,
          'new_id' => NULL,
          'was_paid' => NULL,
          'skipped' => FALSE,
          'skip_reason' => NULL,
          'fee_amount' => NULL,
          'error' => $e->getMessage(),
        ];
      }
    }
  }

}
