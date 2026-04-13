<?php

namespace Civi\ACHOffline;

use Civi\API\Event\PrepareEvent;
use Civi\API\Event\RespondEvent;
use Civi\Api4\BankAccount;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\DAOCreateAction;
use Civi\Api4\Generic\DAOSaveAction;
use Civi\Api4\Generic\DAOUpdateAction;
use Civi\Api4\Generic\Result;
use Civi\Api4\PaymentToken;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles BankAccount lifecycle events.
 *
 * - Encrypts sensitive fields before create/update/save.
 * - Generates payment_token on create.
 * - Creates a linked PaymentToken record after BankAccount is created.
 *
 * @service
 * @internal
 */
class BankAccountSubscriber extends AutoService implements EventSubscriberInterface {

  /**
   * Fields that must be encrypted before saving.
   */
  private const array ENCRYPTED_FIELDS = [
    'account_number',
    'routing_number',
  ];

  public static function getSubscribedEvents(): array {
    return [
      'civi.api.prepare' => ['onPrepare', 500],
      'civi.api.respond' => ['onRespond', 400],
    ];
  }

  /**
   * Before BankAccount create/update/save:
   * - Generate payment_token if missing (create only).
   * - Encrypt sensitive fields before values reach the DAO.
   *
   * @throws \CRM_Core_Exception
   */
  public function onPrepare(PrepareEvent $event): void {
    $apiRequest = $event->getApiRequest();

    if (!($apiRequest instanceof \Civi\Api4\Generic\AbstractAction)) {
      return;
    }

    if ($apiRequest->getEntityName() !== 'BankAccount') {
      return;
    }

    $crypto = \Civi::service('crypto.token');

    if ($apiRequest instanceof DAOCreateAction) {
      // Generate payment_token if not provided.
      if (empty($apiRequest->getValue('payment_token'))) {
        $apiRequest->addValue(
          'payment_token',
          \CRM_Contribute_BAO_Contribution::generateInvoiceID()
        );
      }

      // Encrypt sensitive fields.
      foreach (self::ENCRYPTED_FIELDS as $field) {
        $value = $apiRequest->getValue($field);
        if (!empty($value) && !$this->isEncrypted($value)) {
          $apiRequest->addValue($field, $crypto->encrypt($value, 'CRED'));
        }
      }
      return;
    }

    if ($apiRequest instanceof DAOUpdateAction) {
      foreach (self::ENCRYPTED_FIELDS as $field) {
        $value = $apiRequest->getValue($field);
        if (!empty($value) && !$this->isEncrypted($value)) {
          $apiRequest->addValue($field, $crypto->encrypt($value, 'CRED'));
        }
      }
      return;
    }

    // DAOSaveAction uses getRecords/setRecords instead of getValue/addValue.
    if ($apiRequest instanceof DAOSaveAction) {
      $records = $apiRequest->getRecords();

      foreach ($records as &$record) {
        // Generate payment_token if not provided on new records (no id).
        if (empty($record['id']) && empty($record['payment_token'])) {
          $record['payment_token'] = \CRM_Contribute_BAO_Contribution::generateInvoiceID();
        }

        foreach (self::ENCRYPTED_FIELDS as $field) {
          if (!empty($record[$field]) && !$this->isEncrypted($record[$field])) {
            $record[$field] = $crypto->encrypt($record[$field], 'CRED');
          }
        }
      }

      $apiRequest->setRecords($records);
    }
  }

  /**
   * Create a linked PaymentToken record after a BankAccount is created.
   *
   * @throws \CRM_Core_Exception
   */
  public function onRespond(RespondEvent $event): void {
    $apiRequest = $event->getApiRequest();

    if (!($apiRequest instanceof AbstractAction)) {
      return;
    }

    if ($apiRequest->getEntityName() !== 'BankAccount') {
      return;
    }

    // After create — create linked PaymentToken.
    if ($apiRequest instanceof DAOCreateAction) {
      $this->handleCreate($event->getResponse());
      return;
    }

    // After update — sync masked_account_number on linked PaymentToken
    // if account_number was part of the update.
    // DAOSaveAction covers both create and update via upsert —
    // Afform typically uses save rather than explicit create/update.
    if ($apiRequest instanceof DAOUpdateAction || $apiRequest instanceof DAOSaveAction) {
      $this->handleUpdate($apiRequest, $event->getResponse());
    }
  }

  /**
   * Handle post-create: create linked PaymentToken.
   *
   * @throws \CRM_Core_Exception
   */
  private function handleCreate(Result $result): void {
    $bankAccount = BankAccount::get(FALSE)
      ->addSelect('id', 'contact_id', 'payment_token', 'account_number', 'account_type')
      ->addWhere('id', '=', $result->first()['id'])
      ->execute()
      ->first();

    if (empty($bankAccount) || empty($bankAccount['payment_token'])) {
      \Civi::log()->warning('ACHOffline: BankAccount created without payment_token — skipping PaymentToken creation.', [
        'bank_account_id' => $result->first()['id'],
      ]);
      return;
    }

    $processorID = $this->resolveProcessorID();

    if (empty($processorID)) {
      \Civi::log()->warning('ACHOffline: Could not resolve payment processor ID — skipping PaymentToken creation.', [
        'bank_account_id' => $bankAccount['id'],
      ]);
      return;
    }

    // Avoid duplicates if the subscriber fires more than once.
    $existing = PaymentToken::get(FALSE)
      ->addSelect('id')
      ->addWhere('token', '=', $bankAccount['payment_token'])
      ->execute()
      ->first();

    if (!empty($existing)) {
      return;
    }

    PaymentToken::create(FALSE)
      ->addValue('contact_id', $bankAccount['contact_id'])
      ->addValue('payment_processor_id', $processorID)
      ->addValue('token', $bankAccount['payment_token'])
      ->addValue('masked_account_number', $this->buildMaskedLabel(
        $bankAccount['account_number'],
        $bankAccount['account_type']
      ))
      ->execute();
  }

  /**
   * Handle post-update: sync PaymentToken.masked_account_number if
   * account_number was updated.
   *
   * @param \Civi\Api4\Generic\DAOUpdateAction|\Civi\Api4\Generic\DAOSaveAction $apiRequest
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private function handleUpdate(DAOUpdateAction|DAOSaveAction $apiRequest, Result $result): void {
    if ($apiRequest instanceof DAOSaveAction) {
      $records = $apiRequest->getRecords();
      foreach ($result as $savedRecord) {
        $submitted = current(array_filter(
          $records,
          fn($r) => !empty($r['id']) && (int) $r['id'] === (int) $savedRecord['id']
        ));

        if (empty($submitted)) {
          continue;
        }

        // Trigger sync if either account_number or account_type changed.
        if (!empty($submitted['account_number']) || !empty($submitted['account_type'])) {
          $this->syncPaymentTokenLabel($savedRecord['id'], $submitted);
        }
      }
      return;
    }

    // DAOUpdateAction — trigger sync if account_number or account_type changed.
    $accountNumber = $apiRequest->getValue('account_number');
    $accountType   = $apiRequest->getValue('account_type');

    if (empty($accountNumber) && empty($accountType)) {
      return;
    }

    $submittedValues = array_filter([
      'account_number' => $accountNumber,
      'account_type'   => $accountType,
    ]);

    foreach ($result as $updatedRecord) {
      $this->syncPaymentTokenLabel($updatedRecord['id'], $submittedValues);
    }
  }

  /**
   * Sync PaymentToken.masked_account_number using submitted values where
   * available, falling back to the stored DB values for anything not submitted.
   *
   * Using submitted values avoids decryption issues when the responding
   * contact is not an admin and the DB returns munged values via onApiRespond.
   *
   * @param int $bankAccountId
   * @param array $submittedValues  Partial values from the submitted form.
   *
   * @throws \CRM_Core_Exception
   */
  private function syncPaymentTokenLabel(int $bankAccountId, array $submittedValues = []): void {
    $bankAccount = BankAccount::get(FALSE)
      ->addSelect('payment_token', 'account_number', 'account_type')
      ->addWhere('id', '=', $bankAccountId)
      ->execute()
      ->first();

    if (empty($bankAccount['payment_token'])) {
      return;
    }

    // Use submitted account_number if provided since it will have been
    // encrypted by BankAccountSubscriber::onPrepare and the DB value
    // returned by get may be munged for non-admin users via onApiRespond.
    $accountNumber = $submittedValues['account_number']
      ?? $bankAccount['account_number'];

    // Use submitted account_type if provided — someone may only be
    // updating the type without changing the account number.
    $accountType = $submittedValues['account_type']
      ?? $bankAccount['account_type'];

    PaymentToken::update(FALSE)
      ->addValue('masked_account_number', $this->buildMaskedLabel(
        $accountNumber,
        $accountType
      ))
      ->addWhere('token', '=', $bankAccount['payment_token'])
      ->execute();

    \Civi::cache('long')->clear();
  }

  /**
   * Build a masked label from an encrypted account number and account type.
   *
   * @param string $encryptedAccountNumber
   * @param string|null $accountType
   *
   * @return string
   */
  private function buildMaskedLabel(
    string $encryptedAccountNumber,
    ?string $accountType
  ): string {
    try {
      $plaintext = \Civi::service('crypto.token')
        ->decrypt($encryptedAccountNumber, 'CRED');
      $masked    = \CRM_Utils_System::mungeCreditCard($plaintext);
    }
    catch (\Throwable $e) {
      \Civi::log()->warning('ACHOffline: Could not decrypt account number for masking.', [
        'error' => $e->getMessage(),
      ]);
      $masked = '****';
    }

    $type = $accountType
      ? ucfirst(strtolower($accountType))
      : '';

    return trim("{$type} {$masked}");
  }

  /**
   * Resolve the payment processor ID for the ACHOffline processor.
   *
   * @return int|null
   * @throws \CRM_Core_Exception
   */
  private function resolveProcessorID(): ?int {
    $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id')
      ->addWhere('class_name', '=', 'Payment_ACHOffline')
      ->addWhere('is_active', '=', TRUE)
      ->addWhere('is_test', '=', FALSE)
      ->execute()
      ->first();

    return isset($processor['id']) ? (int) $processor['id'] : NULL;
  }

  /**
   * Check whether a value is already encrypted to avoid double-encrypting.
   *
   * @param string $value
   * @return bool
   */
  private function isEncrypted(string $value): bool {
    return str_starts_with($value, 'CTK');
  }

}