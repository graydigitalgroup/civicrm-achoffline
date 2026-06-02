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

## What is supported

This extensions supports:
- One-off payments.
- Refunds.
- Bank Account On File.
- Recurring payments:
    - weekly, monthly, yearly. *Daily could be made to work but the code in this extension does not support currently*.
    - Updating billing details.
    - Updating credit card details.
    - Cancel recurring payment.

## Recurring Contributions

Note: The ACH Offline requires the exporting of the accounts and processing through the bank. This processor works off
the premise that the payments will be handled offline. This processor does have a cron that will create the next pending
contribution.

## Bank Account on File

For the QuickForm Contribution pages, this will show up if the contact is found and they have any PaymentTokens saved. 

In order for the use of this within an Afform, you need to make sure you have a Join on the Contact to the PaymentToken.
This is needed to show the available payment tokens, Bank Accounts, when the options is chosen. Without it, there won't
be any options to choose from. This option works for logged in contacts or through the JWT auth method for Afform.