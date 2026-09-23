<?php

/**
 * @file
 */

return [
  [
    'name'    => 'CustomGroup_ACH_Data',
    'entity'  => 'CustomGroup',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'name'                 => 'ACH_Processor_Data',
        'title'                => \CRM_ACHOffline_ExtensionUtil::ts('ACH Data'),
        'extends'              => 'Contribution',
        'style'                => 'Inline',
        'collapse_display'     => TRUE,
        'help_pre'             => '',
        'help_post'            => '',
        'weight'               => 14,
        'collapse_adv_display' => TRUE,
        'is_public'            => FALSE,
        'icon'                 => '',
        'table_name'           => 'civicrm_value_ach_data',
      ],
      'match'   => [
        'name',
      ],
    ],
  ],
  [
    'name'    => 'CustomGroup_BankAccount',
    'entity'  => 'CustomField',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'custom_group_id.name' => 'ACH_Processor_Data',
        'name'                 => 'Bank_Account',
        'label'                => \CRM_ACHOffline_ExtensionUtil::ts('Bank Account'),
        'data_type'            => 'EntityReference',
        'html_type'            => 'Autocomplete-Select',
        'weight'               => 7,
        'text_length'          => 255,
        'note_columns'         => 60,
        'note_rows'            => 4,
        'column_name'          => 'contribution_bank_account',
        'fk_entity'            => 'PaymentToken',
        'attributes'           => 'min_input_length=0',
      ],
      'match'   => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
  [
    'name'    => 'CustomField_NSF_Reissued_From',
    'entity'  => 'CustomField',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'custom_group_id.name' => 'ACH_Processor_Data',
        'name'                 => 'NSF_Reissued_From',
        'label'                => \CRM_ACHOffline_ExtensionUtil::ts('NSF Reissued From'),
        'data_type'            => 'Int',
        'html_type'            => 'Text',
        'is_view'              => TRUE,
        'weight'               => 8,
        'column_name'          => 'nsf_reissued_from',
        'help_post'            => \CRM_ACHOffline_ExtensionUtil::ts('When set, this open contribution was reissued because the referenced contribution was reversed for insufficient funds. It blocks the recurring cron from generating the next installment until it is paid or closed.'),
      ],
      'match'   => [
        'name',
        'custom_group_id',
      ],
    ],
  ],
];
