<?php

use Civi\Api4\BankAccount;
use Civi\Api4\Contribution;

/**
 * Placeholder clas for offline recurring payments.
 */
class CRM_Core_Payment_ACHOffline extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * Constructor.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   * @param array $paymentProcessor
   *   The array of information for the payment processor.
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   *
   */
  public function getPaymentFormFields() {
    return ['bank_name', 'bank_account_number', 'bank_identification_number'];
  }

  /**
   * Are back office payments supported.
   *
   * @return bool
   */
  protected function supportsBackOffice() {
    return TRUE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Does this payment processor support recurring contributions?
   *
   * @return bool
   */
  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * We can edit stripe recurring contributions.
   *
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
  }

  /*
   * This function  sends request and receives response from
   * the processor. It is the main function for processing on-server
   * credit card transactions
   */

  /**
   * This function collects all the information from a web/api form and invokes
   * the relevant payment processor specific functions to perform the transaction.
   *
   * @param array|\Civi\Payment\PropertyBag $paymentParams
   *
   * @param string $component
   *
   * @return array
   *   Result array (containing at least the key payment_status_id)
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$paymentParams, $component = 'contribute') {
    /** @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = $this->beginDoPayment($paymentParams);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }

    if (!empty($propertyBag->getCustomProperty('bank_account_number')) && !empty($propertyBag->getCustomProperty('bank_identification_number'))) {
      $contact_id = $propertyBag->getContactID();
      $bank_account_id = BankAccount::create(FALSE)
        ->addValue('contact_id', $contact_id)
        ->addValue('account_number', $propertyBag->getCustomProperty('bank_account_number'))
        ->addValue('routing_number', $propertyBag->getCustomProperty('bank_identification_number'))
        ->execute()
        ->first()['id'];


      // Need to set the ACH information for the API.
      Contribution::update(FALSE)
        ->addWhere('id', '=', $propertyBag->getContributionID())
        ->addValue('ACH_Processor_Data.Bank_Account', $bank_account_id)
        ->execute();
    }

    $query_args = [];

    CRM_Utils_Hook::alterPaymentProcessorParams($this, $propertyBag, $query_args);

    $result = $this->setStatusPaymentPending([]);
    return $result;
  }

  /**
   *
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    $userAlert = ts('You have updated the amount of this recurring contribution.');
    CRM_Core_Session::setStatus($userAlert, ts('Warning'), 'alert');
    return TRUE;
  }

  /**
   *
   */
  public function cancelSubscription(&$message = '', $params = []) {
    $userAlert = ts('You have cancelled this recurring contribution.');
    CRM_Core_Session::setStatus($userAlert, ts('Warning'), 'alert');
    return TRUE;
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string the error message if any
   */
  public function checkConfig() {
    return NULL;
  }

}
