# Local integration fixture

This is an isolated test environment, not a deployment or accepted ERP runtime. It pins WordPress 6.8.3/PHP 8.3.28, MySQL 8.0.45 and Composer tooling by image digest. These are reproducible test fixtures, not a claim of current production support/security suitability. Do not expose them or use real data. The Compose credentials are intentionally public local-only fixture credentials. The site root opens the ERP app after login; implemented ERP screens use frontend routes while `/wp-admin/` remains for WordPress technical administration and initial ERP System Admin bootstrap. Routine employee account creation and ERP access assignment happen in the ERP app. Workflow completion and acceptance are tracked in `docs/tasks.md`.

Prerequisites: Docker Engine and Docker Compose v2 supporting `up --wait`, Bash, free loopback ports 18088/13368, and network access on first run for pinned images and locked Composer packages. No host PHP, Composer, Node, or WordPress is required. Linux was tested; Docker Desktop is not yet tested. Docker daemon image/cache storage remains Docker-managed; all project configuration and application/database persistence are project-local.

From this project directory:

```sh
bash scripts/local.sh start
bash scripts/local.sh health
bash scripts/local.sh backup
bash scripts/local.sh test
bash scripts/local.sh smoke
bash scripts/local.sh schema-smoke
bash scripts/local.sh catalog-smoke
bash scripts/local.sh catalog-page-smoke
```

`start` builds the tools image, waits for DB and core/HTTP readiness, installs WordPress only if absent, installs `composer.lock` dependencies in a container, then activates the local Mini ERP plugin. Repeating start does not reset WordPress or the database, change existing passwords, or seed business data. Composer runs with the caller's UID/GID and project-local cache. Plugin edits on the host are immediately visible through a read-only bind mount inside WordPress; the web process cannot rewrite source.

ERP app: `http://127.0.0.1:18088/`; module URLs are outside `/wp-admin/`. Unauthenticated visits go to WordPress login. The local login `erp_local_admin` is a separate WordPress technical administrator, not an ERP business role; after login it cannot access ERP data. A WordPress technical administrator bootstraps the first ERP System Admin through **Users → ERP Access** in `/wp-admin/`. After that, ERP System Admin creates employee accounts and assigns their ERP role, warehouse/fund scope, and enabled state from **Người dùng ERP** inside the ERP app. The installer does not create or grant a business account. Database listens only on loopback port 13368; only the dedicated `mini_erp_local` database is in scope.

State is persisted under project-local `.runtime/wordpress` and `.runtime/mysql` (ignored). Plugin source is mounted read-only. `docker compose stop` stops only this project's services and preserves their state. Do not delete `.runtime` as a generic reset. No production migrations or shared database access is authorized.

Start this local Compose project first so its database container is running. `backup` writes a compressed dump of only `mini_erp_local` to `.runtime/backups/<UTC timestamp>-<process id>/`. The directory is private to the current OS user; each dump has a manifest, SHA-256 checksum and gzip integrity check. The dump uses MySQL `mysqldump --single-transaction --quick`; MySQL documents this as a consistent snapshot for transactional tables such as InnoDB, and warns that concurrent DDL can invalidate it. Avoid schema upgrades while a backup is running. See the [MySQL 8.0 mysqldump reference](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html).

To restore, first ensure this local Compose project is running and list the directory names with `ls -1 .runtime/backups/`. Pass the exact name printed by `backup`, for example `bash scripts/local.sh restore 20261021T123456Z-1234`. The command validates the manifest, checksum and gzip stream, stops WordPress, creates a fresh pre-restore backup, replaces only the local `mini_erp_local` database, imports the selected dump, and checks WordPress health. It requires an interactive terminal and the exact confirmation `RESTORE mini_erp_local`. Do not use a backup directory from an untrusted source. The restore never changes plugin source or WordPress uploads.

These commands are local recovery helpers, not the production backup control required by SEC-12. They do not encrypt backups, copy them offsite, schedule backups, retain generations, back up `.runtime/wordpress` or uploads, or perform a restore drill. Protect `.runtime/backups` as sensitive data and do not put real business data in this test fixture. Automated encrypted offsite backups, documented retention, RPO/RTO, and a successful monthly restore drill remain go-live requirements.

```sh
bash scripts/local.sh stop     # stop only this Compose project, preserve data
bash scripts/local.sh start    # restart with the same data
bash scripts/local.sh backup   # create a local database backup
bash scripts/local.sh logs     # recent DB/web logs
bash scripts/local.sh down     # remove these containers/network, preserve bind-mounted data
```

Health: `docker compose ps` must show database and wordpress healthy; `health` also prints installed/core/PHP/MySQL/timezone/BCMath and plugin-active facts without secrets. `smoke` performs real HTTP login, loads the authenticated WP dashboard and logs out. `test` runs the PHPUnit suite under Docker PHP. `schema-smoke` creates and removes exact-prefix MySQL schema fixtures. `catalog-smoke` exercises category/product service writes, optimistic conflict handling and same-transaction audit in isolated tables. `catalog-page-smoke` temporarily creates local ERP users and catalog rows to verify Manager, Sales and Warehouse projections, then removes them. These commands do not prove rendered-browser acceptance of the ERP frontend or all business workflows.

If startup fails, inspect `logs` and resolve port/image/disk errors before retrying. Do not delete persisted state to hide a failure. Initial WordPress files are copied by the official image before Apache starts; the health check prevents the installer racing that copy. A failed or partial database install requires inspection; the script does not auto-drop/recreate data. No reset command is provided.

The fixed local demo login is only for this loopback fixture. There is no SMTP, bank, payment gateway, production secret, or public host configured. Installation email is suppressed. Any later production deployment would need separate authorization and security/backup/upgrade work.

### Implementation references

The readiness distinction follows [Docker Compose startup order](https://docs.docker.com/compose/how-tos/startup-order/); core configuration follows the [official WordPress image](https://hub.docker.com/_/wordpress), and tools use the [official Composer image](https://hub.docker.com/_/composer). Version-specific behavior is verified locally, not inferred from those documents.

Verification must separately establish core HTTP/login/database readiness, plugin prerequisites, migrations, permissions and business workflows. A successful WordPress install proves only the core fixture boundary.
