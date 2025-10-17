<?php
use CRM_ACHOffline_ExtensionUtil as E;

return [
  'name' => 'BankAccount',
  'table' => 'civicrm_bank_account',
  'class' => 'CRM_ACHOffline_DAO_BankAccount',
  'getInfo' => fn() => [
    'title' => E::ts('Bank Account'),
    'title_plural' => E::ts('Bank Accounts'),
    'description' => E::ts('Bank Accounts'),
    'log' => TRUE,
    'label_field' => 'description',
    'add' => '4.3',
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('ID'),
      'add' => '4.3',
      'usage' => ['export'],
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'name' => [
      'title' => E::ts('Name'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Name of the bank account to distinguish when multiple accounts are created.'),
      'add' => '4.3',
    ],
    'created_date' => [
      'title' => E::ts('Date created'),
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'description' => E::ts('The date the accoutn was created.'),
      'add' => '4.3',
    ],
    'modified_date' => [
      'title' => E::ts('Date modified'),
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'description' => E::ts('The date the bank account was last updated on.'),
      'add' => '4.3',
    ],
    'account_number' => [
      'title' => E::ts('Bank Account Number'),
      'sql_type' => 'varchar(20)',
      'input_type' => 'Text',
      'description' => E::ts('The bank account number the funds will come out of.'),
      'add' => '4.3',
    ],
    'routing_number' => [
      'title' => E::ts('Bank Routing Number'),
      'sql_type' => 'varchar(20)',
      'input_type' => 'Text',
      'description' => E::ts('The Bank\'s Routing Number. Normally a 9-digit number.'),
      'add' => '4.3',
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to contact owning this account'),
      'add' => '4.3',
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
  ],
];
