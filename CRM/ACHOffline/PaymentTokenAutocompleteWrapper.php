<?php

class CRM_ACHOffline_PaymentTokenAutocompleteWrapper {

  public function fromApiInput($apiRequest) {
    if (is_array($apiRequest)) {
      $apiRequest['params']['select'][] = 'masked_account_number';
      $apiRequest['params']['select'][] = 'ACH_Token_Data.label';
    }
    else {

      // Clear the input so the default label search doesn't fire and
      // return nothing when no term has been typed.
      $apiRequest->setInput('');
      // Allow results to be returned with no input typed.
      if (method_exists($apiRequest, 'setMinInputLength')) {
        $apiRequest->setMinInputLength(0);
      }

      $input = method_exists($apiRequest, 'getInput') ? $apiRequest->getInput() : '';

      if (!empty($input) && !is_numeric($input)) {
        $apiRequest->addWhere('masked_account_number', 'CONTAINS', $input);
      }
    }
    return $apiRequest;
  }

  /**
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \CRM_Core_Exception
   */
  public function toApiOutput($apiRequest, $result) {
    $ids = [];
    foreach ($result as $row) {
      $ids[] = $row['id'];
    }

    if (empty($ids)) {
      return $result;
    }

    $today = date('Y-m-d');

    $tokens = \Civi\Api4\PaymentToken::get(FALSE)
      ->addSelect('id', 'masked_account_number', 'ACH_Token_Data.label', 'expiry_date')
      ->addWhere('id', 'IN', $ids)
      // Filter out expired tokens using nested OR syntax.
      ->setWhere([
       ['id', 'IN', $ids],
       ['OR', [
         ['expiry_date', 'IS NULL'],
         ['expiry_date', '>=', $today],
       ]],
     ])
      ->execute()
      ->indexBy('id');

    foreach ($result as $index => $row) {
      // If the token isn't in our filtered set it was expired — remove it.
      if (!isset($tokens[$row['id']])) {
        $result->offsetUnset($index);
        continue;
      }

      $token = $tokens[$row['id']];
      $row['label'] = ($token['ACH_Token_Data.label'] ?? '')
        ?: trim($token['masked_account_number'] ?? '')
          ?: "Token #{$row['id']}";
      $result->offsetSet($index, $row);
    }

    return $result;
  }

}