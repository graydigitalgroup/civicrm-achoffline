<?php

use Civi\ACHOffline\Event\ContributionReissuedEvent;
use Civi\ACHOffline\Event\ContributionReversedEvent;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentProcessorType;
use Civi\Api4\PaymentToken;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Exercises the ACH Offline recurring-installment cron path.
 *
 * Because ACHOffline is an offline processor, nothing else creates or advances
 * its recurring contributions. Core dispatches the payment cron to
 * Payment_ACHOffline::handlePaymentCron(), which drives each due recur through
 * doRecurPayment() -> Contribution.repeattransaction. These tests assert that:
 *   1. a due recur produces the next Pending installment (token stamped) and
 *      the schedule advances, and
 *   2. a recur that has reached its installment limit is completed without
 *      creating another contribution.
 *
 * @group headless
 */
class CRM_Core_Payment_ACHOfflineTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $contactID;

  private int $processorID;

  private int $tokenID;

  private CRM_Core_Payment_ACHOffline $processor;

  public function setUpHeadless() {
    // Install achoffline together with its dependencies (mjwshared) in the
    // correct order. findInstallRequirements() resolves the chain so the
    // mjwshared classloader is registered before ACHOffline's payment class
    // (which uses CRM_Core_Payment_MJWTrait) is scanned during container boot.
    return \Civi\Test::headless()
      ->callback(function () {
        $mgr = \CRM_Extension_System::singleton()->getManager();
        $mgr->install($mgr->findInstallRequirements(['achoffline']));
      }, 'install-achoffline-with-deps')
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    // The real caller (Job.run_payment_cron via CLI/cron) runs with full
    // permissions; grant them here so the permissioned ContributionRecur::get()
    // in get_scheduled_contributions() behaves the same under test.
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [
      'access CiviCRM',
      'access CiviContribute',
      'edit contributions',
      'view all contacts',
      'edit all contacts',
      'access all custom data',
      'administer CiviCRM',
    ];

    $this->contactID = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('display_name', 'ACH Recur Tester')
      ->execute()
      ->first()['id'];

    $typeID = PaymentProcessorType::get(FALSE)
      ->addWhere('name', '=', 'ACHOffline')
      ->execute()
      ->first()['id'];

    $this->processorID = PaymentProcessor::create(FALSE)
      ->addValue('payment_processor_type_id', $typeID)
      ->addValue('name', 'ACH Offline Test')
      ->addValue('class_name', 'Payment_ACHOffline')
      ->addValue('is_active', TRUE)
      ->addValue('is_test', FALSE)
      ->addValue('domain_id', \CRM_Core_Config::domainID())
      ->addValue('payment_instrument_id:name', 'EFT')
      ->execute()
      ->first()['id'];

    $this->tokenID = PaymentToken::create(FALSE)
      ->addValue('contact_id', $this->contactID)
      ->addValue('payment_processor_id', $this->processorID)
      ->addValue('token', 'ach-test-token')
      ->execute()
      ->first()['id'];

    $this->processor = \Civi\Payment\System::singleton()->getById($this->processorID);

    foreach (['achoffline_nsf_repeat_return_fee', 'achoffline_nsf_max_redrafts', 'achoffline_nsf_hold_recur'] as $setting) {
      \Civi::settings()->revert($setting);
    }
  }

  /**
   * A due recur with installments remaining should get its next Pending
   * installment created (with the bank-account token stamped) and its
   * schedule advanced past today.
   */
  public function testCreatesNextInstallmentAndAdvances(): void {
    $recurID = $this->createRecur(3);
    $this->createContribution($recurID, 'Completed');
    $this->makeDue($recurID);

    $this->assertContributionCount(1, $recurID, 'precondition: one contribution before cron');

    $this->processor->handlePaymentCron();

    $contributions = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'ACH_Processor_Data.Bank_Account')
      ->addWhere('contribution_recur_id', '=', $recurID)
      ->addOrderBy('id', 'ASC')
      ->execute();

    $this->assertCount(2, $contributions, 'a second installment was created');

    $new = $contributions->last();
    $this->assertEquals('Pending', $new['contribution_status_id:name'], 'new installment is Pending');
    $this->assertEquals($this->tokenID, $new['ACH_Processor_Data.Bank_Account'], 'bank-account token stamped for NACHA builder');

    $recur = ContributionRecur::get(FALSE)
      ->addSelect('next_sched_contribution_date', 'contribution_status_id:name')
      ->addWhere('id', '=', $recurID)
      ->execute()
      ->first();

    $this->assertGreaterThan(date('Y-m-d'), substr((string) $recur['next_sched_contribution_date'], 0, 10), 'schedule advanced past today');
    $this->assertNotEquals('Completed', $recur['contribution_status_id:name'], 'recur is not completed while installments remain');
  }

  /**
   * Once the first installment settles, core flips the recur to In Progress.
   * A due In Progress recur must still generate its next installment (this is
   * the case the old Pending-only filter silently dropped).
   */
  public function testProcessesRecurAlreadyInProgress(): void {
    $recurID = $this->createRecur(3);
    $this->createContribution($recurID, 'Completed');
    $this->makeDue($recurID, 'In Progress');

    $this->assertContributionCount(1, $recurID, 'precondition: one contribution before cron');

    $this->processor->handlePaymentCron();

    $this->assertContributionCount(2, $recurID, 'an In Progress recur still generates its next installment');
  }

  /**
   * A due recur that has already reached its installment limit should be
   * completed without creating another contribution.
   */
  public function testCompletesRecurWhenInstallmentLimitReached(): void {
    $recurID = $this->createRecur(1);
    $this->createContribution($recurID, 'Completed');
    $this->makeDue($recurID);

    $this->assertContributionCount(1, $recurID, 'precondition: limit already reached');

    $this->processor->handlePaymentCron();

    $this->assertContributionCount(1, $recurID, 'no extra installment created past the limit');

    $recur = ContributionRecur::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $recurID)
      ->execute()
      ->first();

    $this->assertEquals('Completed', $recur['contribution_status_id:name'], 'recur closed out at the installment limit');
  }

  /**
   * Reversing a paid ACH contribution cancels the original and reissues an open
   * Pending contribution carrying the original amount plus the NSF fee.
   */
  public function testReverseNsfReissuesOpenContribution(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '10');
    \Civi::settings()->set('achoffline_nsf_reversal_status', 'Cancelled');

    $recurID = $this->createRecur(3);
    $originalID = $this->createContribution($recurID, 'Completed');

    $res = CRM_ACHOffline_BAO_NsfReversal::reverse($originalID);

    $this->assertFalse($res['skipped']);
    $this->assertTrue($res['was_paid'], 'a Completed original counts as paid');
    $this->assertNotNull($res['new_id']);

    $orig = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $originalID)
      ->execute()
      ->first();
    $this->assertEquals('Cancelled', $orig['contribution_status_id:name'], 'original is reversed');

    $new = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'total_amount', 'contribution_recur_id',
        'ACH_Processor_Data.NSF_Reissued_From', 'balance_amount')
      ->addWhere('id', '=', $res['new_id'])
      ->execute()
      ->first();
    $this->assertEquals('Pending', $new['contribution_status_id:name'], 'reissue is open');
    $this->assertEquals(35, (float) $new['total_amount'], 'reissue total = original 25 + fee 10');
    $this->assertEquals(35, (float) $new['balance_amount'], 'full amount is owed');
    $this->assertEquals($recurID, $new['contribution_recur_id'], 'reissue stays in the recurring series');
    $this->assertEquals($originalID, $new['ACH_Processor_Data.NSF_Reissued_From'], 'reissue is marked');
  }

  /**
   * Reversing a contribution that is already reversed is a no-op, so the
   * SearchKit task is safe to run more than once.
   */
  public function testReverseNsfIsSafeToRerun(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '');

    $recurID = $this->createRecur(3);
    $originalID = $this->createContribution($recurID, 'Completed');

    $first = CRM_ACHOffline_BAO_NsfReversal::reverse($originalID);
    $this->assertFalse($first['skipped']);
    $this->assertNotNull($first['new_id']);

    $second = CRM_ACHOffline_BAO_NsfReversal::reverse($originalID);
    $this->assertTrue($second['skipped'], 'already-reversed original is skipped');
    $this->assertNull($second['new_id']);
  }

  /**
   * While a returned installment sits reissued and unpaid, the cron must not
   * generate the next scheduled installment on top of it.
   */
  public function testCronSkipsWhileOpenReissueExists(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '');

    // Open-ended so the installment limit isn't what stops the cron.
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');

    $res = CRM_ACHOffline_BAO_NsfReversal::reverse($originalID);
    $this->assertNotNull($res['new_id']);

    // Cancelled original + open Pending reissue.
    $this->assertContributionCount(2, $recurID, 'precondition: original reversed, reissue open');

    $this->makeDue($recurID, 'In Progress');
    $this->processor->handlePaymentCron();

    $this->assertContributionCount(2, $recurID, 'cron did not stack a new installment on the open reissue');
  }

  public function testGetOpenReissueIds(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '');
    $recurID = $this->createRecur(0);
    $this->assertSame([], CRM_ACHOffline_BAO_NsfReversal::getOpenReissueIds($recurID));

    $originalID = $this->createContribution($recurID, 'Completed');
    $newID = (int) CRM_ACHOffline_BAO_NsfReversal::reverse($originalID)['new_id'];
    $this->assertSame([$newID], CRM_ACHOffline_BAO_NsfReversal::getOpenReissueIds($recurID));

    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Cancelled')
      ->addWhere('id', '=', $newID)
      ->execute();
    $this->assertSame([], CRM_ACHOffline_BAO_NsfReversal::getOpenReissueIds($recurID));
  }

  public function testReverseNsfSkipsNonAchContribution(): void {
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed', FALSE);

    $res = CRM_ACHOffline_BAO_NsfReversal::reverse($originalID);

    $this->assertTrue($res['skipped']);
    $this->assertNotEmpty($res['skip_reason']);
    $this->assertNull($res['new_id']);
    $status = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name')
      ->addWhere('id', '=', $originalID)
      ->execute()
      ->first()['contribution_status_id:name'];
    $this->assertEquals('Completed', $status, 'non-ACH contribution is untouched');
  }

  public function testReverseNsfFeeAmountParam(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '10');
    $recurID = $this->createRecur(0);

    $cases = [
      [NULL, 35.0],
      [0, 25.0],
      [15, 40.0],
    ];
    foreach ($cases as [$feeAmount, $expectedTotal]) {
      $originalID = $this->createContribution($recurID, 'Completed');
      $params = ['checkPermissions' => FALSE, 'where' => [['id', '=', $originalID]]];
      if ($feeAmount !== NULL) {
        $params['feeAmount'] = $feeAmount;
      }
      $res = civicrm_api4('Contribution', 'reverseNsf', $params)->first();

      $this->assertNull($res['error']);
      $this->assertEquals($expectedTotal - 25, $res['fee_amount']);
      $total = Contribution::get(FALSE)
        ->addSelect('total_amount')
        ->addWhere('id', '=', $res['new_id'])
        ->execute()
        ->first()['total_amount'];
      $this->assertEquals($expectedTotal, (float) $total, 'feeAmount ' . var_export($feeAmount, TRUE));
    }
  }

  public function testSecondReturnAddsNoFeeAndIsNotRedrafted(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '10');
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');
    $firstID = (int) CRM_ACHOffline_BAO_NsfReversal::reverse($originalID)['new_id'];
    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Completed')
      ->addWhere('id', '=', $firstID)
      ->execute();

    $res = CRM_ACHOffline_BAO_NsfReversal::reverse($firstID, 10);

    $this->assertEquals(0, $res['fee_amount']);
    $second = Contribution::get(FALSE)
      ->addSelect('total_amount', 'ACH_Processor_Data.Bank_Account', 'ACH_Processor_Data.NSF_Reissued_From')
      ->addWhere('id', '=', $res['new_id'])
      ->execute()
      ->first();
    $this->assertEquals(35, (float) $second['total_amount'], 'first fee carried, no second fee');
    $this->assertEmpty($second['ACH_Processor_Data.Bank_Account'], 'not redrafted by ACH');
    $this->assertEquals($firstID, $second['ACH_Processor_Data.NSF_Reissued_From']);
  }

  public function testRepeatReturnFeeSetting(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '10');
    \Civi::settings()->set('achoffline_nsf_repeat_return_fee', TRUE);
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');
    $firstID = $this->reverseAndComplete($originalID);

    $res = CRM_ACHOffline_BAO_NsfReversal::reverse($firstID);

    $this->assertEquals(10, $res['fee_amount']);
    $total = Contribution::get(FALSE)
      ->addSelect('total_amount')
      ->addWhere('id', '=', $res['new_id'])
      ->execute()
      ->first()['total_amount'];
    $this->assertEquals(45, (float) $total);
  }

  public function testMaxRedraftsSetting(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '');
    \Civi::settings()->set('achoffline_nsf_max_redrafts', 2);
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');

    $firstID = $this->reverseAndComplete($originalID);
    $this->assertEquals($this->tokenID, $this->getBankAccount($firstID));
    $secondID = $this->reverseAndComplete($firstID);
    $this->assertEquals($this->tokenID, $this->getBankAccount($secondID));
    $thirdID = (int) CRM_ACHOffline_BAO_NsfReversal::reverse($secondID)['new_id'];
    $this->assertEmpty($this->getBankAccount($thirdID));
    $this->assertSame(2, CRM_ACHOffline_BAO_NsfReversal::getPriorReturnCount(['ACH_Processor_Data.NSF_Reissued_From' => $secondID]));
  }

  public function testZeroMaxRedraftsNeverRedrafts(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '');
    \Civi::settings()->set('achoffline_nsf_max_redrafts', 0);
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');

    $newID = (int) CRM_ACHOffline_BAO_NsfReversal::reverse($originalID)['new_id'];

    $this->assertEmpty($this->getBankAccount($newID));
  }

  public function testCronCreatesInstallmentWhenHoldIsOff(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '');
    \Civi::settings()->set('achoffline_nsf_hold_recur', FALSE);
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');
    CRM_ACHOffline_BAO_NsfReversal::reverse($originalID);

    $this->makeDue($recurID, 'In Progress');
    $this->processor->handlePaymentCron();

    $this->assertContributionCount(3, $recurID, 'cron created the next installment alongside the open reissue');
  }

  public function testReverseNsfRejectsNegativeFee(): void {
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');

    $this->expectException(\CRM_Core_Exception::class);
    civicrm_api4('Contribution', 'reverseNsf', [
      'checkPermissions' => FALSE,
      'where' => [['id', '=', $originalID]],
      'feeAmount' => -1,
    ]);
  }

  public function testReverseNsfPassesFeeAndReasonToEvents(): void {
    \Civi::settings()->set('achoffline_nsf_fee_amount', '10');
    $recurID = $this->createRecur(0);
    $originalID = $this->createContribution($recurID, 'Completed');

    $captured = [];
    $onReissued = function ($e) use (&$captured) {
      $captured['reissued'] = [$e->getFeeAmount(), $e->getReturnReason()];
    };
    $onReversed = function ($e) use (&$captured) {
      $captured['reversed'] = $e->getReturnReason();
    };
    $dispatcher = \Civi::dispatcher();
    $dispatcher->addListener(ContributionReissuedEvent::NAME, $onReissued);
    $dispatcher->addListener(ContributionReversedEvent::NAME, $onReversed);
    try {
      civicrm_api4('Contribution', 'reverseNsf', [
        'checkPermissions' => FALSE,
        'where' => [['id', '=', $originalID]],
        'feeAmount' => 12.5,
        'reason' => 'R01',
      ]);
    }
    finally {
      $dispatcher->removeListener(ContributionReissuedEvent::NAME, $onReissued);
      $dispatcher->removeListener(ContributionReversedEvent::NAME, $onReversed);
    }

    $this->assertEquals([12.5, 'R01'], $captured['reissued']);
    $this->assertEquals('R01', $captured['reversed']);

    $cancelReason = Contribution::get(FALSE)
      ->addSelect('cancel_reason')
      ->addWhere('id', '=', $originalID)
      ->execute()
      ->first()['cancel_reason'];
    $this->assertStringContainsString('R01', $cancelReason);
  }

  /**
   * A submitted saved-token id that belongs to a different contact must be
   * rejected rather than drafted against this contribution. doPayment runs
   * perms-off, so this scoping has to happen in the processor itself.
   */
  public function testDoPaymentRejectsAnotherContactsToken(): void {
    $contributionID = $this->createStandaloneContribution($this->contactID);

    // A token owned by a *different* contact.
    $otherContactID = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('display_name', 'Someone Else')
      ->execute()
      ->first()['id'];
    $otherTokenID = PaymentToken::create(FALSE)
      ->addValue('contact_id', $otherContactID)
      ->addValue('payment_processor_id', $this->processorID)
      ->addValue('token', 'ach-other-contact-token')
      ->execute()
      ->first()['id'];

    $params = [
      'amount' => 25,
      'currency' => 'USD',
      'invoiceID' => 'inv-reject-' . $contributionID,
      'contactID' => $this->contactID,
      'contributionID' => $contributionID,
      'paymentToken' => $otherTokenID,
      'installments' => 1,
      // Supply billing so that, if the contact guard were missing, doPayment
      // would run all the way through and stamp the foreign token - making the
      // failure a clean fail() here rather than an unrelated downstream error.
      'billingStreetAddress' => '123 Test St',
      'billingCity' => 'Austin',
      'billingStateProvince' => 'TX',
      'billingPostalCode' => '78701',
      'billingCountry' => 'US',
    ];

    try {
      $this->processor->doPayment($params);
      $this->fail('doPayment accepted a bank token belonging to another contact');
    }
    catch (\Civi\Payment\Exception\PaymentProcessorException $e) {
      // Expected - the token did not resolve for the paying contact.
    }

    // The foreign token must not have been written onto the contribution.
    $bankAccount = Contribution::get(FALSE)
      ->addSelect('ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first()['ACH_Processor_Data.Bank_Account'] ?? NULL;
    $this->assertNotEquals($otherTokenID, $bankAccount, 'another contact\'s token was not stamped on the contribution');
  }

  /**
   * The paying contact's own saved bank token resolves and is stamped on the
   * contribution, leaving it Pending for the NACHA sweep - the guard must not
   * block legitimate use.
   */
  public function testDoPaymentAcceptsOwnToken(): void {
    $contributionID = $this->createStandaloneContribution($this->contactID);

    $params = [
      'amount' => 25,
      'currency' => 'USD',
      'invoiceID' => 'inv-accept-' . $contributionID,
      'contactID' => $this->contactID,
      'contributionID' => $contributionID,
      'paymentToken' => $this->tokenID,
      'installments' => 1,
      // Billing block - doPayment reads these unconditionally when building the
      // gateway args; PropertyBag drops empty strings, so send real values.
      'billingStreetAddress' => '123 Test St',
      'billingCity' => 'Austin',
      'billingStateProvince' => 'TX',
      'billingPostalCode' => '78701',
      'billingCountry' => 'US',
    ];

    $result = $this->processor->doPayment($params);

    $this->assertEquals('Pending', $result['payment_status'], 'ACH draft stays Pending for the NACHA sweep');

    $bankAccount = Contribution::get(FALSE)
      ->addSelect('ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first()['ACH_Processor_Data.Bank_Account'] ?? NULL;
    $this->assertEquals($this->tokenID, $bankAccount, 'the contact\'s own token is stamped on the contribution');
  }

  /**
   * ACH draws on routing + account number, not a billing address, and the
   * contact here has none on file. doPayment must still succeed (stamping the
   * token and staying Pending) rather than throwing on an unset billing getter.
   */
  public function testDoPaymentSucceedsWithoutBillingAddress(): void {
    $contributionID = $this->createStandaloneContribution($this->contactID);

    $params = [
      'amount' => 25,
      'currency' => 'USD',
      'invoiceID' => 'inv-nobilling-' . $contributionID,
      'contactID' => $this->contactID,
      'contributionID' => $contributionID,
      'paymentToken' => $this->tokenID,
      'installments' => 1,
      // No billing block supplied, and the contact has no address.
    ];

    $result = $this->processor->doPayment($params);

    $this->assertEquals('Pending', $result['payment_status'], 'ACH draft stays Pending without a billing address');

    $bankAccount = Contribution::get(FALSE)
      ->addSelect('ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first()['ACH_Processor_Data.Bank_Account'] ?? NULL;
    $this->assertEquals($this->tokenID, $bankAccount, 'token stamped even with no address on file');
  }

  /**
   * Switching the bank account on a recur mints a new token for the contact,
   * repoints the recur at it, and moves any Pending draft the recur has already
   * queued onto the new token (so the NACHA export pulls the new account).
   */
  public function testUpdateSubscriptionBillingInfoSwitchesBankAndRestampsPending(): void {
    $recurID = $this->createRecur(12);
    $pendingID = $this->createContribution($recurID, 'Pending');
    // Stamp the existing draft with the recur's current (old) token.
    Contribution::update(FALSE)
      ->addValue('ACH_Processor_Data.Bank_Account', $this->tokenID)
      ->addWhere('id', '=', $pendingID)
      ->execute();

    $message = '';
    $this->processor->updateSubscriptionBillingInfo($message, [
      'contributionRecurID' => $recurID,
      'account_holder' => 'ACH Recur Tester',
      'bank_account_number' => '000111222333',
      'bank_identification_number' => '021000021',
      'bank_account_type' => 'CHECKING',
    ]);

    $newTokenID = (int) ContributionRecur::get(FALSE)
      ->addSelect('payment_token_id')
      ->addWhere('id', '=', $recurID)
      ->execute()
      ->first()['payment_token_id'];
    $this->assertNotEquals($this->tokenID, $newTokenID, 'the recur was repointed to a new token');

    $owner = PaymentToken::get(FALSE)
      ->addSelect('contact_id')
      ->addWhere('id', '=', $newTokenID)
      ->execute()
      ->first();
    $this->assertEquals($this->contactID, $owner['contact_id'], 'the new token belongs to the subscriber');

    $stamp = Contribution::get(FALSE)
      ->addSelect('ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $pendingID)
      ->execute()
      ->first()['ACH_Processor_Data.Bank_Account'] ?? NULL;
    $this->assertEquals($newTokenID, $stamp, 'the pending draft was moved onto the new token');
  }

  /**
   * Only Pending drafts are moved. A contribution that has already settled keeps
   * the token it was drafted on, so the ledger/NACHA history stays accurate.
   */
  public function testUpdateSubscriptionBillingInfoLeavesSettledDraftsAlone(): void {
    $recurID = $this->createRecur(12);
    $settledID = $this->createContribution($recurID, 'Completed');
    Contribution::update(FALSE)
      ->addValue('ACH_Processor_Data.Bank_Account', $this->tokenID)
      ->addWhere('id', '=', $settledID)
      ->execute();

    $message = '';
    $this->processor->updateSubscriptionBillingInfo($message, [
      'contributionRecurID' => $recurID,
      'account_holder' => 'ACH Recur Tester',
      'bank_account_number' => '000999888777',
      'bank_identification_number' => '021000021',
      'bank_account_type' => 'CHECKING',
    ]);

    $stamp = Contribution::get(FALSE)
      ->addSelect('ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $settledID)
      ->execute()
      ->first()['ACH_Processor_Data.Bank_Account'] ?? NULL;
    $this->assertEquals($this->tokenID, $stamp, 'a settled contribution keeps its original token');
  }

  /**
   * Create a standalone (non-recurring) Pending EFT contribution for a contact.
   */
  private function createStandaloneContribution(int $contactID): int {
    return Contribution::create(FALSE)
      ->addValue('contact_id', $contactID)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 25)
      ->addValue('currency', 'USD')
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('receive_date', date('Y-m-d'))
      ->addValue('payment_instrument_id:name', 'EFT')
      ->execute()
      ->first()['id'];
  }

  /**
   * Create a recurring contribution for the ACH processor.
   */
  private function createRecur(int $installments): int {
    return ContributionRecur::create(FALSE)
      ->addValue('contact_id', $this->contactID)
      ->addValue('amount', 25)
      ->addValue('currency', 'USD')
      ->addValue('frequency_unit', 'month')
      ->addValue('frequency_interval', 1)
      ->addValue('installments', $installments)
      ->addValue('payment_processor_id', $this->processorID)
      ->addValue('payment_token_id', $this->tokenID)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('next_sched_contribution_date', date('Y-m-d'))
      ->execute()
      ->first()['id'];
  }

  /**
   * Force the recur into the "due today, Pending" state that
   * get_scheduled_contributions() looks for. Creating the template
   * contribution triggers core's recurring bookkeeping, which advances
   * next_sched_contribution_date and flips the status to In Progress, so we
   * reset it here to simulate a recur that is due for its next installment.
   */
  private function makeDue(int $recurID, string $status = 'Pending'): void {
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurID)
      ->addValue('next_sched_contribution_date', date('Y-m-d'))
      ->addValue('contribution_status_id:name', $status)
      ->execute();
  }

  /**
   * Create a contribution linked to the recur (the template for repeattransaction).
   */
  private function createContribution(int $recurID, string $status, bool $withBankAccount = TRUE): int {
    return Contribution::create(FALSE)
      ->addValue('ACH_Processor_Data.Bank_Account', $withBankAccount ? $this->tokenID : NULL)
      ->addValue('contact_id', $this->contactID)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 25)
      ->addValue('currency', 'USD')
      ->addValue('contribution_status_id:name', $status)
      ->addValue('receive_date', date('Y-m-d'))
      ->addValue('contribution_recur_id', $recurID)
      ->addValue('payment_instrument_id:name', 'EFT')
      ->execute()
      ->first()['id'];
  }

  private function reverseAndComplete(int $contributionID): int {
    $newID = (int) CRM_ACHOffline_BAO_NsfReversal::reverse($contributionID)['new_id'];
    Contribution::update(FALSE)
      ->addValue('contribution_status_id:name', 'Completed')
      ->addWhere('id', '=', $newID)
      ->execute();
    return $newID;
  }

  private function getBankAccount(int $contributionID): ?int {
    $value = Contribution::get(FALSE)
      ->addSelect('ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', $contributionID)
      ->execute()
      ->first()['ACH_Processor_Data.Bank_Account'] ?? NULL;
    return $value ? (int) $value : NULL;
  }

  private function assertContributionCount(int $expected, int $recurID, string $message): void {
    $count = Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_recur_id', '=', $recurID)
      ->execute()
      ->count();
    $this->assertEquals($expected, $count, $message);
  }

}