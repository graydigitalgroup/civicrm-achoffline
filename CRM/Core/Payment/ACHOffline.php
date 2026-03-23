<?php

use Civi\Api4\BankAccount;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Class for offline recurring payments.
 */
class CRM_Core_Payment_ACHOffline extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * @var int
   */
  protected int $cid;

  /**
   * Constructor.
   *
   * @param string $mode
   *   The mode of operation: live or test.
   * @param array $paymentProcessor
   *   The array of information for the payment processor.
   */
  public function __construct(string $mode, array &$paymentProcessor) {
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
   * Return the payment form fields.
   *
   * @return array
   */
  public function getPaymentFormFields(): array {
    return ['bank_name', 'account_holder', 'bank_account_number', 'bank_identification_number', 'bank_account_type'];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form.
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata(): array {
    $metadata = parent::getPaymentFormFieldsMetadata();
    $metadata['bank_account_type'] = [
      'htmlType'    => 'Select',
      'name'        => 'bank_account_type',
      'title'       => ts('Account type'),
      'is_required' => TRUE,
      'attributes'  => ['CHECKING' => 'Checking', 'SAVING' => 'Savings'],
    ];
    // Modify the label of Account Number.
    $metadata['bank_account_number']['title'] = ts('Account No.');
    // Modify the label of Bank Identification Number.
    $metadata['bank_identification_number']['title'] = ts('Routing No.');
    return $metadata;
  }

  /**
   * Are back office payments supported.
   *
   * @return bool
   */
  protected function supportsBackOffice(): bool {
    return TRUE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund(): bool {
    return TRUE;
  }

  /**
   * Does this payment processor support recurring contributions?
   *
   * @return bool
   */
  public function supportsRecurring(): bool {
    return TRUE;
  }

  /**
   * We can edit recurring contributions.
   *
   * @return bool
   */
  public function supportsEditRecurringContribution(): bool {
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
  protected function supportsCancelRecurring(): bool {
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
  protected function supportsCancelRecurringNotifyOptional(): bool {
    return TRUE;
  }

  /**
   * Get name for the payment information type.
   *
   * @return string
   */
  public function getPaymentTypeName(): string {
    return 'ach';
  }

  /**
   * Get label for the payment information type.
   *
   * @return string
   */
  public function getPaymentTypeLabel(): string {
    return ts('ACH');
  }

  /**
   * Set default values when loading the (payment) form.
   *
   * @param \CRM_Core_Form $form
   *
   * @throws \CRM_Core_Exception
   */
  public function buildForm(&$form): false {
    // Get any saved accounts for the current contact and put them on the
    // payment form with a select element so you can select "none" to enter
    // new details or a saved account to use an existing one.
    CRM_Core_Region::instance('billing-block-pre')->add([
      'template' => E::path('templates/CRM/Core/BillingBlockACHOffline.tpl'),
      'weight'   => -1,
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

  /**
   * This function collects all the information from a web/api form and invokes
   * the relevant payment processor specific functions to perform the
   * transaction.
   *
   * @param array|\Civi\Payment\PropertyBag $paymentParams
   * @param string $component
   *
   * @return array
   *   Result array (containing at least the key payment_status_id)
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \CRM_Core_Exception
   * @throws \DateMalformedStringException
   * @throws \DateMalformedIntervalStringException
   */
  public function doPayment(&$paymentParams, $component = 'contribute'): array {
    $propertyBag = $this->beginDoPayment($paymentParams);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }

    $paymentTokenID = $this->resolvePaymentToken($propertyBag);

    // Link token to the Contribution (single or first installment).
    Contribution::update(FALSE)
      ->addValue('ACH_Processor_Data.Bank_Account', $paymentTokenID)
      ->addWhere('id', '=', $propertyBag->getContributionID())
      ->execute();

    $isSinglePayment = ($paymentParams['installments'] ?? 0) == 1;

    $query_args = [
      'payment_type'       => 'ACH',
      'amount'             => \Civi::format()->machineMoney($propertyBag->getAmount()),
      'currency'           => $propertyBag->getCurrency(),
      'first_name'         => $propertyBag->has('firstName') ? $propertyBag->getFirstName() : '',
      'last_name'          => $propertyBag->has('lastName') ? $propertyBag->getLastName() : '',
      'street_address'     => $propertyBag->getBillingStreetAddress(),
      'city'               => $propertyBag->getBillingCity(),
      'state'              => $propertyBag->getBillingStateProvince(),
      'postal_code'        => $propertyBag->getBillingPostalCode(),
      'country'            => $propertyBag->getBillingCountry(),
      'email'              => $propertyBag->has('email') ? $propertyBag->getEmail() : '',
      'ip_address'         => $paymentParams['ip_address'] ?? '',
      'accounting_code'    => $paymentParams['contributionType_accounting_code'] ?? '',
      'invoice_id'         => $propertyBag->getInvoiceID(),
      'description'        => $propertyBag->getDescription(),
      'is_test'            => $this->_paymentProcessor['is_test'],
      'payment_token_id'   => $paymentTokenID,
      'masked_account'     => NULL,
      // Recurring fields — populated below if applicable.
      'is_recur'           => FALSE,
      'frequency_unit'     => NULL,
      'frequency_interval' => NULL,
      'pay_period'         => NULL,
      'installments'       => NULL,
    ];

    if (!$isSinglePayment && !empty($paymentParams['is_recur'])) {
      $query_args['is_recur'] = TRUE;

      $interval = $propertyBag->getRecurFrequencyInterval() . ' ' . $propertyBag->getRecurFrequencyUnit();

      // Normalize frequency and set canonical unit/interval on the propertyBag
      // before the hook fires so implementors can modify them if needed.
      switch ($interval) {
        case '1 week':
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(1);
          $query_args['pay_period'] = 'WEEKLY';
          break;

        case '2 weeks':
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(2);
          $query_args['pay_period'] = 'BIWEEKLY';
          break;

        case '4 weeks':
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(4);
          $query_args['pay_period'] = 'FOUR_WEEKLY';
          break;

        case '1 month':
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(1);
          $query_args['pay_period'] = 'MONTHLY';
          break;

        case '3 months':
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(3);
          $query_args['pay_period'] = 'QUARTERLY';
          break;

        case '6 months':
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(6);
          $query_args['pay_period'] = 'SEMIANNUAL';
          break;

        case '1 year':
          $propertyBag->setRecurFrequencyUnit('year');
          $propertyBag->setRecurFrequencyInterval(1);
          $query_args['pay_period'] = 'ANNUAL';
          break;

        default:
          throw new PaymentProcessorException(
            "Unsupported recurring frequency: {$interval}"
          );
      }

      // Reflect the (now normalized) frequency into query_args so the hook
      // receives the canonical values. If the hook modifies propertyBag
      // frequency, those changes will be picked up after the hook fires.
      $query_args['frequency_unit']     = $propertyBag->getRecurFrequencyUnit();
      $query_args['frequency_interval'] = $propertyBag->getRecurFrequencyInterval();
      // Include installments in query_args so hooks can observe or modify it.
      $query_args['installments']       = (int) ($paymentParams['installments'] ?? 0);

      // Store token on recur so doRecurPayment() can find it later.
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $propertyBag->getContributionRecurID())
        ->addValue('payment_token_id', $paymentTokenID)
        ->execute();
    }

    CRM_Utils_Hook::alterPaymentProcessorParams($this, $propertyBag, $query_args);

    // All date/installment writes happen after the hook so any modifications
    // to frequency, interval, or installments made by a hook are respected.
    if (!$isSinglePayment && !empty($paymentParams['is_recur'])) {
      $recurID      = $propertyBag->getContributionRecurID();
      $freqInterval = $propertyBag->getRecurFrequencyInterval();
      $freqUnit     = $propertyBag->getRecurFrequencyUnit();
      // Use post-hook installments in case a hook modified the value.
      $installments = (int) ($query_args['installments'] ?? 0);

      $nextDate = $this->advanceNextScheduledDate($recurID, $freqInterval, $freqUnit);

      if ($installments > 0) {
        // The first payment is happening now, so nextDate is installment #2.
        // end_date is the date of the final installment.
        $endDate   = new DateTime($nextDate);
        $remaining = $installments - 2;
        if ($remaining > 0) {
          $map = $this->getFrequencyIntervalMap($freqInterval);
          $endDate->add(new DateInterval(
            str_replace(
              (string) $freqInterval,
              (string) ($freqInterval * $remaining),
              $map[$freqUnit]
            )
          ));
        }

        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recurID)
          ->addValue('installments', $installments)
          ->addValue('end_date', $endDate->format('Y-m-d'))
          ->execute();
      }
      else {
        // Open-ended — persist installments as 0 and leave end_date NULL.
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', $recurID)
          ->addValue('installments', 0)
          ->execute();
      }
    }

    return $this->setStatusPaymentPending([]);
  }

  /**
   * Process a recurring payment installment.
   *
   * Called by CiviCRM's process_recurring scheduled job for each due recur.
   * Creates a Pending contribution and stamps the PaymentToken ID so the
   * NACHA file builder can find it.
   *
   * @param array $params
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \DateMalformedIntervalStringException
   * @throws \DateMalformedStringException
   */
  public function doRecurPayment(array $params = []): bool {
    $recurID = $params['contributionRecurID']
      ?? $params['contribution_recur_id']
      ?? NULL;

    if (empty($recurID)) {
      throw new PaymentProcessorException(
        'doRecurPayment requires a contribution_recur_id.'
      );
    }

    $recur = ContributionRecur::get(FALSE)
      ->addSelect(
        'id', 'payment_token_id', 'contact_id', 'amount',
        'frequency_unit', 'frequency_interval', 'installments',
        'next_sched_contribution_date'
      )
      ->addWhere('id', '=', $recurID)
      ->execute()
      ->first();

    if (empty($recur['payment_token_id'])) {
      throw new PaymentProcessorException(
        "ContributionRecur {$recurID} has no payment_token_id — cannot process ACH installment."
      );
    }

    // Guard: if installments are capped and we're already at the limit,
    // close the recur out without creating another contribution. This
    // handles the case where the scheduled job fires after we should have
    // already stopped (e.g. status wasn't updated cleanly on the last run).
    if ($this->isInstallmentLimitReached($recur)) {
      $this->completeRecur($recur['id']);
      return TRUE;
    }

    $pendingStatusID = CRM_Core_PseudoConstant::getKey(
      'CRM_Contribute_BAO_Contribution',
      'contribution_status_id',
      'Pending'
    );

    // @todo remove dependency to API3
    $result = civicrm_api3('Contribution', 'repeattransaction', [
      'contribution_recur_id'  => $recurID,
      'contribution_status_id' => $pendingStatusID,
      'is_email_receipt'       => FALSE,
    ]);

    if (!empty($result['id'])) {
      Contribution::update(FALSE)
        ->addValue('ACH_Processor_Data.Bank_Account', $recur['payment_token_id'])
        ->addWhere('id', '=', $result['id'])
        ->execute();
    }

    // Post-create check: if this contribution filled the last slot, close
    // the recur without advancing the date — nothing more should be created.
    if ($this->isInstallmentLimitReached($recur)) {
      $this->completeRecur($recur['id']);
      return TRUE;
    }

    // Still installments remaining (or open-ended) — advance the schedule.
    $this->advanceNextScheduledDate(
      $recur['id'],
      $recur['frequency_interval'],
      $recur['frequency_unit'],
      $recur['next_sched_contribution_date']
    );

    return TRUE;
  }

  /**
   * Resolve or create a PaymentToken for this transaction.
   *
   * Returns the PaymentToken ID. If an existing saved bank account was
   * selected on the form, its token ID is returned directly. Otherwise
   * a new BankAccount and PaymentToken are created from the submitted fields.
   *
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return int  PaymentToken.id
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \CRM_Core_Exception
   */
  private function resolvePaymentToken(
    \Civi\Payment\PropertyBag $propertyBag
  ): int {
    // Existing saved account selected on the form.
    if ($propertyBag->has('bankAccount')
      && !empty($propertyBag->getCustomProperty('bankAccount'))) {
      $tokenID = (int) $propertyBag->getCustomProperty('bankAccount');

      $token = PaymentToken::get(FALSE)
        ->addSelect('id')
        ->setWhere([
         ['id', '=', $tokenID],
         ['OR', [
           ['expiry_date', 'IS NULL'],
           ['expiry_date', '>=', date('Y-m-d')],
         ]],
       ])
        ->execute()
        ->first();

      if (empty($token)) {
        throw new PaymentProcessorException(
          'The selected bank account has expired. Please enter new account details.', 1001
        );
      }

      return $tokenID;
    }

    // New account details entered on the form.
    $accountNumber = $propertyBag->getCustomProperty('bank_account_number') ?? '';
    $routingNumber = $propertyBag->getCustomProperty('bank_identification_number') ?? '';

    if (empty($accountNumber) || empty($routingNumber)) {
      throw new PaymentProcessorException(
        'Invalid ACH payment information. Please re-enter.', 1000
      );
    }

    $contactID = $propertyBag->getContactID();
    $contact   = Contact::get(FALSE)
      ->addSelect('display_name')
      ->addWhere('id', '=', $contactID)
      ->execute()
      ->first();

    $accountName = $propertyBag->getCustomProperty('bank_name')
      ?? ($contact['display_name'] . ' Bank Account');

    $tokenString  = \CRM_Contribute_BAO_Contribution::generateInvoiceID();
    $accountType  = ucfirst(strtolower($propertyBag->getCustomProperty('bank_account_type')));
    $maskedNumber = CRM_Utils_System::mungeCreditCard($accountNumber);
    $label        = trim("{$accountType} {$maskedNumber}");

    BankAccount::create(FALSE)
      ->addValue('contact_id', $contactID)
      ->addValue('name', $accountName)
      ->addValue('account_number',
                 \Civi::service('crypto.token')->encrypt($accountNumber, 'CRED'))
      ->addValue('routing_number',
                 \Civi::service('crypto.token')->encrypt($routingNumber, 'CRED'))
      ->addValue('account_holder',
                 $propertyBag->getCustomProperty('account_holder'))
      ->addValue('account_type',
                 $propertyBag->getCustomProperty('bank_account_type'))
      ->addValue('payment_token', $tokenString)
      ->execute();

    $paymentToken = PaymentToken::create(FALSE)
      ->addValue('contact_id', $contactID)
      ->addValue('payment_processor_id', $this->getPaymentProcessor()['id'])
      ->addValue('token', $tokenString)
      ->addValue('masked_account_number', $label)
      ->addValue('ACH_Token_Data.label', $label)
      ->execute()
      ->first();

    return (int) $paymentToken['id'];
  }

  /**
   * Calculate and persist the next scheduled contribution date on a recur.
   *
   * Combines date calculation and persistence in one place. Used by both
   * doPayment() (advancing from today for a new recur) and doRecurPayment()
   * (advancing from the current next_sched_contribution_date).
   *
   * @param int $recurID
   * @param int $interval
   * @param string $unit day|week|month|year
   * @param string $fromDate Any DateTime-parseable string; defaults to 'now'.
   *
   * @return string  The new next_sched_contribution_date in Y-m-d format.
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \CRM_Core_Exception
   * @throws \DateMalformedIntervalStringException
   * @throws \DateMalformedStringException
   */
  private function advanceNextScheduledDate(
    int $recurID,
    int $interval,
    string $unit,
    string $fromDate = 'now'
  ): string {
    $map = $this->getFrequencyIntervalMap($interval);

    if (!isset($map[$unit])) {
      throw new PaymentProcessorException(
        "Unsupported frequency unit: {$unit}"
      );
    }

    $next = new DateTime($fromDate);
    $next->add(new DateInterval($map[$unit]));
    $nextDate = $next->format('Y-m-d');

    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurID)
      ->addValue('next_sched_contribution_date', $nextDate)
      ->execute();

    return $nextDate;
  }

  /**
   * Build a DateInterval specifier map for a given interval multiplier.
   *
   * Centralises the unit-to-spec mapping used by both advanceNextScheduledDate
   * and the end_date calculation in doPayment so they stay in sync.
   *
   * @param int $interval
   *
   * @return array  Keyed by frequency unit (day|week|month|year).
   */
  private function getFrequencyIntervalMap(int $interval): array {
    return [
      'day'   => "P{$interval}D",
      'week'  => "P{$interval}W",
      'month' => "P{$interval}M",
      'year'  => "P{$interval}Y",
    ];
  }

  /**
   * Check whether a ContributionRecur has reached its installment limit.
   *
   * Returns FALSE for open-ended recurs (installments = 0 or NULL),
   * allowing them to run indefinitely until cancelled.
   *
   * The count includes Completed and Pending contributions — Pending ones
   * are already queued in the NACHA pipeline and should count toward the
   * limit even before the bank settles them.
   *
   * @param array $recur Row from civicrm_contribution_recur.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  private function isInstallmentLimitReached(array $recur): bool {
    $limit = (int) ($recur['installments'] ?? 0);

    // 0 or NULL means open-ended — never consider the limit reached.
    if (empty($limit)) {
      return FALSE;
    }

    $count = Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_recur_id', '=', $recur['id'])
      ->addWhere('contribution_status_id:name', 'NOT IN', ['Failed', 'Cancelled'])
      ->execute()
      ->count();

    return $count >= $limit;
  }

  /**
   * Mark a ContributionRecur as Completed and stamp its end date.
   *
   * @param int $recurID
   *
   * @throws \CRM_Core_Exception
   */
  private function completeRecur(int $recurID): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurID)
      ->addValue('contribution_status_id:name', 'Completed')
      ->addValue('end_date', date('Y-m-d'))
      ->execute();
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