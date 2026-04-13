<?php
namespace Civi\ACHOffline;

use Civi\Afform\Event\AfformPrefillEvent;
use Civi\Afform\Event\AfformSubmitEvent;
use Civi\API\Event\RespondEvent;
use Civi\Api4\BankAccount;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\PaymentToken;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use CRM_Utils_System;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Preprocess Afform requests
 *
 * @service
 * @internal
 *
*/
class AfformSubscriber extends AutoService implements EventSubscriberInterface {

  /**
   * Defines the hooks for this subscriber.
   *
   * @return array[]
   */
  public static function getSubscribedEvents(): array {
    return [
      'civi.api.respond' => ['onApiRespond'],
      'civi.afform.submit' => ['onSubmit'],
    ];
  }

  /**
   * Munges the account information or returns decrypted data.
   *
   * @param \Civi\API\Event\RespondEvent $event
   *
   * @return void
   */
  public function onApiRespond(RespondEvent $event): void {
    $apiRequest = $event->getApiRequest();

    if (!($apiRequest instanceof AbstractAction)) {
      return;
    }

    if ($apiRequest->getEntityName() !== 'BankAccount'
      || $apiRequest->getActionName() !== 'get') {
      return;
    }

    // Only munge when called with permission checking enabled AND
    // from an Afform or form context. Internal trusted calls use
    // get(FALSE) and need raw encrypted values.
    if (!$apiRequest->getCheckPermissions()) {
      return;
    }

    // Detect Afform or variant of QuickForm.
    $isFormContext = $this->isFormContext();

    if (!$isFormContext) {
      return;
    }

    $result = $event->getResponse();

    foreach ($result as $idx => $record) {
      if (!empty($record['account_number'])) {
        try {
          $decrypted = \Civi::service('crypto.token')
            ->decrypt($record['account_number'], 'CRED');
          $result[$idx]['account_number'] =
            \CRM_Utils_System::mungeCreditCard($decrypted);
        }
        catch (\Throwable $e) {
          $result[$idx]['account_number'] = '****';
        }
      }

      if (!empty($record['routing_number'])) {
        try {
          $decrypted = \Civi::service('crypto.token')
            ->decrypt($record['routing_number'], 'CRED');
          $result[$idx]['routing_number'] =
            \CRM_Utils_System::mungeCreditCard($decrypted);
        }
        catch (\Throwable $e) {
          $result[$idx]['routing_number'] = '****';
        }
      }
    }
  }

  /**
   * Handles Afform Submission overrides for this extension.
   *
   * @param \Civi\Afform\Event\AfformSubmitEvent $event
   *
   * @return void
   */
  public function onSubmit(AfformSubmitEvent $event): void {
    if ($event->getEntityType() !== 'BankAccount') {
      return;
    }

    $records = $event->getRecords();

    foreach ($records as &$record) {
      // Strip masked display values so they don't reach the DAO and
      // trigger re-encryption of a munged value. BankAccountSubscriber
      // handles encryption and PaymentToken sync via civi.api.prepare
      // and civi.api.respond.
      if (!empty($record['fields']['account_number'])
        && str_contains($record['fields']['account_number'], '*')) {
        unset($record['fields']['account_number']);
      }

      if (!empty($record['fields']['routing_number'])
        && str_contains($record['fields']['routing_number'], '*')) {
        unset($record['fields']['routing_number']);
      }
    }

    $event->setRecords($records);
  }

  private function isFormContext(): bool {
    $q = $_REQUEST['q'] ?? '';
    if (str_contains($q, 'api4/Afform/prefill')) {
      return TRUE;
    }

    // QuickForm context — formName prefixed with 'qf:'.
    $params = isset($_REQUEST['params']) ? json_decode($_REQUEST['params'], TRUE) : [];

    $formName = $params['formName'] ?? '';
    if (str_starts_with($formName, 'qf:')) {
      return TRUE;
    }

    return FALSE;
  }
}