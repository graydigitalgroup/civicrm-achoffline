<?php
use CRM_ACHOffline_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_ACHOffline_Upgrader extends \CRM_Extension_Upgrader_Base {

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  public function upgrade_1000(): bool {
    $this->ctx->log->info('Applying update 1000');
    $ctx = new CRM_Queue_TaskContext();
    CRM_Upgrade_Incremental_Base::addColumn($ctx, 'civicrm_bank_account', 'account_holder', 'varchar(255) COMMENT "The account holder\'s name."');
    CRM_Upgrade_Incremental_Base::addColumn($ctx, 'civicrm_bank_account', 'account_type', 'varchar(255) COMMENT "Is this a checking or savings account."');
    CRM_Upgrade_Incremental_Base::addColumn($ctx, 'civicrm_bank_account', 'payment_token', 'varchar(255) COMMENT "A unique ID used to relate to a contribution as payment."');
    return TRUE;
  }

}
