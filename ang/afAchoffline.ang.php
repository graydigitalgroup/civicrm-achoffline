<?php

// Angular module crmAchoffline.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
return [
  'js' => [
    'ang/afAchoffline.js',
    'ang/afAchoffline/*.js',
    'ang/afAchoffline/*/*.js',
  ],
  'css' => [
    'ang/afAchoffline.css',
  ],
  'partials' => ['ang/afAchoffline'],
  'requires' => ['afCheckout'],
  'settings' => [],
  'exports' => [
    'af-achoffline-payment-token-checkout' => 'E',
  ],
];
