<?php

use CRM_ACHOffline_ExtensionUtil as E;

/**
 * @file
 * Scheduled job that drives ACH Offline recurring installments.
 *
 * ACHOffline is an offline processor, so nothing else creates or advances its
 * recurring contributions. Core's Job.run_payment_cron dispatches to
 * Payment_ACHOffline::handlePaymentCron() for each active instance; scoping by
 * the (stable) processor TYPE name 'ACHOffline' — matched against
 * civicrm_payment_processor_type.name — keeps this from touching any other
 * payment processor. Core runs it in both live and test mode.
 */

return [
  [
    'name'    => 'Job_ACHOfflinePaymentCron',
    'entity'  => 'Job',
    'cleanup' => 'unused',
    // Respect any admin changes (frequency, active toggle) after install.
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'name'          => 'ACH Offline: Process recurring installments',
        'description'   => E::ts('Creates and advances due ACH Offline recurring contributions via handlePaymentCron().'),
        'run_frequency' => 'Daily',
        'api_entity'    => 'Job',
        'api_action'    => 'run_payment_cron',
        'parameters'    => 'processor_name=ACHOffline',
        'is_active'     => TRUE,
      ],
      'match'   => ['name'],
    ],
  ],
];
