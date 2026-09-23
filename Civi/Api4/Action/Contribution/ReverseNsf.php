<?php

namespace Civi\Api4\Action\Contribution;

use Civi\Api4\Generic\AbstractBatchAction;
use Civi\Api4\Generic\Result;
use CRM_ACHOffline_BAO_NsfReversal as NsfReversal;

/**
 * Reverse ACH contributions returned by the bank (e.g. insufficient funds).
 *
 * For each selected contribution: cancel the original (core unwinds any recorded
 * money) and reissue it as an open Pending contribution carrying the original
 * line items plus, when configured, an NSF-fee line. Runs per row so one bad
 * record doesn't sink the batch, and is safe to re-run (already-reversed rows
 * are skipped).
 *
 * @method $this setReason(string $reason)
 */
class ReverseNsf extends AbstractBatchAction {

  public function _run(Result $result) {
    foreach ($this->getBatchRecords() as $record) {
      $id = (int) $record['id'];
      try {
        $result[] = NsfReversal::reverse($id) + ['error' => NULL];
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
          'error' => $e->getMessage(),
        ];
      }
    }
  }

}
