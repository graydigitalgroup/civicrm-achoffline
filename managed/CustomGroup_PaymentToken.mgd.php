<?php

return [
  [
    'name' => 'CustomGroup_ACH_Token_Data',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name'                 => 'ACH_Token_Data',
        'title'                => \CRM_ACHOffline_ExtensionUtil::ts('ACH Token Data'),
        'extends'              => 'PaymentToken',
        'style'                => 'Inline',
        'collapse_display'     => TRUE,
        'collapse_adv_display' => TRUE,
        'is_public'            => FALSE,
        'help_pre'             => '',
        'help_post'            => '',
        'icon'                 => '',
        'table_name'           => 'civicrm_value_ach_token_data'
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomField_ACH_Token_Label',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'ACH_Token_Data',
        'name'                 => 'label',
        'label'                => \CRM_ACHOffline_ExtensionUtil::ts('Label'),
        'data_type'            => 'String',
        'html_type'            => 'Text',
        'is_required'          => TRUE,
        'is_searchable'        => TRUE,
        'weight'               => 1,
        'text_length'          => 255,
        'column_name'          => 'label',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];