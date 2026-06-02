<?php

namespace Civi\ACHOffline;

use Civi\Api4\Action\Afform\Submit;
use Civi\Api4\PaymentToken;

/**
 * Static helpers for evaluating af-if rules with {entity} placeholders
 *  against submitted Afform values.
 *
 *  Same rule format as af-if and afForm.checkConditions on the client;
 *  the {entity} placeholder lets options be reused across forms that
 *  name their Contribution entity differently.
 */
class ConditionEvaluator {

  /**
   * Evaluate a list of rules against submitted form data.
   *
   * @param array $rules
   *   List of rules in af-if format.
   * @param array $allEntityValues
   *   Submitted values map, typically AfformValidateEvent::getSubmittedValues().
   *   Shape: [entityName => [index => ['fields' => [...], 'joins' => [...]]]].
   * @param array $substitutions
   *   Map of placeholder => replacement to apply to LHS expressions before
   *   evaluation. e.g. ['{entity}' => 'Contribution1'].
   *
   * @return bool
   *   TRUE if all rules pass (or no rules supplied), FALSE otherwise.
   *   On any internal error (malformed rules, etc.) returns TRUE — visibility
   *   rules are UX hints, not security checks; a misconfigured rule must
   *   never block a legitimate submission.
   */
  public static function evaluate(array $rules, array $allEntityValues, array $substitutions = []): bool {
    if (!$rules) {
      return TRUE;
    }
    $resolved = $substitutions ? self::applySubstitutions($rules, $substitutions) : $rules;
    try {
      return Submit::checkAfformConditional($resolved, $allEntityValues);
    }
    catch (\Throwable $e) {
      \Civi::log()->warning(
        'Civi\\ACHOffline\\ConditionEvaluator: evaluation failed ({message}); treating rule as passed.',
        ['message' => $e->getMessage()]
      );
      return TRUE;
    }
  }

  /**
   * Recursively walk the rules array and substitute placeholder strings in
   * the LHS expression of each leaf clause.
   *
   * Leaf clause: [<lhs-string>, <operator>, ...]
   * Group clause: ['OR', [ [...], [...] ]]   // first element is op name,
   *                                          // second is sub-rules array
   */
  public static function applySubstitutions(array $rules, array $substitutions): array {
    $out = [];
    foreach ($rules as $clause) {
      if (!is_array($clause) || count($clause) < 2) {
        $out[] = $clause;
        continue;
      }
      // Group clause: ['OR', [...]] — recurse on sub-rules.
      if (is_array($clause[1])) {
        $out[] = [$clause[0], self::applySubstitutions($clause[1], $substitutions)];
        continue;
      }
      // Leaf clause: substitute LHS only.
      if (is_string($clause[0])) {
        $clause[0] = strtr($clause[0], $substitutions);
      }
      $out[] = $clause;
    }
    return $out;
  }

  /**
   * Public so PaymentToken::validate() can run the same check against
   * submitted values.
   *
   * @param int|null $contactId
   * @param int|null $paymentProcessorId
   *   If NULL, checks across all PayflowPro processors site-wide.
   *   Better to over-show than under-show — empty-token-list UX is
   *   recoverable; silently hiding a valid choice is not.
   *
   * @return bool
   */
  public static function contactHasToken(?int $contactId, ?int $paymentProcessorId): bool {
    if (!$contactId) {
      return FALSE;
    }
    try {
      $query = PaymentToken::get(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->addClause('OR',
          ['expiry_date', 'IS NULL'],
          ['expiry_date', '>', date('Y-m-d')]
        )
        ->selectRowCount();
      if ($paymentProcessorId) {
        $query->addWhere('payment_processor_id', '=', $paymentProcessorId);
      }
      else {
        $query->addWhere('payment_processor_id.payment_processor_type_id:name', '=', 'ACHOffline');
      }
      return $query->execute()->count() > 0;
    }
    catch (\Throwable $e) {
      \Civi::log()->warning(
        'ConditionEvaluator: token lookup failed ({message}); defaulting to FALSE.',
        ['message' => $e->getMessage()]
      );
      return FALSE;
    }
  }

}
