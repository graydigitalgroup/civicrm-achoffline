<?php
namespace Civi\ACHOffline;

use Civi\Afform\Event\AfformPrefillEvent;
use Civi\Afform\Event\AfformSubmitEvent;
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

  public static function getSubscribedEvents(): array {
    return [
      'civi.api.respond' => ['onApiRespond'],
      'civi.afform.submit' => ['onSubmit'],
      'civi.api.prepare' => ['onApiPrepare', 150],
    ];
  }

  public function onApiRespond(\Civi\API\Event\RespondEvent $event): void {
    $request = $event->getApiRequest();
    if ($request['entity'] !== 'BankAccount' || $request['action'] !== 'get') return;

    $result = $event->getResponse();
    foreach ($result as $idx => $record) {
      if (!empty($record['account_number'])) {
        $decrypted = \Civi::service('crypto.token')->decrypt($record['account_number']);
        $result[$idx]['account_number'] = $request['checkPermissions']
          ? \CRM_Utils_System::mungeCreditCard($decrypted)
          : $decrypted;
      }
      if (!empty($record['routing_number'])) {
        $decrypted = \Civi::service('crypto.token')->decrypt($record['routing_number']);
        $result[$idx]['routing_number'] = $request['checkPermissions']
          ? \CRM_Utils_System::mungeCreditCard($decrypted)
          : $decrypted;
      }
    }
  }

  public function onSubmit(AfformSubmitEvent $event): void {
    if ($event->getEntityType() !== 'BankAccount') return;

    $records = $event->getRecords();
    foreach ($records as &$record) {
      if (!empty($record['fields']['account_number'])) {
        if (!str_contains($record['fields']['account_number'], '*')) {
          $record['fields']['account_number'] = \Civi::service('crypto.token')
            ->encrypt($record['fields']['account_number'], 'CRED');
        } else {
          unset($record['fields']['account_number']);
        }
      }
      if (!empty($record['fields']['routing_number'])) {
        if (!str_contains($record['fields']['routing_number'], '*')) {
          $record['fields']['routing_number'] = \Civi::service('crypto.token')
            ->encrypt($record['fields']['routing_number'], 'CRED');
        } else {
          unset($record['fields']['routing_number']);
        }
      }
    }
    $event->setRecords($records);
  }

  public function onApiPrepare(\Civi\API\Event\PrepareEvent $event): void {

  }
}