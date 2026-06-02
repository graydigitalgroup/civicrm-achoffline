<?php

namespace Civi\ACHOffline;

use Civi\Checkout\AfformCheckoutOptionInterface;
use Civi\Core\Service\AutoService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the PaymentToken option's `if:` rules to <af-field name="checkout_option">
 * defn.options entries after AfformMetadataInjector has populated them.
 *
 * Runs at priority -100 (AfformMetadataInjector is at the default 0, so
 * lower priority means we run later in Symfony EventDispatcher).
 *
 * @service civi.payflowpro.option_defn_injector
 */
class OptionDefnInjector extends AutoService implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      // AfformMetadataInjector subscribes at the default priority (0).
      // We need defn.options to already be merged by it before we walk
      // them, so we subscribe at a lower priority — Symfony EventDispatcher
      // calls lower-priority listeners later.
      'hook_civicrm_alterAngular' => ['preprocess', -100],
    ];
  }

  /**
   * @param \Civi\Core\Event\GenericHookEvent $e
   * @see CRM_Utils_Hook::alterAngular()
   */
  public function preprocess($e): void {
    $rulesByOption = $this->collectRulesByOption();
    if (!$rulesByOption) {
      return;
    }

    $changeSet = \Civi\Angular\ChangeSet::create('checkoutOptionConditionals')
      ->alterHtml(';\\.aff\\.html$;', function ($doc, $path) use ($rulesByOption) {
        foreach (pq('af-field[name="checkout_option"]', $doc) as $afField) {
          /** @var \DOMElement $afField */
          $this->amendField($afField, $rulesByOption);
        }
      });
    $e->angular->add($changeSet);
  }

  /**
   * Read the field's defn, attach `if:` clauses to any option whose key
   * has a registered visible_when, write defn back. No-op if the field
   * has no options or no matches.
   *
   * @param \DOMElement $afField
   * @param array<string, array> $rulesByOption
   */
  protected function amendField(\DOMElement $afField, array $rulesByOption): void {
    $existing = trim(pq($afField)->attr('defn') ?: '');
    // If the markup author wrote a non-object defn, leave it alone — same
    // posture as AfformMetadataInjector::setFieldMetadata.
    if ($existing && $existing[0] !== '{') {
      return;
    }

    $rawDefn = $existing ? \CRM_Utils_JS::getRawProps($existing) : [];
    if (empty($rawDefn['options'])) {
      // No options on this field — nothing to amend. Shouldn't happen for
      // checkout_option after AfformMetadataInjector has merged metadata,
      // but be defensive in case the field is declared with no options.
      return;
    }

    $options = \CRM_Utils_JS::decode($rawDefn['options']);
    if (!is_array($options)) {
      return;
    }

    // Resolve {entity} now: it's shorthand for whatever the containing
    // af-fieldset named its entity, which is always known at markup-process
    // time. By baking it in here we avoid leaking a CheckoutOption-specific
    // placeholder convention into generic afField code.
    $entityName = pq($afField)->parents('[af-fieldset]')->attr('af-fieldset');
    if (!$entityName) {
      return;
    }

    $changed = FALSE;
    foreach ($options as &$opt) {
      $key = $opt['id'] ?? $opt['name'] ?? NULL;
      if ($key && !empty($rulesByOption[$key]) && empty($opt['if'])) {
        $opt['if'] = ConditionEvaluator::applySubstitutions($rulesByOption[$key], ['{entity}' => $entityName]);
        $changed = TRUE;
      }
    }
    unset($opt);

    if (!$changed) {
      return;
    }
    $rawDefn['options'] = \CRM_Utils_JS::encode($options);
    pq($afField)->attr('defn', htmlspecialchars(\CRM_Utils_JS::writeObject($rawDefn), ENT_COMPAT));
  }

  /**
   * Map of option key => visible_when rules for every CheckoutOption that
   * opted into HasConditionalVisibilityTrait.
   *
   * Sourced from AfformCheckoutOptionInterface::getAfformSettings, since
   * that's where the trait surfaces the rules. testMode is irrelevant for
   * visibility — rules don't differ between live and test for any current
   * option, and if that ever changes the long-term optionsCallback path
   * is the better place to handle it.
   *
   * @return array<string, array>
   */
  protected function collectRulesByOption(): array {
    $out = [];
    /** @var \Civi\Checkout $checkout */
    $checkout = \Civi::service('civi.checkout');
    foreach ($checkout->getOptions() as $key => $option) {
      if (!$option instanceof AfformCheckoutOptionInterface) {
        continue;
      }
      $settings = $option->getAfformSettings($checkout->isTestMode());
      if (!empty($settings['visible_when'])) {
        $out[$key] = $settings['visible_when'];
      }
    }
    return $out;
  }

}