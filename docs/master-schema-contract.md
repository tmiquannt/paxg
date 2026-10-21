# MASTER-SCHEMA-1 — narrow migration input

Lead accepts this technical input for the first five master tables only. This is NOT the complete 26-table v1.1 schema and must not set `erp_schema_version=1.1`. SPEC §6.2 rows 1–5 define minimum inputs; no invoice allocation or cash-scope policy is needed to define these tables. Initial input is greenfield, no production/legacy data. Migration runs outside business transactions because MySQL DDL implicitly commits. The original serialized ownership gate was satisfied after TX acceptance and fixture cleanup; this migration was smoke-tested against local MySQL and plugin activation. This narrow migration is wired during activation and does not stamp the complete schema version. It is separate from acceptance of user access, business workflows, or the full v1.1 schema.

## Public interface

`MiniERP\Infrastructure\Database\MasterSchema::__construct(\wpdb $db)`; `migrate(): void`; `tableNames(): array` returns exactly five actual-prefix ERP table names in dependency order. No WordPress option, role, user, business record, or global SQL-mode changes. Plugin activation wires this first-five-table migration and the separate append-only audit migration; there is no overall schema-version stamp yet.

Validate `$wpdb->prefix` against ASCII `[A-Za-z0-9_]+`, length ≤32, before generating identifiers. Table suffixes are static allowlisted names, never from a request. Existing tables must be verified compatible (engine, columns/types/nullability, unique indexes and FK/check definitions); no unconditional destructive alteration. Repeating migrate is safe. A partially created compatible set may resume; incompatible existing objects cause a safe error and no overwrite/drop. Concurrent calls serialize with a database advisory lock scoped to database+prefix; bounded lock wait, release in finally. No credentials/raw query values in public exception messages.

## Common columns

All five tables: `id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`, `is_active TINYINT NOT NULL DEFAULT 1 CHECK (is_active IN (0,1))`, `created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`, `updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`. Store UTC; no auto-update clause needed (services own explicit update/audit). Engine InnoDB, charset utf8mb4, collation utf8mb4_unicode_ci. MySQL 8.0.16+ enforced checks required (local accepted runtime 8.0.45); do not claim MariaDB compatibility without testing.

| Suffix | Required columns/indexes beyond common |
| --- | --- |
| erp_categories | code VARCHAR(64) NOT NULL UNIQUE; name VARCHAR(191) NOT NULL |
| erp_products | sku VARCHAR(64) NOT NULL UNIQUE; name VARCHAR(191) NOT NULL; category_id BIGINT UNSIGNED NOT NULL FK categories(id) ON DELETE RESTRICT ON UPDATE RESTRICT; unit VARCHAR(32) NOT NULL; unit_step DECIMAL(18,3) NOT NULL CHECK(unit_step > 0); sale_price_vnd BIGINT NOT NULL CHECK(sale_price_vnd >= 0); INDEX(category_id) |
| erp_customers | code VARCHAR(64) NOT NULL UNIQUE; name VARCHAR(191) NOT NULL; tax_id VARCHAR(64) NOT NULL DEFAULT ''; phone VARCHAR(32) NOT NULL DEFAULT ''; email VARCHAR(254) NOT NULL DEFAULT ''; address VARCHAR(1000) NOT NULL DEFAULT '' |
| erp_suppliers | same columns as customers; separate table/code uniqueness |
| erp_warehouses | code VARCHAR(64) NOT NULL UNIQUE; name VARCHAR(191) NOT NULL |

Use deterministic constraint names including a short hash of database+prefix+table to avoid collisions across test prefixes within one DB. WordPress baseline may already contain unrelated tables; never alter them. No seed rows. Unique codes/SKUs are case-insensitive under stated collation; CRUD validation will normalize/validate input later rather than use this schema as permission checks.

## Original assignment boundaries and current implementation

The original schema-only assignment limited its writer to `MasterSchema.php` and `scripts/test-master-schema.php`; this historical boundary was superseded by the user's explicit end-to-end ERP implementation request. Current code also wires activation and includes authentication, catalog, and master-data UI slices. The original TX, Docker, and composer files were outside that schema assignment.

Integration script requires CLI, WP local, DB `mini_erp_local`, and main prefix `paxg_` before doing anything. Use a SECOND test wpdb connection to same DB with a new random prefix `paxg_test_<12 hex>_` (length within bound), never change global `$wpdb` or core prefix. Create exactly five fixture tables under that random prefix; verify allowed prefix and full generated names before each cleanup. Drop only these exact fixtures in child-before-parent order in finally, preserving all main WP/ERP tables. No production data and no global reset. It may insert disposable rows within those test tables to prove constraints and repeat-migration preservation.

## Acceptance evidence

- RED before class exists; live smoke fails for missing implementation, then GREEN.
- Real MySQL: five InnoDB/utf8mb4 tables, precise fields and constraints, duplicate SKU/code rejection, orphan category rejection, referenced-category deletion rejection, invalid active/negative price/nonpositive step rejection, timestamps microsecond-capable, repeated migrate preserves rows, incomplete compatible set resumes, incompatible existing fixture table fails closed without changing its rows.
- Unsafe/overlong prefixes rejected before queries; advisory lock failure bounded; cleanup verified. Verify empty install and rerun, not merely generated SQL strings.
- Do not run simultaneously with another DB-mutating smoke without Lead coordination, even though fixture prefixes are independent.
- Handoff exact base/contract hash, changed paths/hashes, actual commands/results/limits, write ownership release. Lead inspects code and reruns live fixture smoke before ACCEPT/REJECT. Master data CRUD/UI, audit and full migration version acceptance remain separate.

Reopen if strict compatibility checks need an unowned support class, source environment lacks enforced checks/advisory locks, or scope would require another table/interface. Never silently drop/alter a mismatching table.
