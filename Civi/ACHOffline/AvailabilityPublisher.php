<?php

namespace Civi\ACHOffline;

use Civi\Afform\Event\AfformPrefillEvent;
use Civi\Api4\Action\Afform\Prefill;
use Civi\Api4\Generic\Result;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publishes `achoffline_has_payment_token` onto each Contribution entity in the
 * Afform.prefill response so the client-side conditional filter can
 * decide whether to show the PaymentToken checkout option.
 *
 * Two events:
 *   civi.afform.prefill (priority -10):
 *     Per Contribution — resolve contact, check for tokens, stash.
 *   civi.api.respond (priority 0):
 *     Per API call — inject stashed facts into Afform.prefill responses.
 *
 * Synthetic facts don't survive Submit::preprocessSubmittedValues; the
 * server-side enforcement re-queries directly in CheckoutOption::validate.
 *
 * @service civi.achoffline.availability_publisher
 */
class AvailabilityPublisher extends AutoService implements EventSubscriberInterface {

  private array $factsByRequest = [];

  public static function getSubscribedEvents(): array {
    return [
      'civi.afform.prefill' => ['onAfformPrefill', -10],
      'civi.api.respond' => ['onApiRespond', 0],
    ];
  }

  public function onAfformPrefill(AfformPrefillEvent $event): void {
    if ($event->getEntityType() !== 'Contribution') {
      return;
    }
    $entity = $event->getEntity();
    $contactId = $this->resolveContactIdFromPrefill($event, $entity);
    $processorId = $this->resolveProcessorIdFromPrefill($entity);

    $requestId = spl_object_id($event->getApiRequest());
    $this->factsByRequest[$requestId][$event->getEntityName()] = self::computeFacts($contactId, $processorId);
  }

  public function onApiRespond($event): void {
    $apiRequest = $event->getApiRequest();
    if (!($apiRequest instanceof Prefill)) {
      return;
    }
    $requestId = spl_object_id($apiRequest);
    $facts = $this->factsByRequest[$requestId] ?? NULL;
    unset($this->factsByRequest[$requestId]);

    if (!$facts) {
      return;
    }
    $response = $event->getResponse();
    if (!$response instanceof Result) {
      return;
    }

    // The Result is an ArrayObject — getArrayCopy + exchangeArray is
    // the supported mutation pattern.
    $values = $response->getArrayCopy();

    // Index existing response entries by entity name for quick lookup.
    // Entries are missing from the response when no record was loaded
    // for that entity (e.g. a fresh "create new contribution" form has
    // no Contribution autofill, so the prefill response contains no
    // Contribution1 entry even though the form declares one).
    $indexByName = [];
    foreach ($values as $i => $entry) {
      if (isset($entry['name'])) {
        $indexByName[$entry['name']] = $i;
      }
    }

    foreach ($facts as $entityName => $entityFacts) {
      if (!isset($indexByName[$entityName])) {
        // Entity has no response entry — append one. The first record
        // is created from scratch; downstream JS will treat fields not
        // in our stashed facts as undefined, which is correct.
        $values[] = [
          'name' => $entityName,
          'values' => [['fields' => $entityFacts, 'joins' => []]],
        ];
        continue;
      }

      $i = $indexByName[$entityName];
      // Existing entry. Ensure values[0] exists, then merge our fields
      // into every record (matches per-Contribution semantics — every
      // Contribution that resolved to this contact/processor gets the
      // same facts).
      if (empty($values[$i]['values'])) {
        $values[$i]['values'] = [['fields' => [], 'joins' => []]];
      }
      foreach ($values[$i]['values'] as $idx => $record) {
        if (!isset($values[$i]['values'][$idx]['fields'])) {
          $values[$i]['values'][$idx]['fields'] = [];
        }
        foreach ($entityFacts as $field => $value) {
          $values[$i]['values'][$idx]['fields'][$field] = $value;
        }
      }
    }
    $response->exchangeArray($values);
  }

  protected function resolveContactIdFromPrefill(AfformPrefillEvent $event, array $entity): ?int {
    $ref = $entity['data']['contact_id'] ?? NULL;
    if (is_numeric($ref)) {
      return (int) $ref;
    }
    if (is_string($ref) && $ref !== '') {
      $ids = $event->getEntityIds($ref);
      $id = $ids[0] ?? NULL;
      return is_numeric($id) ? (int) $id : NULL;
    }
    return NULL;
  }

  // -------------------------------------------------------------------------
  // Processor id resolution
  // -------------------------------------------------------------------------

  protected function resolveProcessorIdFromPrefill(array $entity): ?int {
    $val = $entity['data']['afform_payment_processor'] ?? NULL;
    return is_numeric($val) ? (int) $val : NULL;
  }

  public static function computeFacts(?int $contactId, ?int $processorId): array {
    if (!$contactId) {
      return ['achoffline_has_payment_token' => FALSE];
    }
    return [
      'achoffline_has_payment_token' => ConditionEvaluator::contactHasToken($contactId, $processorId),
    ];
  }

}