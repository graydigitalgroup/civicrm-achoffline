<?php

namespace Civi\ACHOffline\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Checkout\CheckoutOptionUtils;
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
  /**
   * @inheritdoc
   * @throws \CRM_Core_Exception
   */
  public function startCheckout(CheckoutSession $session): void {
    $params = $session->getCheckoutParams();

    // FIXME: copy doPayment implementation and switch to Api4 style keys
    $whatOldStyleKeysDoesDoPaymentNeed = ['amount', 'contributionID', 'contactID', 'currency', 'invoiceID'];
    $params += CheckoutOptionUtils::fetchRequiredParams($session->getContributionId(), [], $whatOldStyleKeysDoesDoPaymentNeed, $params);

    // The recurring schedule is saved as a ContributionRecur through the
    // Civi\Contribute\Service\CreateContribution class. The form's recurrence
    // fields never reach the CheckoutOption, so derive the doPayment recur
    // keys from the saved order here — otherwise doPayment treats every
    // submission as a one-off and never links the token to the recur or
    // finalises its schedule.
    $recur = Contribution::get(FALSE)
      ->addWhere('id', '=', $session->getContributionId())
      ->addSelect(
        'contribution_recur_id',
        'contribution_recur_id.frequency_unit',
        'contribution_recur_id.frequency_interval',
        'contribution_recur_id.installments',
        'contribution_recur_id.payment_processor_id'
      )
      ->execute()
      ->first();

    if (!empty($recur['contribution_recur_id'])) {
      $params['is_recur'] = TRUE;
      $params['contributionRecurID'] = (int) $recur['contribution_recur_id'];
      $params['frequency_unit'] = $recur['contribution_recur_id.frequency_unit'];
      $params['frequency_interval'] = (int) $recur['contribution_recur_id.frequency_interval'];
      // 0 / NULL installments means open-ended; doPayment treats 1 as a single payment.
      $params['installments'] = (int) ($recur['contribution_recur_id.installments'] ?? 0);

      // Link the recur to this processor now that checkout has chosen one.
      // The QuickForm sets ContributionRecur.payment_processor_id at recur
      // creation (it has the processor on the form), but in the afform flow the
      // recur is created by Order.create BEFORE a checkout option is picked, so
      // it is created without a processor. Without this back-fill the series has
      // no payment_processor_id and anything that resolves the processor from it
      // - core's UpdateSubscription / cancellation, and the template-edit
      // amount-amend - can't find one.
      if (empty($recur['contribution_recur_id.payment_processor_id'])) {
        \Civi\Api4\ContributionRecur::update(FALSE)
          ->addWhere('id', '=', (int) $recur['contribution_recur_id'])
          ->addValue('payment_processor_id', $this->getPaymentProcessorId($session->isTestMode()))
          ->execute();
      }
    }

    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $params['contact_id'])
      ->execute()
      ->first();
    $contactAddress = Address::get(FALSE)
      ->addWhere('contact_id', '=', $params['contact_id'])
      ->addWhere('is_billing', '=', TRUE)
      ->execute()
      ->first();
    if (!$contactAddress) {
      $contactAddress = Address::get(FALSE)
        ->addWhere('contact_id', '=', $params['contact_id'])
        ->addWhere('is_primary', '=', TRUE)
        ->execute()
        ->first();
    }
    $params['firstName'] = $contact['first_name'];
    $params['lastName'] = $contact['last_name'];
    $params['billingStreetAddress'] = $contactAddress['street_address'] ?? '';
    $params['billingCity'] = $contactAddress['city'] ?? '';
    $params['billingStateProvince'] = $contactAddress['state_province_id'] ?? '';
    $params['billingPostalCode'] = $contactAddress['postal_code'] ?? '';
    $params['billingCountry'] = $contactAddress['country_id'] ?? '';

    $payment = $this->getQuickformProcessor($session->isTestMode())->doPayment($params);

    // Payment should always return pending since it is handled offline
    if ($payment['payment_status'] == 'Pending') {
      $session->pending();
      // ensure clientside messages are shown
      $session->setResponseItem('message', $session->getStatusMessage());
      return;
    }
  }

  /**
   * @inheritdoc
   */
  public function continueCheckout(CheckoutSession $session): void {
    // everything happens in the first submit, so nothing more to do here
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

}
