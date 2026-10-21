<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use ReflectionProperty;
use RuntimeException;
use Throwable;
use wpdb;

/** Installs purchase orders and receipt document tables. */
final class PurchaseSchema
{
    public function __construct(private readonly wpdb $db) {}

    public function migrate(): void
    {
        $prefix = $this->db->prefix; $database = $this->db->dbname;
        if (!is_string($prefix) || strlen($prefix) > 32 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) || !is_string($database) || $database === '') throw new RuntimeException('The WordPress database prefix is not supported.');
        try { $property = new ReflectionProperty($this->db, 'dbh'); $connection = $property->getValue($this->db); }
        catch (Throwable) { throw new RuntimeException('The WordPress database connection is unavailable.'); }
        if (!$connection instanceof \mysqli) throw new RuntimeException('Purchase schema migration requires a live MySQL connection.');
        $lock = 'mini-erp-purchase:' . substr(hash('sha256', $database . ':' . $prefix), 0, 40);
        $escaped = $connection->real_escape_string($lock);
        if ((string) $this->scalar($connection, "SELECT GET_LOCK('{$escaped}',5)") !== '1') throw new RuntimeException('Could not acquire the bounded purchase schema lock.');
        try {
            foreach ($this->statements($prefix) as $suffix => $sql) {
                $this->query($connection, $sql);
                $this->assertTable($connection, $database, $prefix . $suffix, $this->columns()[$suffix]);
            }
        } finally {
            if ((string) $this->scalar($connection, "SELECT RELEASE_LOCK('{$escaped}')") !== '1') throw new RuntimeException('The purchase schema lock could not be released cleanly.');
        }
    }

    /** @return array<string,string> */
    private function statements(string $p): array
    {
        $h = substr(hash('sha256', $this->db->dbname . ':' . $p), 0, 10);
        $po = '`' . $p . 'erp_purchase_orders`'; $items = '`' . $p . 'erp_purchase_order_items`';
        $receipts = '`' . $p . 'erp_goods_receipts`'; $receiptItems = '`' . $p . 'erp_goods_receipt_items`';
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'erp_purchase_orders' => "CREATE TABLE IF NOT EXISTS {$po} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, po_no VARCHAR(32) NOT NULL,
                supplier_id BIGINT UNSIGNED NOT NULL, warehouse_id BIGINT UNSIGNED NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL, approved_by BIGINT UNSIGNED NULL,
                status VARCHAR(24) NOT NULL, subtotal_exact_vnd DECIMAL(22,3) NOT NULL,
                rounding_adjustment_vnd DECIMAL(4,3) NOT NULL, subtotal_vnd BIGINT NOT NULL,
                total_vnd BIGINT NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
                note VARCHAR(1000) NOT NULL DEFAULT '', closure_reason VARCHAR(1000) NOT NULL DEFAULT '',
                created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                UNIQUE KEY po_no (po_no), KEY supplier_status (supplier_id,status), KEY warehouse_status (warehouse_id,status),
                CONSTRAINT fk_{$h}_po_supplier FOREIGN KEY (supplier_id) REFERENCES `{$p}erp_suppliers` (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_po_warehouse FOREIGN KEY (warehouse_id) REFERENCES `{$p}erp_warehouses` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_po_status CHECK (status IN ('DRAFT','PENDING_APPROVAL','APPROVED','PARTIALLY_RECEIVED','RECEIVED','REJECTED','CANCELLED','CLOSED')),
                CONSTRAINT ck_{$h}_po_money CHECK (subtotal_vnd >= 0 AND total_vnd >= 0)
            ){$engine}",
            'erp_purchase_order_items' => "CREATE TABLE IF NOT EXISTS {$items} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, po_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL, sku_snapshot VARCHAR(64) NOT NULL, name_snapshot VARCHAR(191) NOT NULL,
                unit_snapshot VARCHAR(32) NOT NULL, unit_step_snapshot DECIMAL(18,3) NOT NULL,
                unit_cost_vnd BIGINT NOT NULL, quantity DECIMAL(18,3) NOT NULL,
                line_subtotal_exact_vnd DECIMAL(22,3) NOT NULL, rounding_allocation_vnd DECIMAL(4,3) NOT NULL,
                line_total_vnd BIGINT NOT NULL, KEY po_id (po_id), KEY product_id (product_id),
                CONSTRAINT fk_{$h}_poi_order FOREIGN KEY (po_id) REFERENCES {$po} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_poi_product FOREIGN KEY (product_id) REFERENCES `{$p}erp_products` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_poi_values CHECK (unit_step_snapshot > 0 AND unit_cost_vnd >= 0 AND quantity > 0 AND line_total_vnd >= 0)
            ){$engine}",
            'erp_goods_receipts' => "CREATE TABLE IF NOT EXISTS {$receipts} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, receipt_no VARCHAR(32) NOT NULL,
                purchase_order_id BIGINT UNSIGNED NOT NULL, warehouse_id BIGINT UNSIGNED NOT NULL,
                received_by BIGINT UNSIGNED NOT NULL, received_at DATETIME(6) NOT NULL, request_key CHAR(36) NOT NULL,
                UNIQUE KEY receipt_no (receipt_no), UNIQUE KEY request_key (request_key),
                KEY po_received (purchase_order_id,received_at),
                CONSTRAINT fk_{$h}_gr_order FOREIGN KEY (purchase_order_id) REFERENCES {$po} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_gr_warehouse FOREIGN KEY (warehouse_id) REFERENCES `{$p}erp_warehouses` (id) ON DELETE RESTRICT
            ){$engine}",
            'erp_goods_receipt_items' => "CREATE TABLE IF NOT EXISTS {$receiptItems} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, goods_receipt_id BIGINT UNSIGNED NOT NULL,
                purchase_item_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, quantity DECIMAL(18,3) NOT NULL,
                UNIQUE KEY receipt_line (goods_receipt_id,purchase_item_id), KEY purchase_item_id (purchase_item_id),
                CONSTRAINT fk_{$h}_gri_receipt FOREIGN KEY (goods_receipt_id) REFERENCES {$receipts} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_gri_line FOREIGN KEY (purchase_item_id) REFERENCES {$items} (id) ON DELETE RESTRICT,
                CONSTRAINT fk_{$h}_gri_product FOREIGN KEY (product_id) REFERENCES `{$p}erp_products` (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_gri_qty CHECK (quantity > 0)
            ){$engine}",
        ];
    }

    /** @return array<string,array<string,string>> */
    private function columns(): array
    {
        return [
            'erp_purchase_orders'=>['id'=>'bigint unsigned','po_no'=>'varchar(32)','supplier_id'=>'bigint unsigned','warehouse_id'=>'bigint unsigned','created_by'=>'bigint unsigned','approved_by'=>'bigint unsigned','status'=>'varchar(24)','subtotal_exact_vnd'=>'decimal(22,3)','rounding_adjustment_vnd'=>'decimal(4,3)','subtotal_vnd'=>'bigint','total_vnd'=>'bigint','version'=>'int unsigned','note'=>'varchar(1000)','closure_reason'=>'varchar(1000)','created_at'=>'datetime(6)','updated_at'=>'datetime(6)'],
            'erp_purchase_order_items'=>['id'=>'bigint unsigned','po_id'=>'bigint unsigned','product_id'=>'bigint unsigned','sku_snapshot'=>'varchar(64)','name_snapshot'=>'varchar(191)','unit_snapshot'=>'varchar(32)','unit_step_snapshot'=>'decimal(18,3)','unit_cost_vnd'=>'bigint','quantity'=>'decimal(18,3)','line_subtotal_exact_vnd'=>'decimal(22,3)','rounding_allocation_vnd'=>'decimal(4,3)','line_total_vnd'=>'bigint'],
            'erp_goods_receipts'=>['id'=>'bigint unsigned','receipt_no'=>'varchar(32)','purchase_order_id'=>'bigint unsigned','warehouse_id'=>'bigint unsigned','received_by'=>'bigint unsigned','received_at'=>'datetime(6)','request_key'=>'char(36)'],
            'erp_goods_receipt_items'=>['id'=>'bigint unsigned','goods_receipt_id'=>'bigint unsigned','purchase_item_id'=>'bigint unsigned','product_id'=>'bigint unsigned','quantity'=>'decimal(18,3)'],
        ];
    }

    /** @param array<string,string> $expected */
    private function assertTable(\mysqli $connection, string $database, string $table, array $expected): void
    {
        $schema = $connection->real_escape_string($database); $name = $connection->real_escape_string($table);
        $result = $connection->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof \mysqli_result) $result->free();
        if (!is_array($row) || strtoupper((string)$row['ENGINE']) !== 'INNODB' || !str_starts_with(strtolower((string)$row['TABLE_COLLATION']), 'utf8mb4_')) throw new RuntimeException('An existing purchase table is incompatible; it was left unchanged.');
        $result = $connection->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect purchase schema metadata.');
        $actual=[]; while (($column=$result->fetch_assoc()) !== null) $actual[(string)$column['COLUMN_NAME']]=strtolower((string)$column['COLUMN_TYPE']); $result->free();
        foreach ($expected as $column=>$type) if (($actual[$column]??null)!==$type) throw new RuntimeException('An existing purchase table is incompatible; it was left unchanged.');
    }

    private function scalar(\mysqli $connection, string $sql): mixed { $result=$connection->query($sql); if (!$result instanceof \mysqli_result) throw new RuntimeException('A required purchase schema operation failed.'); $row=$result->fetch_row(); $result->free(); return $row[0]??null; }
    private function query(\mysqli $connection, string $sql): void { $result=$connection->query($sql); if ($result===false) throw new RuntimeException('A required purchase schema operation failed.'); if ($result instanceof \mysqli_result) $result->free(); }
}
