<?php

namespace Civi\ACHOffline\CheckoutOption;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Checkout\CheckoutOptionUtils;
use Civi\Checkout\CheckoutSession;
use CRM_ACHOffline_ExtensionUtil as E;
use CRM_Core_Session;
use CRM_Utils_Request;

class PaymentToken extends ACHOfflineCheckoutOptionBase {


  public function getLabel(): string {
    return E::ts('Use Existing Bank Account');
  }

  public function getFrontendLabel(): string {
    return E::ts('Use Existing Bank Account');
  }

  public function getAfformSettings(bool $testMode): array {
    $contactId = self::resolveCurrentContactId();
    if (!$contactId) {
      return [];
    }

    // resolveContactId() already did the permission check for the ?cid=
    // path and falls back to the authenticated user's own contact. From
    // here, we trust $contactId as the authoritative target for this session.
    // checkPermissions=FALSE is intentional: we're not letting the client
    // influence the query — we've done the auth work above.
    $tokens = \Civi\Api4\PaymentToken::get(FALSE)
      ->addSelect('id', 'masked_account_number')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('payment_processor_id', '=', $this->getPaymentProcessorId())
      ->execute()
      ->getArrayCopy();

    // If they have no tokens, suppress the option entirely — no point
    // showing "Use Existing Bank Account" when there are none.
    if (!$tokens) {
      return [];
    }

    $accountOptions = array_map(fn($t) => [
      'id' => (int) $t['id'],
      'label' => $t['masked_account_number'],
    ], $tokens);

    return [
      'template' => '~/afAchoffline/achoffline-payment-token-checkout.html',
      'payment_processor_id' => $this->getPaymentProcessorId(),
      'account_options' => $accountOptions,
    ];
  }

  public function getAfformModule(): ?string {
    return 'afAchoffline';
  }

  public function startCheckout(CheckoutSession $session): void {
    $params = $session->getCheckoutParams();

    // FIXME: copy doPayment implementation and switch to Api4 style keys
    $whatOldStyleKeysDoesDoPaymentNeed = ['amount', 'contributionID', 'contactID', 'currency', 'invoiceID'];
    $params += CheckoutOptionUtils::fetchRequiredParams($session->getContributionId(), [], $whatOldStyleKeysDoesDoPaymentNeed, $params);

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
    $params['billingFirstName'] = $contact['first_name'];
    $params['billingLastName'] = $contact['last_name'];
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

}