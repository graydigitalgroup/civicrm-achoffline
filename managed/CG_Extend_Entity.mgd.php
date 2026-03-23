<?php

return [
  [
    'name'    => 'cg_extend_objects:PaymentToken',
    'entity'  => 'OptionValue',
    'cleanup' => 'unused',
    'update'  => 'always',
    'params'  => [
      'version' => 4,
      'values'  => [
        'option_group_id.name' => 'cg_extend_objects',
        'label'                => ts('Payment Tokens'),
        'value'                => 'PaymentToken',
        'name'                 => 'civicrm_payment_token',
        'is_reserved'          => TRUE,
        'is_active'            => TRUE,
      ],
      'match'   => ['option_group_id', 'name'],
    ],
  ],
];