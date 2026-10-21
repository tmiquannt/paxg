<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use ReflectionProperty;
use RuntimeException;
use Throwable;
use wpdb;

/** Installs the sales and inventory-ledger tables without stamping the full v1.1 schema. */
final class SalesSchema
{
    private const TABLES = [
        'erp_sales_orders', 'erp_sales_order_items', 'erp_stock_balances',
        'erp_stock_movements', 'erp_shipments', 'erp_shipment_items',
    ];

    public function __construct(private readonly wpdb $db) {}

    public function migrate(): void
    {
        $prefix = $this->db->prefix;
        $database = $this->db->dbname;
        if (!is_string($prefix) || strlen($prefix) > 32 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)
            || !is_string($database) || $database === '') {
            throw new RuntimeException('The WordPress database prefix is not supported.');
        }
        try {
            $property = new ReflectionProperty($this->db, 'dbh');
            $connection = $property->getValue($this->db);
        } catch (Throwable) {
            throw new RuntimeException('The WordPress database connection is unavailable.');
        }
        if (!$connection instanceof \mysqli) {
            throw new RuntimeException('Sales schema migration requires a live MySQL connection.');
        }

        $lock = 'mini-erp-sales:' . substr(hash('sha256', $database . ':' . $prefix), 0, 40);
        $escapedLock = $connection->real_escape_string($lock);
        if ((string) $this->scalar($connection, "SELECT GET_LOCK('{$escapedLock}', 5)") !== '1') {
            throw new RuntimeException('Could not acquire the bounded sales schema migration lock.');
        }
        try {
            $this->upgradeEmptyDraftTables($connection, $database, $prefix);
            foreach ($this->statements($prefix) as $suffix => $sql) {
                $this->query($connection, $sql);
                $this->assertTable($connection, $database, $prefix . $suffix, $this->columns()[$suffix], $this->indexes($prefix)[$suffix], $this->checks()[$suffix], $this->foreignKeys($prefix)[$suffix]);
            }
        } finally {
            if ((string) $this->scalar($connection, "SELECT RELEASE_LOCK('{$escapedLock}')") !== '1') {
                throw new RuntimeException('The sales schema migration lock could not be released cleanly.');
            }
        }
    }

    /** Add only newer snapshot columns to empty tables from this unreleased local draft. */
    private function upgradeEmptyDraftTables(\mysqli $connection, string $database, string $prefix): void
    {
        $additions = [
            'erp_sales_orders' => [
                'tax_bps' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
                'subtotal_exact_vnd' => 'DECIMAL(22,3) NOT NULL DEFAULT 0',
                'rounding_adjustment_vnd' => 'DECIMAL(4,3) NOT NULL DEFAULT 0',
                'closure_reason' => "VARCHAR(1000) NOT NULL DEFAULT ''",
            ],
            'erp_sales_order_items' => [
                'unit_step_snapshot' => 'DECIMAL(18,3) NOT NULL DEFAULT 0',
                'line_subtotal_exact_vnd' => 'DECIMAL(22,3) NOT NULL DEFAULT 0',
                'rounding_allocation_vnd' => 'DECIMAL(4,3) NOT NULL DEFAULT 0',
                'line_subtotal_vnd' => 'BIGINT NOT NULL DEFAULT 0',
                'discount_vnd' => 'BIGINT NOT NULL DEFAULT 0',
                'tax_vnd' => 'BIGINT NOT NULL DEFAULT 0',
            ],
        ];
        $legacyColumns = [
            'erp_sales_orders' => ['id'=>'bigint unsigned','order_no'=>'varchar(32)','customer_id'=>'bigint unsigned','warehouse_id'=>'bigint unsigned','created_by'=>'bigint unsigned','approved_by'=>'bigint unsigned','status'=>'varchar(24)','discount_bps'=>'smallint unsigned','subtotal_vnd'=>'bigint','discount_vnd'=>'bigint','tax_vnd'=>'bigint','total_vnd'=>'bigint','version'=>'int unsigned','note'=>'varchar(1000)','created_at'=>'datetime(6)','updated_at'=>'datetime(6)'],
            'erp_sales_order_items' => ['id'=>'bigint unsigned','order_id'=>'bigint unsigned','product_id'=>'bigint unsigned','sku_snapshot'=>'varchar(64)','name_snapshot'=>'varchar(191)','unit_snapshot'=>'varchar(32)','unit_step_snapshot'=>'decimal(18,3)','unit_price_vnd'=>'bigint','quantity'=>'decimal(18,3)','line_total_vnd'=>'bigint'],
        ];
        foreach ($additions as $suffix => $fields) {
            $table = $prefix . $suffix;
            $schemaEscaped = $connection->real_escape_string($database);
            $tableEscaped = $connection->real_escape_string($table);
            $exists = $this->scalar($connection, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$schemaEscaped}' AND TABLE_NAME='{$tableEscaped}'");
            if ((int) $exists !== 1) continue;
            $columnsResult = $connection->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$schemaEscaped}' AND TABLE_NAME='{$tableEscaped}'");
            if (!$columnsResult instanceof \mysqli_result) continue;
            $present = [];
            while (($column = $columnsResult->fetch_assoc()) !== null) $present[(string) $column['COLUMN_NAME']] = strtolower((string) $column['COLUMN_TYPE']);
            $columnsResult->free();
            $missing = array_diff_key($fields, $present);
            if ($missing === []) continue;
            foreach ($legacyColumns[$suffix] as $column => $type) {
                if (($present[$column] ?? null) !== $type) throw new RuntimeException('An existing sales table does not match the recognized unreleased draft schema; it was left unchanged.');
            }
            $rowCount = $this->scalar($connection, 'SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`');
            if ((int) $rowCount !== 0) {
                throw new RuntimeException('An older sales table contains records that cannot be backfilled without changing their exact monetary snapshots.');
            }
            foreach ($missing as $column => $definition) {
                $this->query($connection, 'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN `' . $column . '` ' . $definition);
            }
        }
    }

    /** @return array<string,string> */
    private function statements(string $p): array
    {
        $h = substr(hash('sha256', $this->db->dbname . ':' . $p), 0, 10);
        $orders = '`' . $p . 'erp_sales_orders`';
        $items = '`' . $p . 'erp_sales_order_items`';
        $balances = '`' . $p . 'erp_stock_balances`';
        $movements = '`' . $p . 'erp_stock_movements`';
        $shipments = '`' . $p . 'erp_shipments`';
        $shipmentItems = '`' . $p . 'erp_shipment_items`';
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'erp_sales_orders' => "CREATE TABLE IF NOT EXISTS {$orders} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_no VARCHAR(32) NOT NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                warehouse_id BIGINT UNSIGNED NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                approved_by BIGINT UNSIGNED NULL,
                status VARCHAR(24) NOT NULL,
                discount_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                tax_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
                subtotal_exact_vnd DECIMAL(22,3) NOT NULL,
                rounding_adjustment_vnd DECIMAL(4,3) NOT NULL,
                subtotal_vnd BIGINT NOT NULL,
                discount_vnd BIGINT NOT NULL DEFAULT 0,
                tax_vnd BIGINT NOT NULL DEFAULT 0,
                total_vnd BIGINT NOT NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                note VARCHAR(1000) NOT NULL DEFAULT '',
                closure_reason VARCHAR(1000) NOT NULL DEFAULT '',
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY order_no (order_no), KEY customer_status (customer_id,status), KEY warehouse_status (warehouse_id,status),
                CONSTRAINT fk_{$h}_so_customer FOREIGN KEY (customer_id) REFERENCES `{$p}erp_customers` (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_so_warehouse FOREIGN KEY (warehouse_id) REFERENCES `{$p}erp_warehouses` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_so_status CHECK (status IN ('DRAFT','PENDING_APPROVAL','CONFIRMED','PARTIALLY_SHIPPED','SHIPPED','CANCELLED','CLOSED')),
                CONSTRAINT ck_{$h}_so_money CHECK (subtotal_vnd >= 0 AND discount_vnd >= 0 AND tax_vnd >= 0 AND total_vnd >= 0)
            ){$engine}",
            'erp_sales_order_items' => "CREATE TABLE IF NOT EXISTS {$items} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                sku_snapshot VARCHAR(64) NOT NULL,
                name_snapshot VARCHAR(191) NOT NULL,
                unit_snapshot VARCHAR(32) NOT NULL,
                unit_step_snapshot DECIMAL(18,3) NOT NULL,
                unit_price_vnd BIGINT NOT NULL,
                quantity DECIMAL(18,3) NOT NULL,
                line_subtotal_exact_vnd DECIMAL(22,3) NOT NULL,
                rounding_allocation_vnd DECIMAL(4,3) NOT NULL,
                line_subtotal_vnd BIGINT NOT NULL,
                discount_vnd BIGINT NOT NULL,
                tax_vnd BIGINT NOT NULL,
                line_total_vnd BIGINT NOT NULL,
                KEY order_id (order_id), KEY product_id (product_id),
                CONSTRAINT fk_{$h}_soi_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_soi_product FOREIGN KEY (product_id) REFERENCES `{$p}erp_products` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_soi_values CHECK (unit_step_snapshot > 0 AND unit_price_vnd >= 0 AND quantity > 0 AND line_subtotal_vnd >= 0 AND discount_vnd >= 0 AND tax_vnd >= 0 AND line_total_vnd >= 0)
            ){$engine}",
            'erp_stock_balances' => "CREATE TABLE IF NOT EXISTS {$balances} (
                warehouse_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                quantity DECIMAL(18,3) NOT NULL DEFAULT 0,
                updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (warehouse_id,product_id),
                CONSTRAINT fk_{$h}_sb_warehouse FOREIGN KEY (warehouse_id) REFERENCES `{$p}erp_warehouses` (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_sb_product FOREIGN KEY (product_id) REFERENCES `{$p}erp_products` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_sb_qty CHECK (quantity >= 0)
            ){$engine}",
            'erp_stock_movements' => "CREATE TABLE IF NOT EXISTS {$movements} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                warehouse_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                movement_key VARCHAR(128) NOT NULL,
                direction VARCHAR(3) NOT NULL,
                qty DECIMAL(18,3) NOT NULL,
                before_qty DECIMAL(18,3) NOT NULL,
                after_qty DECIMAL(18,3) NOT NULL,
                source_type VARCHAR(32) NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                actor_id BIGINT UNSIGNED NOT NULL,
                occurred_at DATETIME(6) NOT NULL,
                UNIQUE KEY movement_key (movement_key), KEY source (source_type,source_id), KEY stock_history (warehouse_id,product_id,occurred_at),
                CONSTRAINT fk_{$h}_sm_balance FOREIGN KEY (warehouse_id,product_id) REFERENCES {$balances} (warehouse_id,product_id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_sm_values CHECK (direction IN ('IN','OUT') AND qty > 0 AND before_qty >= 0 AND after_qty >= 0)
            ){$engine}",
            'erp_shipments' => "CREATE TABLE IF NOT EXISTS {$shipments} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                shipment_no VARCHAR(32) NOT NULL,
                sales_order_id BIGINT UNSIGNED NOT NULL,
                warehouse_id BIGINT UNSIGNED NOT NULL,
                shipped_by BIGINT UNSIGNED NOT NULL,
                shipped_at DATETIME(6) NOT NULL,
                request_key CHAR(36) NOT NULL,
                UNIQUE KEY shipment_no (shipment_no), UNIQUE KEY request_key (request_key),
                KEY order_shipped (sales_order_id,shipped_at),
                CONSTRAINT fk_{$h}_sh_order FOREIGN KEY (sales_order_id) REFERENCES {$orders} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_sh_warehouse FOREIGN KEY (warehouse_id) REFERENCES `{$p}erp_warehouses` (id) ON DELETE RESTRICT
            ){$engine}",
            'erp_shipment_items' => "CREATE TABLE IF NOT EXISTS {$shipmentItems} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                shipment_id BIGINT UNSIGNED NOT NULL,
                sales_item_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                quantity DECIMAL(18,3) NOT NULL,
                UNIQUE KEY shipment_line (shipment_id,sales_item_id), KEY sales_item_id (sales_item_id),
                CONSTRAINT fk_{$h}_shi_shipment FOREIGN KEY (shipment_id) REFERENCES {$shipments} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_shi_line FOREIGN KEY (sales_item_id) REFERENCES {$items} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_shi_product FOREIGN KEY (product_id) REFERENCES `{$p}erp_products` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_shi_qty CHECK (quantity > 0)
            ){$engine}",
        ];
    }

    /** @return array<string,array<string,string>> */
    private function columns(): array
    {
        return [
            'erp_sales_orders' => ['id'=>'bigint unsigned','order_no'=>'varchar(32)','customer_id'=>'bigint unsigned','warehouse_id'=>'bigint unsigned','created_by'=>'bigint unsigned','approved_by'=>'bigint unsigned','status'=>'varchar(24)','discount_bps'=>'smallint unsigned','tax_bps'=>'bigint unsigned','subtotal_exact_vnd'=>'decimal(22,3)','rounding_adjustment_vnd'=>'decimal(4,3)','subtotal_vnd'=>'bigint','discount_vnd'=>'bigint','tax_vnd'=>'bigint','total_vnd'=>'bigint','version'=>'int unsigned','note'=>'varchar(1000)','closure_reason'=>'varchar(1000)','created_at'=>'datetime(6)','updated_at'=>'datetime(6)'],
            'erp_sales_order_items' => ['id'=>'bigint unsigned','order_id'=>'bigint unsigned','product_id'=>'bigint unsigned','sku_snapshot'=>'varchar(64)','name_snapshot'=>'varchar(191)','unit_snapshot'=>'varchar(32)','unit_step_snapshot'=>'decimal(18,3)','unit_price_vnd'=>'bigint','quantity'=>'decimal(18,3)','line_subtotal_exact_vnd'=>'decimal(22,3)','rounding_allocation_vnd'=>'decimal(4,3)','line_subtotal_vnd'=>'bigint','discount_vnd'=>'bigint','tax_vnd'=>'bigint','line_total_vnd'=>'bigint'],
            'erp_stock_balances' => ['warehouse_id'=>'bigint unsigned','product_id'=>'bigint unsigned','quantity'=>'decimal(18,3)','updated_at'=>'datetime(6)'],
            'erp_stock_movements' => ['id'=>'bigint unsigned','warehouse_id'=>'bigint unsigned','product_id'=>'bigint unsigned','movement_key'=>'varchar(128)','direction'=>'varchar(3)','qty'=>'decimal(18,3)','before_qty'=>'decimal(18,3)','after_qty'=>'decimal(18,3)','source_type'=>'varchar(32)','source_id'=>'bigint unsigned','actor_id'=>'bigint unsigned','occurred_at'=>'datetime(6)'],
            'erp_shipments' => ['id'=>'bigint unsigned','shipment_no'=>'varchar(32)','sales_order_id'=>'bigint unsigned','warehouse_id'=>'bigint unsigned','shipped_by'=>'bigint unsigned','shipped_at'=>'datetime(6)','request_key'=>'char(36)'],
            'erp_shipment_items' => ['id'=>'bigint unsigned','shipment_id'=>'bigint unsigned','sales_item_id'=>'bigint unsigned','product_id'=>'bigint unsigned','quantity'=>'decimal(18,3)'],
        ];
    }

    /** @return array<string,array<string,list<string>>> */
    private function indexes(string $prefix): array
    {
        $hash = substr(hash('sha256', $this->db->dbname . ':' . $prefix), 0, 10);
        return [
            'erp_sales_orders'=>['PRIMARY'=>['id'],'order_no'=>['order_no'],'customer_status'=>['customer_id','status'],'warehouse_status'=>['warehouse_id','status']],
            'erp_sales_order_items'=>['PRIMARY'=>['id'],'order_id'=>['order_id'],'product_id'=>['product_id']],
            'erp_stock_balances'=>['PRIMARY'=>['warehouse_id','product_id'],'fk_'.$hash.'_sb_product'=>['product_id']],
            'erp_stock_movements'=>['PRIMARY'=>['id'],'movement_key'=>['movement_key'],'source'=>['source_type','source_id'],'stock_history'=>['warehouse_id','product_id','occurred_at']],
            'erp_shipments'=>['PRIMARY'=>['id'],'shipment_no'=>['shipment_no'],'request_key'=>['request_key'],'order_shipped'=>['sales_order_id','shipped_at'],'fk_'.$hash.'_sh_warehouse'=>['warehouse_id']],
            'erp_shipment_items'=>['PRIMARY'=>['id'],'shipment_line'=>['shipment_id','sales_item_id'],'sales_item_id'=>['sales_item_id'],'fk_'.$hash.'_shi_product'=>['product_id']],
        ];
    }

    /** @return array<string,list<string>> */
    private function checks(): array
    {
        return [
            'erp_sales_orders'=>["status in ('draft','pending_approval','confirmed','partially_shipped','shipped','cancelled','closed')",'subtotal_vnd>=0 and discount_vnd>=0 and tax_vnd>=0 and total_vnd>=0'],
            'erp_sales_order_items'=>['unit_step_snapshot>0 and unit_price_vnd>=0 and quantity>0 and line_subtotal_vnd>=0 and discount_vnd>=0 and tax_vnd>=0 and line_total_vnd>=0'],
            'erp_stock_balances'=>['quantity>=0'],
            'erp_stock_movements'=>["direction in ('in','out') and qty>0 and before_qty>=0 and after_qty>=0"],
            'erp_shipments'=>[],
            'erp_shipment_items'=>['quantity>0'],
        ];
    }

    /** @return array<string,list<array{column:string,table:string,referenced:string,delete:string,update:string}>> */
    private function foreignKeys(string $prefix): array
    {
        $fk = static fn (string $column, string $table, string $referenced = 'id'): array => ['column'=>$column,'table'=>$prefix . $table,'referenced'=>$referenced,'delete'=>'RESTRICT','update'=>'NO ACTION'];
        return [
            'erp_sales_orders'=>[$fk('customer_id','erp_customers'),$fk('warehouse_id','erp_warehouses')],
            'erp_sales_order_items'=>[$fk('order_id','erp_sales_orders'),$fk('product_id','erp_products')],
            'erp_stock_balances'=>[$fk('warehouse_id','erp_warehouses'),$fk('product_id','erp_products')],
            'erp_stock_movements'=>[['column'=>'warehouse_id','table'=>$prefix.'erp_stock_balances','referenced'=>'warehouse_id','delete'=>'RESTRICT','update'=>'NO ACTION'],['column'=>'product_id','table'=>$prefix.'erp_stock_balances','referenced'=>'product_id','delete'=>'RESTRICT','update'=>'NO ACTION']],
            'erp_shipments'=>[$fk('sales_order_id','erp_sales_orders'),$fk('warehouse_id','erp_warehouses')],
            'erp_shipment_items'=>[$fk('shipment_id','erp_shipments'),$fk('sales_item_id','erp_sales_order_items'),$fk('product_id','erp_products')],
        ];
    }

    /** @param array<string,string> $expected */
    private function assertTable(\mysqli $connection, string $database, string $table, array $expected, array $expectedIndexes, array $expectedChecks, array $expectedForeignKeys): void
    {
        $schema = $connection->real_escape_string($database);
        $name = $connection->real_escape_string($table);
        $result = $connection->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof \mysqli_result) $result->free();
        if (!is_array($row) || strtoupper((string) $row['ENGINE']) !== 'INNODB' || !str_starts_with(strtolower((string) $row['TABLE_COLLATION']), 'utf8mb4_')) {
            throw new RuntimeException('An existing sales table is incompatible; it was left unchanged.');
        }
        $result = $connection->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect sales schema metadata.');
        $actual = [];
        while ($column = $result->fetch_assoc()) $actual[(string) $column['COLUMN_NAME']] = strtolower((string) $column['COLUMN_TYPE']);
        $result->free();
        foreach ($expected as $column => $type) {
            if (($actual[$column] ?? null) !== $type) throw new RuntimeException('An existing sales table is incompatible; it was left unchanged.');
        }

        $result = $connection->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}' ORDER BY INDEX_NAME,SEQ_IN_INDEX");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect sales schema indexes.');
        $actualIndexes = []; $uniqueIndexes = [];
        while ($index = $result->fetch_assoc()) {
            $indexName = (string) $index['INDEX_NAME']; $actualIndexes[$indexName][] = (string) $index['COLUMN_NAME'];
            if ((int) $index['NON_UNIQUE'] === 0) $uniqueIndexes[$indexName] = true;
        }
        $result->free();
        foreach ($expectedIndexes as $indexName => $columns) {
            if (($actualIndexes[$indexName] ?? null) !== $columns) throw new RuntimeException('An existing sales table is missing a required index; it was left unchanged.');
            $expectedUnique = $indexName === 'PRIMARY' || in_array($indexName, ['order_no','movement_key','shipment_no','request_key','shipment_line'], true);
            if ($expectedUnique !== isset($uniqueIndexes[$indexName])) throw new RuntimeException('An existing sales table has an incompatible unique index.');
        }
        if (count($actualIndexes) !== count($expectedIndexes)) throw new RuntimeException('An existing sales table has unexpected indexes; it was left unchanged.');

        $result = $connection->query("SELECT cc.CHECK_CLAUSE,tc.ENFORCED FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA='{$schema}' AND tc.TABLE_NAME='{$name}' AND tc.CONSTRAINT_TYPE='CHECK'");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect sales schema checks.');
        $actualChecks = [];
        while ($check = $result->fetch_assoc()) if (strtoupper((string) $check['ENFORCED']) === 'YES') $actualChecks[] = $this->normalize((string) $check['CHECK_CLAUSE']);
        $result->free();
        foreach ($expectedChecks as $expression) if (!in_array($this->normalize($expression), $actualChecks, true)) throw new RuntimeException('An existing sales table is missing an enforced check constraint.');
        if (count($actualChecks) !== count($expectedChecks)) throw new RuntimeException('An existing sales table has unexpected check constraints.');

        $result = $connection->query("SELECT kcu.COLUMN_NAME,kcu.REFERENCED_TABLE_NAME,kcu.REFERENCED_COLUMN_NAME,rc.DELETE_RULE,rc.UPDATE_RULE,kcu.ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA=kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME=kcu.CONSTRAINT_NAME WHERE kcu.TABLE_SCHEMA='{$schema}' AND kcu.TABLE_NAME='{$name}' AND kcu.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY kcu.CONSTRAINT_NAME,kcu.ORDINAL_POSITION");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect sales schema foreign keys.');
        $actualForeignKeys = [];
        while ($fk = $result->fetch_assoc()) $actualForeignKeys[] = ['column'=>(string)$fk['COLUMN_NAME'],'table'=>(string)$fk['REFERENCED_TABLE_NAME'],'referenced'=>(string)$fk['REFERENCED_COLUMN_NAME'],'delete'=>strtoupper((string)$fk['DELETE_RULE']),'update'=>strtoupper((string)$fk['UPDATE_RULE'])];
        $result->free();
        foreach ($expectedForeignKeys as $expectedFk) if (!in_array($expectedFk, $actualForeignKeys, true)) throw new RuntimeException('An existing sales table is missing a required foreign key.');
        if (count($actualForeignKeys) !== count($expectedForeignKeys)) throw new RuntimeException('An existing sales table has unexpected foreign keys.');
    }

    private function normalize(string $value): string
    {
        $normalized = strtolower(preg_replace('/[`()\s\\\\]+/', '', $value) ?? $value);
        return str_replace('_utf8mb4', '', $normalized);
    }

    private function scalar(\mysqli $connection, string $sql): mixed
    {
        $result = $connection->query($sql);
        if (!$result instanceof \mysqli_result) throw new RuntimeException('A required sales schema operation failed.');
        $row = $result->fetch_row(); $result->free(); return $row[0] ?? null;
    }

    private function query(\mysqli $connection, string $sql): void
    {
        $result = $connection->query($sql);
        if ($result === false) throw new RuntimeException('A required sales schema operation failed.');
        if ($result instanceof \mysqli_result) $result->free();
    }
}
