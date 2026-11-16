<?php

namespace Civi\ACHOffline;

use Civi\Api4\Contribution;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use CRM_ACHOffline_BAO_NsfReversal as NsfReversal;
use CRM_ACHOffline_ExtensionUtil as E;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the "Reverse (NSF)" task and single-contribution link.
 *
 * @service achoffline.reverse_nsf_links_subscriber
 * @internal
 */
class ReverseNsfLinksSubscriber extends AutoService implements EventSubscriberInterface {

  private const PATH = 'civicrm/contribute/reverse-nsf';

  public static function getSubscribedEvents(): array {
    return [
      'hook_civicrm_searchKitTasks' => ['onSearchKitTasks'],
      'civi.api4.getLinks' => ['onGetLinks'],
      'hook_civicrm_links' => ['onLinks'],
    ];
  }

  public function onSearchKitTasks(GenericHookEvent $event): void {
    if ($event->checkPermissions && !\CRM_Core_Permission::check('edit contributions')) {
      return;
    }
    $event->tasks['Contribution']['achoffline_reverse_nsf'] = [
      'title' => E::ts('Reverse (NSF / Returned Payment)'),
      'icon' => 'fa-undo',
      'number' => '> 0',
      'crmPopup' => [
        'path' => "'" . self::PATH . "'",
        'query' => '{reset: 1}',
        'data' => "{ids: ids.join(',')}",
      ],
    ];
  }

  public function onGetLinks(GenericHookEvent $event): void {
    if ($event->entity !== 'Contribution') {
      return;
    }
    $event->links[] = [
      'ui_action' => 'reverse_nsf',
      'api_action' => 'update',
      'entity' => 'Contribution',
      'path' => self::PATH . '?reset=1&id=[id]',
      'text' => E::ts('Reverse (NSF)'),
      'icon' => 'fa-undo',
      'target' => 'crm-popup',
      'weight' => 90,
      'conditions' => [
        ['contribution_status_id:name', 'NOT IN', ['Cancelled', 'Chargeback', 'Refunded', 'Failed']],
        ['ACH_Processor_Data.Bank_Account', 'IS NOT EMPTY'],
      ],
    ];
  }

  public function onLinks(GenericHookEvent $event): void {
    if ($event->op !== 'contribution.selector.row' || empty($event->objectId)
      || !\CRM_Core_Permission::check('edit contributions')) {
      return;
    }
    $contribution = Contribution::get(FALSE)
      ->addSelect('contribution_status_id:name', 'ACH_Processor_Data.Bank_Account')
      ->addWhere('id', '=', (int) $event->objectId)
      ->execute()
      ->first();
    if (!$contribution || NsfReversal::getIneligibleReason($contribution) !== NULL) {
      return;
    }
    $event->links[] = [
      'name' => E::ts('Reverse (NSF)'),
      'url' => self::PATH,
      'qs' => 'reset=1&id=%%id%%',
      'title' => E::ts('Reverse (NSF / Returned Payment)'),
      'class' => 'crm-popup',
      'weight' => 90,
    ];
  }

}
