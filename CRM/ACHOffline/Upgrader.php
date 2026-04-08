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

  /**
   * Also run on any upgrade in case the table is missing on existing installs.
   *
   * @return TRUE on success
   * @throws \CRM_Core_Exception
   */
  public function upgrade_1001(): bool {
    $this->ctx->log->info('Ensuring ACH custom tables exist.');
    $this->ensureCustomTable('ACH_Token_Data');
    $this->ensureCustomTable('ACH_Processor_Data');
    return TRUE;
  }

  /**
   * Change data type for account information.
   *
   * @returns TRUE on success
   * @throws \CRM_Core_Exception
   */
  public function upgrade_1002(): bool {
    $this->ctx->log->info('Changing account_number column to TEXT');
    CRM_Upgrade_Incremental_Base::alterColumn($this->ctx, 'civicrm_bank_account', 'account_number', 'TEXT NOT NULL' );

    $this->ctx->log->info('Changing routing_number column to TEXT');
    CRM_Upgrade_Incremental_Base::alterColumn($this->ctx, 'civicrm_bank_account', 'routing_number', 'TEXT NOT NULL' );
    return TRUE;
  }

  /**
   * Ensure the custom data table for a given CustomGroup exists in the schema.
   *
   * If CiviCRM created the CustomGroup managed entity but didn't create the
   * backing table (typically due to cg_extend_objects ordering on first
   * install), this forces the table to be created.
   *
   * @param string $groupName The CustomGroup.name value.
   *
   * @throws \CRM_Core_Exception
   * @throws \Exception
   */
  private function ensureCustomTable(string $groupName): void {
    $group = \Civi\Api4\CustomGroup::get(FALSE)
      ->addSelect('id', 'name', 'table_name')
      ->addWhere('name', '=', $groupName)
      ->execute()
      ->first();

    if (empty($group)) {
      \Civi::log()->warning("ACHOffline: CustomGroup {$groupName} not found — skipping table creation.");
      return;
    }

    if (empty($group['table_name'])) {
      \Civi::log()->warning("ACHOffline: CustomGroup {$groupName} has no table_name — skipping table creation.");
      return;
    }

    // If the table already exists there is nothing to do.
    if (CRM_Core_DAO::checkTableExists($group['table_name'])) {
      return;
    }

    \Civi::log()->info("ACHOffline: Creating missing custom table {$group['table_name']} for {$groupName}.");

    $tableExists = CRM_Core_DAO::checkTableExists($group['table_name']);

    // Load the full CustomGroup DAO object.
    $groupDAO = new CRM_Core_DAO_CustomGroup();
    $groupDAO->id = $group['id'];
    $groupDAO->find(TRUE);

    if (!$tableExists) {
      \Civi::log()->info("ACHOffline: Creating missing custom table {$group['table_name']} for {$groupName}.");
      CRM_Core_BAO_CustomGroup::createTable($groupDAO);
    }

    // Always check each field's column exists, regardless of whether the
    // table itself was just created or already existed with missing columns.
    $fields = \Civi\Api4\CustomField::get(FALSE)
      ->addSelect('id', 'name', 'column_name', 'data_type')
      ->addWhere('custom_group_id.name', '=', $groupName)
      ->addWhere('is_active', '=', TRUE)
      ->execute();

    foreach ($fields as $field) {
      $columnType = $this->getColumnType($field['data_type']);
      CRM_Upgrade_Incremental_Base::addColumn($this->ctx, $group['table_name'], $field['column_name'], $columnType);
      \Civi::log()->info("ACHOffline: Ensured column {$field['column_name']} on {$group['table_name']}.");
    }

    // Flush caches so CiviCRM's internal state reflects the updated schema.
    \Civi\Api4\System::flush()->execute();
  }

  /**
   * Map a CiviCRM CustomField data_type to a MySQL column definition.
   *
   * @param string $dataType
   *
   * @return string
   */
  private function getColumnType(string $dataType): string {
    $map = [
      'String'          => 'varchar(255)',
      'Int'             => 'int',
      'Float'           => 'double',
      'Money'           => 'decimal(20,2)',
      'Memo'            => 'longtext',
      'Date'            => 'datetime',
      'Boolean'         => 'tinyint(4)',
      'StateProvince'   => 'int',
      'Country'         => 'int',
      'File'            => 'int',
      'Link'            => 'varchar(255)',
      'ContactReference'=> 'int',
      'EntityReference' => 'int',
    ];

    return $map[$dataType] ?? 'varchar(255)';
  }

}
