<?php

/**
 * @file
 */

return [
  [
    'name' => 'CustomGroup_ACH_Data',
    'entity' => 'CustomGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'ACH_Processor_Data',
        'title' => \CRM_ACHOffline_ExtensionUtil::ts('ACH Data'),
        'extends' => 'Contribution',
        'style' => 'Inline',
        'collapse_display' => TRUE,
        'help_pre' => '',
        'help_post' => '',
        'weight' => 14,
        'collapse_adv_display' => TRUE,
        'is_public' => FALSE,
        'icon' => '',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
  [
    'name' => 'CustomGroup_BankAccount',
    'entity' => 'CustomField',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'custom_group_id.name' => 'ACH_Processor_Data',
        'name' => 'Bank_Account',
        'label' => \CRM_ACHOffline_ExtensionUtil::ts('Bank Account'),
        'data_type' => 'EntityReference',
        'html_type' => 'Autocomplete-Select',
        'weight' => 7,
        'text_length' => 255,
        'note_columns' => 60,
        'note_rows' => 4,
        'column_name' => 'contribution_bank_account',
        'fk_entity' => 'BankAccount',
      ],
      'match' => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];
