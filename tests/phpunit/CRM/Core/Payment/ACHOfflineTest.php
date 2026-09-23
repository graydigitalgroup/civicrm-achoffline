<?php

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
  private function createContribution(int $recurID, string $status): int {
    return Contribution::create(FALSE)
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

  private function assertContributionCount(int $expected, int $recurID, string $message): void {
    $count = Contribution::get(FALSE)
      ->selectRowCount()
      ->addWhere('contribution_recur_id', '=', $recurID)
      ->execute()
      ->count();
    $this->assertEquals($expected, $count, $message);
  }

}