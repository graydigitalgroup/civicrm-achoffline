<?php

use Civi\Api4\BankAccount;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Placeholder clas for offline recurring payments.
 */
class CRM_Core_Payment_ACHOffline extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  protected $cid;

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
   * Get array of fields that should be displayed on the payment form for ACH/EFT (badly named as debit cards).
   *
   * @return array
   */

  protected function getDirectDebitFormFields(): array {
    $fields = parent::getDirectDebitFormFields();
    if ($fields[0] == 'account_holder') {
      array_shift($fields);
      $fields[] = 'account_holder';
    }
    $fields[] = 'bank_account_type';
    return $fields;
  }

  /**
   *
   */
  public function getPaymentFormFields() {
    return ['bank_name', 'account_holder', 'bank_account_number', 'bank_identification_number', 'bank_account_type'];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata(): array {
    $metadata = parent::getPaymentFormFieldsMetadata();
    $metadata['bank_account_type'] = [
      'htmlType' => 'Select',
      'name' => 'bank_account_type',
      'title' => ts('Account type'),
      'is_required' => TRUE,
      'attributes' => ['CHECKING' => 'Checking', 'SAVING' => 'Savings'],
    ];
    // Modify the label of Account Number
    $metadata['bank_account_number']['title'] = ts('Account No.');
    // Modify the label of Bank Identification Number
    $metadata['bank_identification_number']['title'] = ts('Routing No.');
    return $metadata;
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


  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Get any saved accounts for the current contact
    // and put them on the payment form with a select element so you can
    // select "none" to enter new details or a saved account to use an existing one.
    CRM_Core_Region::instance('billing-block-pre')->add([
      'template' => E::path('templates/CRM/Core/BillingBlockACHOffline.tpl'),
      'weight' => -1,
    ]);

    $cid = \CRM_Utils_Request::retrieve('cid', 'Positive', NULL);

    if (empty($cid)) {
      // Can't save account details if no contact ID.
      return FALSE;
    }

    $accounts = BankAccount::get(FALSE)
      ->setSelect(['id', 'name', 'payment_token'])
      ->addWhere('contact_id', '=', $cid)
      ->addChain('payment_token', PaymentToken::get(FALSE)->addSelect('id')->addWhere('token', '=', '$payment_token'))
      ->execute();
    foreach ($accounts as $account) {
      $bankAccounts[$account['payment_token'][0]['id']] = $account['name'];
    }
    if (!empty($bankAccounts)) {
      $form->add('select', 'payment_token', E::ts('Use Existing Bank Account'),
       ['0' => E::ts('- none -')] + $bankAccounts, FALSE);
    }
    return FALSE;
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

    // Need to add options on contribution form to allow for selecting an
    // existing Bank Account for the contact the contribution is being created for.

    $query_args = [
      // C - Direct Payment using credit card.
      'TENDER' => 'C',
      // A - Authorization, S - Sale.
      'TRXTYPE' => 'S',
      'ACCT' => '',
      'ACCTTYPE' => 'ACH',
      'TOKEN' => '',
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
      $query_args['TOKEN'] = $propertyBag->getCustomProperty('bankAccount');
      $payment_token = PaymentToken::get(FALSE)
        ->addSelect('masked_account_number')
        ->addWhere('id', '=', $query_args['TOKEN'])
        ->execute()
        ->first();
      $query_args['ACCT'] = $payment_token['masked_account_number'];
    } else {
      if (!empty($propertyBag->getCustomProperty('bank_account_number')) && !empty($propertyBag->getCustomProperty('bank_identification_number'))) {
        $contact_id = $propertyBag->getContactID();
        $contact = Contact::get(FALSE)
          ->addWhere('id', '=', $contact_id)
          ->execute()
          ->first();
        $account_name = $propertyBag->getCustomProperty('bank_name') ?? $contact['display_name'] . ' Bank Account';
        $payment_token = \CRM_Contribute_BAO_Contribution::generateInvoiceID();
        BankAccount::create(FALSE)
          ->addValue('contact_id', $contact_id)
          ->addValue('name', $account_name)
          ->addValue('account_number', \Civi::service('crypto.token')->encrypt($propertyBag->getCustomProperty('bank_account_number'), 'CRED'))
          ->addValue('routing_number', \Civi::service('crypto.token')->encrypt($propertyBag->getCustomProperty('bank_identification_number'), 'CRED'))
          ->addValue('account_holder', $propertyBag->getCustomProperty('account_holder'))
          ->addValue('account_type', $propertyBag->getCustomProperty('bank_account_type'))
          ->addValue('payment_token', $payment_token)
          ->execute()
          ->first()['id'];

        // Set
        $payment_token = PaymentToken::create(FALSE)
          ->addValue('contact_id', $propertyBag->getContactID())
          ->addValue('payment_processor_id', $this->getPaymentProcessor()['id'])
          ->addValue('token', $payment_token)
          ->addValue('masked_account_number', CRM_Utils_System::mungeCreditCard($propertyBag->getCustomProperty('bank_account_number')))
          ->execute();
        $query_args['TOKEN'] = $payment_token[0]['id'];
        $query_args['ACCT'] = $payment_token[0]['masked_account_number'];
      } else {
        // No payment information is present.
        throw new PaymentProcessorException('Invalid ACH payment information. Please re-enter.', 1000);
      }
    }

    if ($paymentParams['installments'] == 1) {
      $paymentParams['is_recur'] = FALSE;
    }

    if ($paymentParams['is_recur'] == TRUE && !empty($query_args['TOKEN'])) {
      // Store the payment token ID on the recur.
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $propertyBag->getContributionRecurID())
        ->addValue('payment_token_id', $query_args['TOKEN'])
        ->execute();

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
