<?php

/**
 * @file
 */

return [
  [
    'name' => 'PaymentProcessorType_ACHOffline',
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'ACHOffline',
        'title' => \CRM_ACHOffline_ExtensionUtil::ts('ACH Offline'),
        'user_name_label' => 'Account (ignored)',
        'password_label' => 'Password (ignored)',
        'url_site_default' => 'https://bitbucket.org/graydigitalgroup/achoffline',
        'url_site_test_default' => 'https://bitbucket.org/graydigitalgroup/achoffline',
        'class_name' => 'Payment_ACHOffline',
        'billing_mode' => 'form',
        'is_recur' => TRUE,
        'payment_instrument_id:name' => 'EFT',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
