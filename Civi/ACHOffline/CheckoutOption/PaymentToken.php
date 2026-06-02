<?php

namespace Civi\ACHOffline\CheckoutOption;

use Civi\Afform\Event\AfformValidateEvent;
use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Checkout\CheckoutOptionUtils;
use Civi\Checkout\CheckoutSession;
use Civi\ACHOffline\AvailabilityPublisher;
use Civi\ACHOffline\ConditionEvaluator;
use CRM_ACHOffline_ExtensionUtil as E;
use CRM_Core_Session;
use CRM_Utils_Request;

class PaymentToken extends ACHOfflineCheckoutOptionBase {

  public const string NAME = 'PaymentToken';

  public function getName(): string {
    return self::NAME;
  }

  public function getLabel(): string {
    return E::ts('Use Existing Bank Account');
  }

  public function getFrontendLabel(): string {
    return E::ts('Use Existing Bank Account');
  }

  public function getAfformSettings(bool $testMode): array {
    return [
     'template'             => '~/afACHOffline/achoffline-payment-token-checkout.html',
     'payment_processor_id' => $this->getPaymentProcessorId(),
     'visible_when' => $this->getVisibleWhen(),
   ];
  }

  public function getAfformModule(): ?string {
    return 'afACHOffline';
  }

  public function getVisibleWhen(): array {
    return [
      ['{entity}[0][fields][achoffline_has_payment_token]', 'IS NOT EMPTY'],
    ];
  }

  /**
   * Server-side enforcement of the visibility rule.
   *
   * The `_has_payment_token` synthetic field is stripped from
   * submitted values by Submit::preprocessSubmittedValues(), so the
   * trait's validateVisibility() can't see it on validate. Instead,
   * we re-run the same query against the submitted contact for any
   * Contribution that selected this option.
   *
   * Note that token-id validation already enforces the security
   * boundary: a user without tokens for this contact cannot submit a
   * valid `payment_token` value. This check is mainly for clean error
   * messaging in the unusual case where the user spoofs option
   * selection without a corresponding token.
   */
  public function validate(AfformValidateEvent $event): void {
    $values = $event->getSubmittedValues();
    $contributions = array_filter(
      $event->getFormDataModel()->getEntities(),
      fn($entity) => $entity['type'] === 'Contribution'
    );

    foreach ($contributions as $contribution) {
      $entityName = $contribution['name'];
      foreach ($values[$entityName] ?? [] as $i => $record) {
        if (($record['fields']['checkout_option'] ?? NULL) !== self::NAME) {
          continue;
        }
        // Re-derive the contact id the same way the publisher did, then
        // re-check the token exists. We don't trust _has_payment_token
        // from the submitted payload — it was a UX hint, never a fact.
        $contactId = $this->resolveSubmittedContactId($entityName, $record, $values);
        $processorId = $this->resolveSubmittedProcessorId($contribution, $record);
        $facts = AvailabilityPublisher::computeFacts($contactId, $processorId);

        $augmented = $values;
        foreach ($facts as $name => $value) {
          $augmented[$entityName][$i]['fields'][$name] = $value;
        }

        if (!ConditionEvaluator::evaluate($this->getVisibleWhen(), $augmented, ['{entity}' => $entityName])) {
          $event->setError(E::ts('Cannot use saved bank account: no payment token found for this contact.'));
        }
      }
    }
  }

  private function resolveSubmittedContactId(array $entity, array $record, array $submitted): ?int {
    $direct = $record['fields']['contact_id'] ?? NULL;
    if (is_numeric($direct)) {
      return (int) $direct;
    }
    $ref = $entity['data']['contact_id'] ?? NULL;
    if (is_numeric($ref)) {
      return (int) $ref;
    }
    if (is_string($ref) && $ref !== '') {
      $referenced = $submitted[$ref][0]['fields']['id'] ?? NULL;
      return is_numeric($referenced) ? (int) $referenced : NULL;
    }
    return NULL;
  }

  private function resolveSubmittedProcessorId(array $entity, array $record): ?int {
    $submitted = $record['fields']['afform_payment_processor'] ?? NULL;
    if (is_numeric($submitted)) {
      return (int) $submitted;
    }
    $declared = $entity['data']['afform_payment_processor'] ?? NULL;
    return is_numeric($declared) ? (int) $declared : NULL;
  }

}