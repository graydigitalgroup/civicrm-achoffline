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

/**
 * Implements hook_civicrm_buildForm().
 */
function achoffline_civicrm_buildForm(string $formName, \CRM_Core_Form &$form): void {
  if (!in_array($formName, [
    'CRM_Contribute_Form_Contribution',
    'CRM_Contribute_Form_AdditionalInfo',
  ])) {
    return;
  }

  CRM_Core_Resources::singleton()
    ->addScriptFile(E::LONG_NAME, 'js/ach-autocomplete.js', 10, 'page-header')
    ->addStyleFile(E::LONG_NAME, 'css/ach-autocomplete.css', 10, 'page-header');
}

/**
 * Implements hook_civicrm_searchKitTasks().
 *
 * Adds a bulk "Reverse (NSF)" task to Contribution search displays. It runs the
 * Contribution.reverseNsf API action against the selected rows.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_searchKitTasks
 */
function achoffline_civicrm_searchKitTasks(array &$tasks, bool $checkPermissions, ?int $userID): void {
  $tasks['Contribution']['achoffline_reverse_nsf'] = [
    'module'   => 'crmSearchTasks',
    'title'    => E::ts('Reverse (NSF / Returned Payment)'),
    'icon'     => 'fa-undo',
    'number'   => '> 0',
    'apiBatch' => [
      'action'     => 'reverseNsf',
      'params'     => NULL,
      'confirmMsg' => E::ts('Reverse the %1 selected ACH contributions? Each is cancelled and reissued as an open contribution (plus any configured NSF fee).'),
      'runMsg'     => E::ts('Reversing %1 contributions...'),
      'successMsg' => E::ts('Reversed %1 contributions.'),
      'errorMsg'   => E::ts('An error occurred while reversing contributions.'),
    ],
  ];
}

/**
 * Implements hook_civicrm_permission().
 *
 * Define BankAccount permissions.
 */
function achoffline_civicrm_permission(&$permissions) {
  $permissions['edit own bank accounts'] = [
    'label'       => E::ts('ACHOffline: Edit Own Bank Accounts'),
    'description' => E::ts('Allow contacts to manage their own saved bank accounts.'),
  ];
  $permissions['administer bank accounts'] = [
    'label'       => E::ts('ACHOffline: Administer Bank Accounts'),
    'description' => E::ts('Allow staff to manage bank accounts for any contact.'),
  ];
}