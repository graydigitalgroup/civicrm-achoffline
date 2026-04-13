<?php

namespace Civi\ACHOffline;

use Civi\API\Event\PrepareEvent;
use Civi\API\Event\RespondEvent;
use Civi\Api4\Generic\AutocompleteAction;
use Civi\Api4\PaymentProcessor;
use Civi\Api4\PaymentToken;
use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Preprocess Autocomplete requests
 *
 * @service
 * @internal
 *
 */
class AutocompleteSubscriber extends AutoService implements EventSubscriberInterface {

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents(): array {
    return [
      '&civi.search.autocompleteDefault' => ['onAutocompleteDefault', 100],
      'civi.api.prepare' => ['onApiPrepare', 150],
      'civi.api.respond' => ['onApiRespond', 100],
    ];
  }

  /**
   * Apply filters for the BankAccount CustomField. Sets the current contact if passed in.
   *
   * @param \Civi\API\Event\PrepareEvent $event
   *
   * @return void
   */
  public function onApiPrepare(PrepareEvent $event): void {
    $apiRequest = $event->getApiRequest();
    if (!($apiRequest instanceof AutocompleteAction)
      || $apiRequest->getEntityName() !== 'PaymentToken') {
      return;
    }
    // Read contact_id from filters injected by JS inside params.
    $filters   = $apiRequest->getFilters();
    $contactId = isset($filters['contact_id']) ? (int) $filters['contact_id'] : NULL;
    if (!empty($contactId)) {
      $apiRequest->addFilter('contact_id', $contactId);
    }
  }

  /**
   * Adds where clause to return only ACH payment processors and exclude
   * expired tokens.
   *
   * @param array $savedSearch
   * @param string|null $formName
   * @param string|null $fieldName
   * @param array $filters
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function onAutocompleteDefault(array &$savedSearch, ?string $formName, ?string $fieldName, array $filters): void {
    if (($savedSearch['api_entity'] ?? '') !== 'PaymentToken') {
      return;
    }

    if ($fieldName !== 'ACH_Processor_Data.Bank_Account') {
      return;
    }

    $achPaymentProcessors = PaymentProcessor::get(FALSE)
      ->addWhere('class_name', '=', 'Payment_ACHOffline')
      ->execute();
    $achProcessors = [];
    foreach($achPaymentProcessors as $processor) {
      $achProcessors[] = $processor['id'];
    }
    if (0 < count($achProcessors)) {
      $savedSearch['api_params']['where'][] = ['payment_processor_id', 'IN', $achProcessors];
    }

    $today = date('Y-m-d');
    $savedSearch['api_params']['where'][] = ['OR', [
      ['expiry_date', 'IS NULL'],
      ['expiry_date', '>=', $today],
    ]];
  }

  /**
   * Handles modifying the output for the autocomplete.
   *
   * @param \Civi\API\Event\RespondEvent $event
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function onApiRespond(RespondEvent $event): void {
    $apiRequest = $event->getApiRequest();

    if (!($apiRequest instanceof AutocompleteAction)
      || $apiRequest->getEntityName() !== 'PaymentToken') {
      return;
    }

    $result = $event->getResponse();

    $ids = [];
    foreach ($result as $row) {
      $ids[] = $row['id'];
    }

    if (empty($ids)) {
      return;
    }

    $tokens = PaymentToken::get(FALSE)
      ->addSelect('id', 'masked_account_number')
      ->addWhere('id', 'IN', $ids)
      ->execute()
      ->indexBy('id');

    $newValues = [];
    foreach ($result as $row) {
      $token = $tokens[$row['id']] ?? NULL;

      if (empty($token)) {
        continue;
      }

      $row['label'] = trim($token['masked_account_number'] ?? '')
          ?: "Token #{$row['id']}";

      $newValues[] = $row;
    }

    // Replace the result values and update the counts to match.
    $result->exchangeArray($newValues);
  }
}