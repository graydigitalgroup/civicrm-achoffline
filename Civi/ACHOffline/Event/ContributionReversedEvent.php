<?php

namespace Civi\ACHOffline\Event;

use Civi\Core\Event\GenericHookEvent;

/**
 * Fired after an ACH contribution has been reversed (e.g. bank return / NSF).
 *
 * The reversal itself — cancelling the original contribution and letting core
 * unwind its financial items — is already done by the time this fires. This
 * event exists so a consumer can reverse its OWN records that hang off the
 * contribution and that core knows nothing about (soft credits by line item,
 * bill-to bridges, etc.).
 *
 * wasPaid tells a consumer whether real money had been recorded against the
 * original: if TRUE it should write an offsetting (negative) reversal so the
 * audit trail is preserved; if FALSE the original was only ever Pending, so the
 * related records can simply be removed.
 *
 * Event name: civi.achoffline.contributionReversed (see self::NAME)
 */
class ContributionReversedEvent extends GenericHookEvent {

  public const NAME = 'civi.achoffline.contributionReversed';

  private int $contributionID;

  private bool $wasPaid;

  private string $reason;

  public function __construct(int $contributionID, bool $wasPaid, string $reason) {
    $this->contributionID = $contributionID;
    $this->wasPaid = $wasPaid;
    $this->reason = $reason;
  }

  /**
   * The original contribution that was reversed.
   */
  public function getContributionID(): int {
    return $this->contributionID;
  }

  /**
   * TRUE when money had actually been recorded against the original
   * (it was Completed or Partially paid), FALSE when it was only Pending.
   */
  public function wasPaid(): bool {
    return $this->wasPaid;
  }

  /**
   * Short machine reason for the reversal, e.g. 'nsf'.
   */
  public function getReason(): string {
    return $this->reason;
  }

}
