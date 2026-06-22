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
    ];
  }

  /**
   * Grant access to BankAccount and PaymentToken actions so the core
   * authorization passes and civi.api4.authorizeRecord can then enforce
   * the ownership rules at the record level.
   */
  public function onAuthorize(AuthorizeEvent $e): void {
    if ($e->getEntityName() !== 'BankAccount') {
      return;
    }
    if (!in_array($e->getActionName(), ['get', 'delete', 'update', 'autocomplete'])) {
      return;
    }

    $loggedInContactID = \CRM_Core_Session::getLoggedInContactID();

    // If no logged in contact, access is always denied
    if (empty($loggedInContactID)) {
      $e->setAuthorized(FALSE);
      $e->stopPropagation();
      return;
    }

    // Admins with the permission can see all bank accounts.
    if (\CRM_Core_Permission::check('administer bank accounts')) {
      // Explicitly grant access to ALL bank accounts.
      $e->authorize();
      $e->stopPropagation();
      return;
    }

    if ($e->getActionName() === 'autocomplete') {
      // For autocomplete action we add a filter instead of a where clause
      $e->getApiRequest()->addFilter('contact_id', $loggedInContactID);
      $e->authorize();
      $e->stopPropagation();
      return;
    }

    // Get the requested contact ID from the API request
    $cid = NULL;
    foreach ($e->getApiRequest()->getWhere() as $where) {
      if ($where[0] === 'contact_id') {
        if ($where[1] === '=') {
          $cid = $where[2];
          if ($cid === 'user_contact_id') {
            $cid = $loggedInContactID;
          }
          $cid = (int) $cid;
          break;
        }
      }
      elseif ($where[0] === 'id') {
        // If we specify one or more PaymentToken IDs we don't need to decode the
        //   where clause for ID, we just need to make sure we filter by contact_id.
        $requestedByID = TRUE;
      }
    }

    if (!empty($requestedByID) && empty($cid)) {
      // Since we requested by ID but didn't specify a contact ID
      //   we add a where clause restricting to the current logged in contact.
      $e->getApiRequest()->addWhere('contact_id', '=', $loggedInContactID);
      $e->authorize();
      $e->stopPropagation();
      return;
    }

    if (!$cid) {
      $e->setAuthorized(FALSE);
      // There is no contact ID in the API request.
      // Set authorize to FALSE but allow other subscribers to override that.
      return;
    }

    // If API request contactID matches the logged in contact ID, grant access.
    if ($loggedInContactID === $cid) {
      // Explicitly grant access to payment tokens for the requested/logged in contact.
      $e->authorize();
      $e->stopPropagation();
    }

    // If we get to here we have not explicitly authorized access to payment tokens
    // but another subscriber might authorize access.
    // Also, "Administer CiviCRM" will have access, but they have access to the full database anyway..
  }
}