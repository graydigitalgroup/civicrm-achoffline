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
];
