# Mini ERP v1.1 — internal WordPress project

Local development platform runs on Docker, WordPress login and role-scoped ERP screens. Sales, purchases, fulfillment, stock counts, internal invoices, cash/bank ledgers, reconciliation and reports are implementation candidates that still need full acceptance; this is not a legal e-invoicing system or production deployment.

```sh
bash scripts/local.sh start
bash scripts/local.sh health
bash scripts/local.sh test
bash scripts/local.sh smoke
bash scripts/local.sh stop
```

Open [http://127.0.0.1:18088/](http://127.0.0.1:18088/) for the ERP app. It uses WordPress login; an enabled non-administrator account with an ERP role is required. `/wp-admin/` remains for WordPress technical administration and initial ERP access setup under **Users → ERP Access**. The local technical admin `erp_local_admin` is not an ERP business account. Its local-only password is `local-only-demo-not-production-2026`; never reuse these credentials elsewhere.

`bash scripts/local.sh start` installs the plugin's locked PDF dependency from `wp-content/plugins/mini-erp/composer.lock`. If packaging the plugin manually, include the generated `vendor/` directory or run `composer install --working-dir=wp-content/plugins/mini-erp --no-dev` before activation. Internal cash accounts are separate records for each cash drawer or bank account; receipts, payments and expenses post to the assigned record and its cash ledger.

See [local setup, persistence and troubleshooting](docs/local-environment.md), [requirements](SPEC.md), and [implementation/acceptance status](docs/tasks.md). All application/database state remains under `.runtime/` in this project. No production or external hosting is configured.
