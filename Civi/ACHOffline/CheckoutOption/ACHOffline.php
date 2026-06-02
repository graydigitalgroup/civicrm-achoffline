<?php

namespace Civi\ACHOffline\CheckoutOption;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Checkout\CheckoutOptionUtils;
use Civi\Checkout\CheckoutSession;
use CRM_ACHOffline_ExtensionUtil as E;
use CRM_Core_Session;
use CRM_Utils_Request;

class ACHOffline extends ACHOfflineCheckoutOptionBase {

  public function getAfformSettings(bool $testMode): array {
    // FIXME: billing fields should really come from other entities on the form
    $fields = CheckoutOptionUtils::mapQuickformFieldMetadata($this->getQuickformProcessor()->getPaymentFormFieldsMetadata());
    return [
      'fields' => $fields,
    ];
  }

  public function getAfformModule(): ?string {
    return NULL;
  }

}
