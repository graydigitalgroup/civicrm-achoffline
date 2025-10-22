# achoffline
This is an extension that provides an ACH payment processor option that will be processed offline.
It captures the Bank Routing Number and Bank Account Number and stores it into a new entity table.
This will be used to connect to a contribution and can then be used to link to other contributions
in the future by selecting it from a list.

To process payments offline, a SearchKit report can be created or the NACHA export extension, in works,
can be installed.

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Getting Started

Install the extension and then create a new Payment Processor using "ACH Offline" as the Payment Processor.

