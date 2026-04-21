<?php

namespace Civi\ACHOffline\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Checkout\CheckoutSession;
use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Checkout\CheckoutOptionInterface;

abstract class ACHOfflineCheckoutOptionBase implements CheckoutOptionInterface, AfformCheckoutOptionInterface {

  /**
   * @var array
   */
  protected array $liveConnection;

  /**
   * @var array
   */
  protected array $testConnection;

  public function __construct(
    array $liveConnection,
    array $testConnection
  ) {
    $this->liveConnection = $liveConnection;
    $this->testConnection = $testConnection;
  }

  public function getLabel(): string {
    return $this->liveConnection['title'];
  }

  public function getFrontendLabel(): string {
    return $this->liveConnection['frontend_title'];
  }

  public function getPaymentProcessorId(): int {
    return $this->liveConnection['id'];
  }

  protected function getConnectionDetails(bool $testMode): array {
    return $testMode ? $this->testConnection : $this->liveConnection;
  }

  public function continueCheckout(CheckoutSession $session): void {
    // achoffline processors have doPayment integrations which happen in a
    // single shot
  }

  protected function getQuickformProcessor(bool $testMode = FALSE): \CRM_Core_Payment {
    return \Civi\Payment\System::singleton()->getById($this->getConnectionDetails($testMode)['id']);
  }

  public function validate(AfformValidateEvent $event): void {
    // no specific validation rules
  }

  public function getPaymentMethod(): string {
    return 'achoffline';
  }

  public static function resolveCurrentContactId(): ?int {
    $cid = \CRM_Utils_Request::retrieve('cid', 'Positive');
    if ($cid && \Civi\Api4\Contact::get(TRUE)
        ->addWhere('id', '=', $cid)
        ->selectRowCount()
        ->execute()
        ->count()) {
      return (int) $cid;
    }
    $loggedIn = \CRM_Core_Session::getLoggedInContactID();
    return $loggedIn ? (int) $loggedIn : NULL;
  }

}
