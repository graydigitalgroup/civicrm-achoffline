<?php

use Civi\Api4\BankAccount;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Payment\Exception\PaymentProcessorException;

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

  /**
   * Get name for the payment information type.
   * @return string
   */
  public function getPaymentTypeName() {
    return 'ach';
  }

  /**
   * Get label for the payment information type.
   * @return string
   */
  public function getPaymentTypeLabel() {
    return ts('ACH');
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
      $contact = Contact::get(FALSE)
        ->addWhere('id', '=', $contact_id)
        ->execute()
        ->first();
      $account_name = $propertyBag->getCustomProperty('bank_name') ?? $contact['display_name'] . ' Bank Account';
      $bank_account_id = BankAccount::create(FALSE)
        ->addValue('contact_id', $contact_id)
        ->addValue('name', $account_name)
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

    // Need to add options on contribution form to allow for selecting an
    // existing Bank Account for the contact the contribution is being created for.

    $query_args = [
      // C - Direct Payment using credit card.
      'TENDER' => 'C',
      // A - Authorization, S - Sale.
      'TRXTYPE' => 'S',
      'ACCT' => '',
      'ACCTTYPE' => urlencode($paymentParams['credit_card_type']),
      // @todo Do we need to machinemoney format? ie. 1024.00 or is 1024 ok for API?
      'AMT' => \Civi::format()->machineMoney($propertyBag->getAmount()),
      'CURRENCY' => urlencode($propertyBag->getCurrency()),
      'FIRSTNAME' => $propertyBag->has('firstName') ? $propertyBag->getFirstName() : '',
      'LASTNAME' => $propertyBag->has('lastName') ? $propertyBag->getLastName() : '',
      'STREET' => $propertyBag->getBillingStreetAddress(),
      'CITY' => urlencode($propertyBag->getBillingCity()),
      'STATE' => urlencode($propertyBag->getBillingStateProvince()),
      'ZIP' => urlencode($propertyBag->getBillingPostalCode()),
      'COUNTRY' => urlencode($propertyBag->getBillingCountry()),
      'EMAIL' => $propertyBag->has('email') ? $propertyBag->getEmail() : '',
      'CUSTIP' => urlencode($paymentParams['ip_address']),
      'COMMENT1' => urlencode($paymentParams['contributionType_accounting_code']),
      'COMMENT2' => $this->_paymentProcessor['is_test'] ? 'test' : 'live',
      'INVNUM' => urlencode($propertyBag->getInvoiceID()),
      'ORDERDESC' => urlencode($propertyBag->getDescription()),
      'VERBOSITY' => 'MEDIUM',
      'BILLTOCOUNTRY' => urlencode($propertyBag->getBillingCountry()),
    ];

    // Check for selected bank account.
    if ($propertyBag->has('bankAccount') && !empty($propertyBag->getCustomProperty('bankAccount'))) {
      $query_args['ACCT'] = $propertyBag->getCustomProperty('bankAccount');
    }

    if ($paymentParams['installments'] == 1) {
      $paymentParams['is_recur'] = FALSE;
    }

    if ($paymentParams['is_recur'] == TRUE) {

      $query_args['TRXTYPE'] = 'R';
      $query_args['OPTIONALTRX'] = 'S';
      // @todo Do we need to machinemoney format? ie. 1024.00 or is 1024 ok for API?
      $query_args['OPTIONALTRXAMT'] = \Civi::format()->machineMoney($propertyBag->getAmount());
      // Amount of the initial Transaction. Required.
      $query_args['ACTION'] = 'A';
      // A for add recurring (M-modify,C-cancel,R-reactivate,I-inquiry,P-payment.
      $query_args['PROFILENAME'] = urlencode('RegularContribution');
      // A for add recurring (M-modify,C-cancel,R-reactivate,I-inquiry,P-payment.
      if ($paymentParams['installments'] > 0) {
        $query_args['TERM'] = $propertyBag->getRecurInstallments() - 1;
        // ie. in addition to the one happening with this transaction.
      }
      if ($propertyBag->getRecurFrequencyUnit() === 'day') {
        throw new PaymentProcessorException('Current implementation does not support recurring with frequency "day"');
      }
      $interval = $propertyBag->getRecurFrequencyInterval() . " " . $propertyBag->getRecurFrequencyUnit();
      switch ($interval) {
        case '1 week':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 7, date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (7 * $query_args['TERM']), date("Y"));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "WEEK";
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(1);
          break;

        case '2 weeks':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 14, date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (14 * $query_args['TERM']), date("Y "));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "BIWK";
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(2);
          break;

        case '4 weeks':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 28, date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (28 * $query_args['TERM']), date("Y"));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "FRWK";
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(4);
          break;

        case '1 month':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 1, date("d"), date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m") + (1 * $query_args['TERM']), date("d"), date("Y"));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "MONT";
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(1);
          break;

        case '3 months':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 3, date("d"), date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m") + (3 * $query_args['TERM']), date("d"), date("Y"));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "QTER";
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(3);
          break;

        case '6 months':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 6, date("d"), date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m") + (6 * $query_args['TERM']), date("d"), date("Y"));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "SMYR";
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(6);
          break;

        case '1 year':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d"), date("Y") + 1);
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d"), date("Y") + (1 * $query_args['TEM']));
          $query_args['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $query_args['PAYPERIOD'] = "YEAR";
          $propertyBag->setRecurFrequencyUnit('year');
          $propertyBag->setRecurFrequencyInterval(1);
          break;
      }
    }

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
