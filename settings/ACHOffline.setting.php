<?php

use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Settings for the NSF / returned-payment reversal action.
 *
 * The fee is optional: leave the amount blank (or 0) and a reversal simply
 * reissues the original line items with nothing added. Set an amount to have the
 * reissued contribution carry an extra NSF-fee line item.
 */
return [
  'achoffline_nsf_fee_amount' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_fee_amount',
    'type' => 'String',
    'title' => E::ts('NSF Fee Amount'),
    'html_type' => 'text',
    'default' => '',
    'description' => E::ts('Amount to add as an NSF / returned-payment fee line item on the reissued contribution. Leave blank for no fee.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 10]],
  ],
  'achoffline_nsf_fee_financial_type_id' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_fee_financial_type_id',
    'type' => 'Integer',
    'title' => E::ts('NSF Fee Financial Type'),
    'html_type' => 'entity_reference',
    'entity_reference_options' => [
      'entity' => 'FinancialType',
      'key' => 'id',
    ],
    'default' => NULL,
    'description' => E::ts('Financial type for the NSF fee line item. If not set, the original contribution\'s financial type is used.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 11]],
  ],
  'achoffline_nsf_fee_label' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_fee_label',
    'type' => 'String',
    'title' => E::ts('NSF Fee Label'),
    'html_type' => 'text',
    'default' => 'NSF / Returned Payment Fee',
    'description' => E::ts('Line item label used for the NSF fee.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 12]],
  ],
  'achoffline_nsf_reversal_status' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_reversal_status',
    'type' => 'String',
    'title' => E::ts('Reversed Contribution Status'),
    'html_type' => 'select',
    'pseudoconstant' => [
      'callback' => 'CRM_ACHOffline_BAO_NsfReversal::getReversalStatusOptions',
    ],
    'default' => 'Cancelled',
    'description' => E::ts('Status to set on the original contribution when it is reversed. Both options let core unwind the recorded financials.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 13]],
  ],
  'achoffline_nsf_repeat_return_fee' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_repeat_return_fee',
    'type' => 'Boolean',
    'title' => E::ts('NSF Fee on Repeat Returns'),
    'html_type' => 'checkbox',
    'default' => 0,
    'description' => E::ts('Add the NSF fee when a reissued contribution is returned again. When off, a repeat return keeps the earlier fee and adds none.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 14]],
  ],
  'achoffline_nsf_max_redrafts' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_max_redrafts',
    'type' => 'Integer',
    'title' => E::ts('ACH Redrafts per Returned Payment'),
    'html_type' => 'text',
    'default' => 1,
    'description' => E::ts('How many times a returned payment is reissued with its bank account so that ACH drafts it again. After that, the reissue has no bank account and must be collected another way. Set to 0 to never redraft.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 15]],
  ],
  'achoffline_nsf_hold_recur' => [
    'group' => 'achoffline',
    'name' => 'achoffline_nsf_hold_recur',
    'type' => 'Boolean',
    'title' => E::ts('Hold Recurring Schedule While a Reissue Is Owed'),
    'html_type' => 'checkbox',
    'default' => 1,
    'description' => E::ts('Do not create the next recurring installment while an NSF reissue on the same recurring contribution is Pending or Partially paid.'),
    'is_domain' => 1,
    'is_contact' => 0,
    'settings_pages' => ['achoffline' => ['weight' => 16]],
  ],
];
