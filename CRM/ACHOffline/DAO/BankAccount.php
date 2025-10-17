<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $description
 * @property string $created_date
 * @property string $modified_date
 * @property string $data_raw
 * @property string $data_parsed
 * @property string $contact_id
 */
class CRM_ACHOffline_DAO_BankAccount extends CRM_ACHOffline_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_bank_account';

}

