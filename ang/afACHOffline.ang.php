<?php

// Angular module afACHOffline.
// @see https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
return [
  'js' => [
    'ang/afACHOffline.js',
    'ang/afACHOffline/*.js',
    'ang/afACHOffline/*/*.js',
  ],
  'css' => [
    'ang/afACHOffline.css',
  ],
  'partials' => [
    'ang/afACHOffline',
  ],
  'requires' => ['crmUi', 'crmUtil', 'ngRoute', 'afCheckout'],
  'settings' => [],
];
