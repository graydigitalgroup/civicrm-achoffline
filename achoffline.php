<?php

require_once 'achoffline.civix.php';

use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function achoffline_civicrm_config(&$config): void {
  _achoffline_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function achoffline_civicrm_install(): void {
  _achoffline_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function achoffline_civicrm_enable(): void {
  _achoffline_civix_civicrm_enable();
}
