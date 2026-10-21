<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use ReflectionProperty;
use RuntimeException;
use Throwable;
use wpdb;

/** Installs immutable internal invoice snapshots sourced from posted stock documents. */
final class InvoiceSchema
{
    public function __construct(private readonly wpdb $db) {}

    public function migrate(): void
    {
        $prefix = $this->db->prefix;
        if (!is_string($prefix) || strlen($prefix) > 32 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)) {
            throw new RuntimeException('The WordPress database prefix is not supported.');
        }
        try { $connection = (new ReflectionProperty($this->db, 'dbh'))->getValue($this->db); }
        catch (Throwable) { throw new RuntimeException('The WordPress database connection is unavailable.'); }
        if (!$connection instanceof \mysqli) throw new RuntimeException('Invoice schema migration requires a live MySQL connection.');
        $lock = 'mini-erp-invoice:' . substr(hash('sha256', $this->db->dbname . ':' . $prefix), 0, 40);
        $escaped = $connection->real_escape_string($lock);
        if ((string)$this->scalar($connection, "SELECT GET_LOCK('{$escaped}',5)") !== '1') throw new RuntimeException('Could not acquire the bounded invoice schema lock.');
        try {
            $statements=$this->statements($prefix);
            foreach ($statements as $sql) {
                if ($connection->query($sql) === false) throw new RuntimeException('A required invoice schema operation failed.');
            }
            foreach(array_keys($statements) as $suffix)$this->assertTable($connection,$prefix.$suffix,$suffix,false);
            $this->migratePricingSnapshots($connection,$prefix);
            foreach(array_keys($statements) as $suffix)$this->assertTable($connection,$prefix.$suffix,$suffix,true);
        } finally {
            if ((string)$this->scalar($connection, "SELECT RELEASE_LOCK('{$escaped}')") !== '1') throw new RuntimeException('The invoice schema lock could not be released cleanly.');
        }
    }

    /** @return array<string,string> */
    private function statements(string $p): array
    {
        $h = substr(hash('sha256', $this->db->dbname . ':' . $p), 0, 10);
        $invoices = '`' . $p . 'erp_invoices`';
        $items = '`' . $p . 'erp_invoice_items`';
        $engine = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'erp_invoices' => "CREATE TABLE IF NOT EXISTS {$invoices} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                invoice_no VARCHAR(32) NOT NULL,
                source_type VARCHAR(24) NOT NULL,
                source_id BIGINT UNSIGNED NOT NULL,
                direction VARCHAR(3) NOT NULL,
                party_id BIGINT UNSIGNED NOT NULL,
                seller_name VARCHAR(191) NOT NULL,
                seller_address VARCHAR(1000) NOT NULL DEFAULT '',
                seller_tax_id VARCHAR(64) NOT NULL DEFAULT '',
                buyer_name VARCHAR(191) NOT NULL,
                buyer_address VARCHAR(1000) NOT NULL DEFAULT '',
                buyer_tax_id VARCHAR(64) NOT NULL DEFAULT '',
                subtotal_exact_vnd DECIMAL(22,3) NOT NULL,
                rounding_adjustment_vnd DECIMAL(4,3) NOT NULL,
                subtotal_vnd BIGINT NOT NULL,
                discount_vnd BIGINT NOT NULL DEFAULT 0,
                tax_vnd BIGINT NOT NULL DEFAULT 0,
                total_vnd BIGINT NOT NULL,
                pricing_algorithm_version VARCHAR(16) NOT NULL DEFAULT 'LEGACY',
                discount_bps BIGINT NULL,
                tax_bps BIGINT NULL,
                status VARCHAR(16) NOT NULL,
                issued_at DATETIME(6) NOT NULL,
                due_at DATETIME(6) NOT NULL,
                issued_by BIGINT UNSIGNED NOT NULL,
                void_reason VARCHAR(1000) NOT NULL DEFAULT '',
                voided_by BIGINT UNSIGNED NULL,
                voided_at DATETIME(6) NULL,
                template_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE KEY invoice_no (invoice_no),
                UNIQUE KEY source_once (source_type,source_id),
                KEY status_due (status,due_at),
                KEY party_issued (direction,party_id,issued_at),
                CONSTRAINT ck_{$h}_inv_source CHECK (source_type IN ('SHIPMENT','GOODS_RECEIPT')),
                CONSTRAINT ck_{$h}_inv_direction CHECK (direction IN ('IN','OUT')),
                CONSTRAINT ck_{$h}_inv_status CHECK (status IN ('ISSUED','VOID')),
                CONSTRAINT ck_{$h}_inv_money CHECK (subtotal_vnd >= 0 AND discount_vnd >= 0 AND tax_vnd >= 0 AND total_vnd > 0)
            ){$engine}",
            'erp_invoice_items' => "CREATE TABLE IF NOT EXISTS {$items} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                invoice_id BIGINT UNSIGNED NOT NULL,
                source_item_id BIGINT UNSIGNED NOT NULL,
                sku_snapshot VARCHAR(64) NOT NULL,
                name_snapshot VARCHAR(191) NOT NULL,
                unit_snapshot VARCHAR(32) NOT NULL,
                quantity DECIMAL(18,3) NOT NULL,
                unit_price_vnd BIGINT NOT NULL,
                line_subtotal_exact_vnd DECIMAL(22,3) NOT NULL,
                rounding_allocation_vnd DECIMAL(4,3) NOT NULL,
                line_subtotal_vnd BIGINT NOT NULL,
                discount_vnd BIGINT NOT NULL DEFAULT 0,
                tax_vnd BIGINT NOT NULL DEFAULT 0,
                line_total_vnd BIGINT NOT NULL,
                UNIQUE KEY invoice_source_line (invoice_id,source_item_id),
                KEY source_item (source_item_id),
                CONSTRAINT fk_{$h}_ii_invoice FOREIGN KEY (invoice_id) REFERENCES {$invoices} (id) ON DELETE RESTRICT,
                CONSTRAINT ck_{$h}_ii_values CHECK (quantity > 0 AND unit_price_vnd >= 0 AND discount_vnd >= 0 AND tax_vnd >= 0 AND line_total_vnd >= 0)
            ){$engine}",
        ];
    }

    private function migratePricingSnapshots(\mysqli $connection,string $prefix):void
    {
        $invoices='`'.$prefix.'erp_invoices`';
        $items='`'.$prefix.'erp_invoice_items`';
        $this->ensureColumns($connection,$prefix.'erp_invoices',[
            'pricing_algorithm_version'=>['varchar(16)',"VARCHAR(16) NULL"],
            'discount_bps'=>['bigint','BIGINT NULL'],
            'tax_bps'=>['bigint','BIGINT NULL'],
        ]);
        $this->ensureColumns($connection,$prefix.'erp_invoice_items',[
            'line_subtotal_vnd'=>['bigint','BIGINT NULL'],
            'rounding_allocation_vnd'=>['decimal(4,3)','DECIMAL(4,3) NULL'],
        ]);

        $invalidLineCount=(int)$this->scalar($connection,"SELECT COUNT(*) FROM {$items} WHERE (CASE WHEN line_subtotal_vnd IS NULL THEN line_total_vnd-tax_vnd+discount_vnd ELSE line_subtotal_vnd END)<0 OR (CASE WHEN line_subtotal_vnd IS NULL THEN line_total_vnd-tax_vnd+discount_vnd ELSE line_subtotal_vnd END)>9223372036854775807 OR ABS(CAST(CASE WHEN line_subtotal_vnd IS NULL THEN line_total_vnd-tax_vnd+discount_vnd ELSE line_subtotal_vnd END AS DECIMAL(22,3))-line_subtotal_exact_vnd)>=1 OR CAST(CASE WHEN line_subtotal_vnd IS NULL THEN line_total_vnd-tax_vnd+discount_vnd ELSE line_subtotal_vnd END AS DECIMAL(22,3))-line_subtotal_exact_vnd<>COALESCE(rounding_allocation_vnd,CAST(CASE WHEN line_subtotal_vnd IS NULL THEN line_total_vnd-tax_vnd+discount_vnd ELSE line_subtotal_vnd END AS DECIMAL(22,3))-line_subtotal_exact_vnd)");
        if($invalidLineCount>0)throw new RuntimeException('An invoice line cannot be safely reconstructed from its stored immutable amounts.');
        $invalidInvoiceCount=(int)$this->scalar($connection,"SELECT COUNT(*) FROM {$invoices} i LEFT JOIN (SELECT invoice_id,COUNT(*) AS line_count,SUM(COALESCE(line_subtotal_vnd,line_total_vnd-tax_vnd+discount_vnd)) AS subtotal_vnd,SUM(discount_vnd) AS discount_vnd,SUM(tax_vnd) AS tax_vnd,SUM(line_total_vnd) AS total_vnd,SUM(line_subtotal_exact_vnd) AS subtotal_exact_vnd,SUM(COALESCE(rounding_allocation_vnd,CAST(COALESCE(line_subtotal_vnd,line_total_vnd-tax_vnd+discount_vnd) AS DECIMAL(22,3))-line_subtotal_exact_vnd)) AS rounding_adjustment_vnd FROM {$items} GROUP BY invoice_id) x ON x.invoice_id=i.id WHERE x.invoice_id IS NULL OR x.subtotal_vnd<>i.subtotal_vnd OR x.discount_vnd<>i.discount_vnd OR x.tax_vnd<>i.tax_vnd OR x.total_vnd<>i.total_vnd OR x.subtotal_exact_vnd<>i.subtotal_exact_vnd OR x.rounding_adjustment_vnd<>i.rounding_adjustment_vnd OR i.subtotal_vnd-i.discount_vnd+i.tax_vnd<>i.total_vnd");
        if($invalidInvoiceCount>0)throw new RuntimeException('An invoice header does not reconcile to its immutable source lines; pricing migration stopped.');

        $this->query($connection,"UPDATE {$invoices} SET pricing_algorithm_version='LEGACY' WHERE pricing_algorithm_version IS NULL");
        $this->query($connection,"UPDATE {$items} SET line_subtotal_vnd=line_total_vnd-tax_vnd+discount_vnd WHERE line_subtotal_vnd IS NULL");
        $this->query($connection,"UPDATE {$items} SET rounding_allocation_vnd=CAST(line_subtotal_vnd AS DECIMAL(22,3))-line_subtotal_exact_vnd WHERE rounding_allocation_vnd IS NULL");
        $this->query($connection,"ALTER TABLE {$invoices} MODIFY COLUMN pricing_algorithm_version VARCHAR(16) NOT NULL DEFAULT 'LEGACY'");
        $this->query($connection,"ALTER TABLE {$items} MODIFY COLUMN line_subtotal_vnd BIGINT NOT NULL, MODIFY COLUMN rounding_allocation_vnd DECIMAL(4,3) NOT NULL");
    }

    /** @param array<string,array{0:string,1:string}> $columns */
    private function ensureColumns(\mysqli $connection,string $table,array $columns):void
    {
        $actual=$this->columnTypes($connection,$table);
        $quoted='`'.$table.'`';
        foreach($columns as $name=>[$expectedType,$definition]){
            if(isset($actual[$name])){
                if($actual[$name]!==$expectedType)throw new RuntimeException('An invoice pricing column has an incompatible type; it was left unchanged.');
                continue;
            }
            $this->query($connection,"ALTER TABLE {$quoted} ADD COLUMN `{$name}` {$definition}");
            $actual=$this->columnTypes($connection,$table);
            if(($actual[$name]??null)!==$expectedType)throw new RuntimeException('An invoice pricing column could not be installed safely.');
        }
    }

    /** @return array<string,string> */
    private function columnTypes(\mysqli $connection,string $table):array
    {
        $schema=$connection->real_escape_string($this->db->dbname);$name=$connection->real_escape_string($table);
        $result=$connection->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        if(!$result instanceof \mysqli_result)throw new RuntimeException('Could not inspect invoice schema metadata.');
        $columns=[];while(($column=$result->fetch_assoc())!==null)$columns[(string)$column['COLUMN_NAME']]=strtolower((string)$column['COLUMN_TYPE']);$result->free();return $columns;
    }

    private function query(\mysqli $connection,string $sql):void
    {
        if($connection->query($sql)===false)throw new RuntimeException('A required invoice schema operation failed.');
    }

    private function assertTable(\mysqli $connection, string $table, string $suffix,bool $includePricingSnapshot): void
    {
        $schema = $connection->real_escape_string($this->db->dbname);
        $name = $connection->real_escape_string($table);
        $result = $connection->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        $row = $result instanceof \mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof \mysqli_result) $result->free();
        if (!is_array($row) || strtoupper((string)$row['ENGINE']) !== 'INNODB' || !str_starts_with(strtolower((string)$row['TABLE_COLLATION']), 'utf8mb4_')) throw new RuntimeException('An existing invoice table is incompatible; it was left unchanged.');
        $expected = $suffix === 'erp_invoices'
            ? ['id'=>'bigint unsigned','invoice_no'=>'varchar(32)','source_type'=>'varchar(24)','source_id'=>'bigint unsigned','direction'=>'varchar(3)','party_id'=>'bigint unsigned','seller_name'=>'varchar(191)','seller_address'=>'varchar(1000)','seller_tax_id'=>'varchar(64)','buyer_name'=>'varchar(191)','buyer_address'=>'varchar(1000)','buyer_tax_id'=>'varchar(64)','subtotal_exact_vnd'=>'decimal(22,3)','rounding_adjustment_vnd'=>'decimal(4,3)','subtotal_vnd'=>'bigint','discount_vnd'=>'bigint','tax_vnd'=>'bigint','total_vnd'=>'bigint','pricing_algorithm_version'=>'varchar(16)','discount_bps'=>'bigint','tax_bps'=>'bigint','status'=>'varchar(16)','issued_at'=>'datetime(6)','due_at'=>'datetime(6)','issued_by'=>'bigint unsigned','void_reason'=>'varchar(1000)','voided_by'=>'bigint unsigned','voided_at'=>'datetime(6)','template_version'=>'smallint unsigned']
            : ['id'=>'bigint unsigned','invoice_id'=>'bigint unsigned','source_item_id'=>'bigint unsigned','sku_snapshot'=>'varchar(64)','name_snapshot'=>'varchar(191)','unit_snapshot'=>'varchar(32)','quantity'=>'decimal(18,3)','unit_price_vnd'=>'bigint','line_subtotal_exact_vnd'=>'decimal(22,3)','rounding_allocation_vnd'=>'decimal(4,3)','line_subtotal_vnd'=>'bigint','discount_vnd'=>'bigint','tax_vnd'=>'bigint','line_total_vnd'=>'bigint'];
        if(!$includePricingSnapshot){unset($expected['pricing_algorithm_version'],$expected['discount_bps'],$expected['tax_bps'],$expected['rounding_allocation_vnd'],$expected['line_subtotal_vnd']);}
        $result = $connection->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}'");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect invoice schema metadata.');
        $actual=[]; while (($column=$result->fetch_assoc()) !== null) $actual[(string)$column['COLUMN_NAME']]=strtolower((string)$column['COLUMN_TYPE']); $result->free();
        foreach ($expected as $column=>$type) if (($actual[$column]??null)!==$type) throw new RuntimeException('An existing invoice table is incompatible; it was left unchanged.');
        $result=$connection->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{$schema}' AND TABLE_NAME='{$name}' ORDER BY INDEX_NAME,SEQ_IN_INDEX");
        if (!$result instanceof \mysqli_result) throw new RuntimeException('Could not inspect invoice index metadata.');
        $indexes=[]; while (($index=$result->fetch_assoc())!==null) $indexes[(string)$index['INDEX_NAME']]['columns'][]=(string)$index['COLUMN_NAME']; $result->data_seek(0);
        while (($index=$result->fetch_assoc())!==null) $indexes[(string)$index['INDEX_NAME']]['unique']=(int)$index['NON_UNIQUE']===0;
        $result->free();
        $required=$suffix==='erp_invoices'
            ? ['invoice_no'=>['invoice_no',true],'source_once'=>['source_type,source_id',true],'status_due'=>['status,due_at',false]]
            : ['invoice_source_line'=>['invoice_id,source_item_id',true]];
        foreach($required as $key=>[$columns,$unique]) if(($indexes[$key]['columns']??[])!==explode(',',$columns)||($indexes[$key]['unique']??false)!==$unique) throw new RuntimeException('An existing invoice table is missing a required index; it was left unchanged.');
    }

    private function scalar(\mysqli $connection, string $sql): mixed
    {
        $result=$connection->query($sql); if (!$result instanceof \mysqli_result) throw new RuntimeException('A required invoice schema operation failed.');
        $row=$result->fetch_row(); $result->free(); return $row[0]??null;
    }
}
