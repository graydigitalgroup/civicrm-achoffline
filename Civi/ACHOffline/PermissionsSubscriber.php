<?php

namespace Civi\ACHOffline;

use Civi\API\Event\AuthorizeEvent;
use Civi\Api4\BankAccount;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\PaymentToken;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles permissions for the BankAccount and PaymentToken entities.
 *
 * @service achoffline.permissions_subscriber
 * @internal
 */
class PermissionsSubscriber extends AutoService implements EventSubscriberInterface {

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents(): array {
    return [
      'civi.api.authorize'       => ['onAuthorize', 500],
      'civi.api4.authorizeRecord' => ['onAuthorizeRecord', 500],
    ];
  }

  /**
   * Grant access to BankAccount and PaymentToken actions so the core
   * authorization passes and civi.api4.authorizeRecord can then enforce
   * the ownership rules at the record level.
   */
  public function onAuthorize(AuthorizeEvent $event): void {
    $apiRequest = $event->getApiRequest();

    if (!($apiRequest instanceof AbstractAction)) {
      return;
    }

    $entity = $apiRequest->getEntityName();

    if (!in_array($entity, ['BankAccount', 'PaymentToken'], TRUE)) {
      return;
    }

    // Allow the action through so authorizeRecord can enforce
    // ownership rules at the individual record level.
    if (\CRM_Core_Permission::check('administer bank accounts')
      || \CRM_Core_Permission::check('edit own bank accounts')) {
      $event->authorize();
      $event->stopPropagation();
    }
  }

  /**
   * Enforce ownership rules on BankAccount and PaymentToken entities.
   */
  public function onAuthorizeRecord(\Civi\Api4\Event\AuthorizeRecordEvent $event): void {
    $entity = $event->getEntityName();

    if (!in_array($entity, ['BankAccount', 'PaymentToken'], TRUE)) {
      return;
    }

    // Staff with administer permission can manage any record.
    if (\CRM_Core_Permission::check('administer bank accounts')) {
      $event->setAuthorized(TRUE);
      return;
    }

    if (!\CRM_Core_Permission::check('edit own bank accounts')) {
      $event->setAuthorized(FALSE);
      return;
    }

    $loggedInContactID = \CRM_Core_Session::getLoggedInContactID();

    if (empty($loggedInContactID)) {
      $event->setAuthorized(FALSE);
      return;
    }

    $action = $event->getActionName();
    $record = $event->getRecord();

    if ($action === 'create') {
      $event->setAuthorized(
        isset($record['contact_id'])
        && (int) $record['contact_id'] === (int) $loggedInContactID
      );
      return;
    }

    if (in_array($action, ['get', 'update', 'delete'], TRUE)) {
      $ownerContactId = $this->resolveContactId($entity, $record['id'] ?? NULL);
      $event->setAuthorized(
        $ownerContactId !== NULL
        && (int) $ownerContactId === (int) $loggedInContactID
      );
      return;
    }

    $event->setAuthorized(FALSE);
  }

  /**
   * Fetch the contact_id for a BankAccount or PaymentToken record.
   */
  private function resolveContactId(string $entity, ?int $id): ?int {
    if (empty($id)) {
      return NULL;
    }

    if ($entity === 'BankAccount') {
      $record = BankAccount::get(FALSE)
        ->addSelect('contact_id')
        ->addWhere('id', '=', $id)
        ->execute()
        ->first();
    }
    else {
      $record = PaymentToken::get(FALSE)
        ->addSelect('contact_id')
        ->addWhere('id', '=', $id)
        ->execute()
        ->first();
    }

    return isset($record['contact_id']) ? (int) $record['contact_id'] : NULL;
  }
}