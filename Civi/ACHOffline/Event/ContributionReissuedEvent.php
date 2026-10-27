<?php

namespace Civi\ACHOffline\Event;

use Civi\Core\Event\GenericHookEvent;

/**
 * Fired after a reversed ACH contribution has been reissued as a new, open
 * (Pending) contribution that carries the original line items plus any NSF fee.
 *
 * A consumer uses this to replicate its own per-line records onto the new
 * contribution. Because those records are keyed by line item and the copies get
 * fresh IDs, the lineItemMap (old line item id => new line item id) is the piece
 * that lets a consumer re-point them accurately.
 *
 * feeLineItemID identifies the appended NSF-fee line (or NULL when no fee was
 * configured) so a consumer can tell it apart from the copied lines — a fee is
 * a real charge to the member, not something to soft-credit back to a third
 * party, so consumers should normally skip it.
 *
 * Event name: civi.achoffline.contributionReissued (see self::NAME)
 */
class ContributionReissuedEvent extends GenericHookEvent {

  public const NAME = 'civi.achoffline.contributionReissued';

  private int $originalContributionID;

  private int $newContributionID;

  private array $lineItemMap;

  private ?int $feeLineItemID;

  private float $feeAmount;

  private ?string $returnReason;

  public function __construct(
    int $originalContributionID,
    int $newContributionID,
    array $lineItemMap,
    ?int $feeLineItemID,
    float $feeAmount = 0.0,
    ?string $returnReason = NULL
  ) {
    $this->originalContributionID = $originalContributionID;
    $this->newContributionID = $newContributionID;
    $this->lineItemMap = $lineItemMap;
    $this->feeLineItemID = $feeLineItemID;
    $this->feeAmount = $feeAmount;
    $this->returnReason = $returnReason;
  }

  /**
   * The NSF fee added to the reissue (0 when no fee line was added).
   */
  public function getFeeAmount(): float {
    return $this->feeAmount;
  }

  /**
   * The bank return reason entered by staff (e.g. an R-code such as R01), or
   * NULL when none was given.
   */
  public function getReturnReason(): ?string {
    return $this->returnReason;
  }

  /**
   * The reversed contribution the new one was cloned from.
   */
  public function getOriginalContributionID(): int {
    return $this->originalContributionID;
  }

  /**
   * The new open (Pending) contribution.
   */
  public function getNewContributionID(): int {
    return $this->newContributionID;
  }

  /**
   * Map of old line item id => new line item id for the copied lines.
   * Does not include the appended NSF-fee line (see getFeeLineItemID()).
   */
  public function getLineItemMap(): array {
    return $this->lineItemMap;
  }

  /**
   * The appended NSF-fee line item id, or NULL when no fee was configured.
   */
  public function getFeeLineItemID(): ?int {
    return $this->feeLineItemID;
  }

}
