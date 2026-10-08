# abraflexi-voip-credit

MultiFlexi application: adds the ordered IPEX prepaid VoIP credit (`KREDIT_VOIP`) for a **paid** AbraFlexi issued invoice.

Headless successor of `SpojeNet\System\orderplugins\VoIPcredit::settled()` from the `system` eShop.

## How it works

1. Takes the invoice from `INVOICE_ID` (or `EVENT_RECORD_ID` from a MultiFlexi event rule, or `--invoice`).
2. Sums the `KREDIT_VOIP` items (skips invoices without them, exit 0) and requires the `API` label (pending flag).
3. Reads the `order.json` attachment; every entry with `ipexuser` and `phoneno` is one credit (`cenaMj * mnozMj`).
   The requested total must not exceed the invoiced `KREDIT_VOIP` amount (the attachment is customer-supplied data).
4. Calls IPEX `PUT <phoneno>/credit` (`customerId`, `amount`, `expiration`) and mails the `notify` address.
5. Removes the `API` label **only when all numbers were credited**. On a partial failure the label stays and the report lists the numbers that were already credited.

Result is written as JSON to `RESULT_FILE`. Exit codes: `0` ok/nothing to do, `1` failed, `2` missing invoice id.

## Configuration

`ABRAFLEXI_URL|LOGIN|PASSWORD|COMPANY`, `IPEX_URL|LOGIN|PASSWORD`, optional `INVOICE_ID`, `IPEX_CREDIT_EXPIRATION` (days, default 365), `NOTIFY_CUSTOMER`, `RESULT_FILE`, `APP_DEBUG`.

## Development

```
composer install
vendor/bin/phpunit
```
