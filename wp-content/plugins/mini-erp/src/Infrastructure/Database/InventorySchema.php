<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use ReflectionProperty;
use RuntimeException;
use Throwable;
use wpdb;

/** Stock count and maker-checker adjustment documents. */
final class InventorySchema
{
    public function __construct(private readonly wpdb $db) {}

    public function migrate():void
    {
        $prefix=$this->db->prefix;if(!is_string($prefix)||strlen($prefix)>32||!preg_match('/\A[A-Za-z0-9_]+\z/D',$prefix))throw new RuntimeException('The WordPress database prefix is not supported.');
        try{$connection=(new ReflectionProperty($this->db,'dbh'))->getValue($this->db);}catch(Throwable){throw new RuntimeException('The WordPress database connection is unavailable.');}
        if(!$connection instanceof \mysqli)throw new RuntimeException('Inventory schema migration requires a live MySQL connection.');
        $lock='mini-erp-inventory:'.substr(hash('sha256',$this->db->dbname.':'.$prefix),0,40);$escaped=$connection->real_escape_string($lock);
        if((string)$this->scalar($connection,"SELECT GET_LOCK('{$escaped}',5)")!=='1')throw new RuntimeException('Could not acquire the bounded inventory schema lock.');
        $failure=null;try{foreach($this->statements($prefix) as $suffix=>$sql){if($connection->query($sql)===false)throw new RuntimeException('A required inventory schema operation failed.');$this->assertTable($connection,$prefix.$suffix,$suffix);}}catch(Throwable $e){$failure=$e;}
        $release=null;try{if((string)$this->scalar($connection,"SELECT RELEASE_LOCK('{$escaped}')")!=='1')$release=new RuntimeException('The inventory schema lock could not be released cleanly.');}catch(Throwable $e){$release=$e;}
        if($failure!==null)throw $failure;if($release!==null)throw new RuntimeException('The inventory schema lock could not be released cleanly.',0,$release);
    }

    /** @return array<string,string> */
    private function statements(string $p):array
    {
        $h=substr(hash('sha256',$this->db->dbname.':'.$p),0,10);$adjustments='`'.$p.'erp_stock_adjustments`';$items='`'.$p.'erp_stock_adjustment_items`';$warehouses='`'.$p.'erp_warehouses`';$products='`'.$p.'erp_products`';$engine=' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'erp_stock_adjustments'=>"CREATE TABLE IF NOT EXISTS {$adjustments} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,adjustment_no VARCHAR(32) NOT NULL,warehouse_id BIGINT UNSIGNED NOT NULL,status VARCHAR(24) NOT NULL,requested_by BIGINT UNSIGNED NOT NULL,approved_by BIGINT UNSIGNED NULL,applied_by BIGINT UNSIGNED NULL,reason VARCHAR(1000) NOT NULL,requested_at DATETIME(6) NULL,approved_at DATETIME(6) NULL,applied_at DATETIME(6) NULL,apply_request_key CHAR(36) NULL,version INT UNSIGNED NOT NULL DEFAULT 1,created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,UNIQUE KEY adjustment_no(adjustment_no),UNIQUE KEY apply_request_key(apply_request_key),KEY warehouse_status(warehouse_id,status),KEY requester_status(requested_by,status),CONSTRAINT fk_{$h}_adj_warehouse FOREIGN KEY(warehouse_id) REFERENCES {$warehouses}(id) ON DELETE RESTRICT,CONSTRAINT ck_{$h}_adj_status CHECK(status IN ('DRAFT','REQUESTED','APPROVED','REJECTED','STALE','APPLIED')),CONSTRAINT ck_{$h}_adj_reason CHECK(CHAR_LENGTH(reason)>0)){$engine}",
            'erp_stock_adjustment_items'=>"CREATE TABLE IF NOT EXISTS {$items} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,adjustment_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NOT NULL,sku_snapshot VARCHAR(64) NOT NULL,name_snapshot VARCHAR(191) NOT NULL,unit_snapshot VARCHAR(32) NOT NULL,unit_step_snapshot DECIMAL(18,3) NOT NULL,expected_qty DECIMAL(18,3) NOT NULL,counted_qty DECIMAL(18,3) NOT NULL,delta_qty DECIMAL(18,3) NOT NULL,UNIQUE KEY adjustment_product(adjustment_id,product_id),KEY product_id(product_id),CONSTRAINT fk_{$h}_adji_adjustment FOREIGN KEY(adjustment_id) REFERENCES {$adjustments}(id) ON DELETE RESTRICT,CONSTRAINT fk_{$h}_adji_product FOREIGN KEY(product_id) REFERENCES {$products}(id) ON DELETE RESTRICT,CONSTRAINT ck_{$h}_adji_values CHECK(unit_step_snapshot>0 AND expected_qty>=0 AND counted_qty>=0 AND delta_qty=counted_qty-expected_qty)){$engine}",
        ];
    }

    private function assertTable(\mysqli $connection,string $table,string $suffix):void
    {
        $db=$connection->real_escape_string($this->db->dbname);$name=$connection->real_escape_string($table);$r=$connection->query("SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$name}'");$row=$r instanceof \mysqli_result?$r->fetch_assoc():null;if($r instanceof \mysqli_result)$r->free();if(!is_array($row)||strtoupper((string)$row['ENGINE'])!=='INNODB'||!str_starts_with(strtolower((string)$row['TABLE_COLLATION']),'utf8mb4_'))throw new RuntimeException('An existing inventory table is incompatible; it was left unchanged.');
        $r=$connection->query("SELECT COLUMN_NAME,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$name}'");if(!$r instanceof \mysqli_result)throw new RuntimeException('Could not inspect inventory schema metadata.');$columns=[];while(($column=$r->fetch_assoc())!==null)$columns[(string)$column['COLUMN_NAME']]=strtolower((string)$column['COLUMN_TYPE']);$r->free();
        $expected=$suffix==='erp_stock_adjustments'?['id'=>'bigint unsigned','adjustment_no'=>'varchar(32)','warehouse_id'=>'bigint unsigned','status'=>'varchar(24)','requested_by'=>'bigint unsigned','approved_by'=>'bigint unsigned','applied_by'=>'bigint unsigned','reason'=>'varchar(1000)','requested_at'=>'datetime(6)','approved_at'=>'datetime(6)','applied_at'=>'datetime(6)','apply_request_key'=>'char(36)','version'=>'int unsigned','created_at'=>'datetime(6)','updated_at'=>'datetime(6)']:['id'=>'bigint unsigned','adjustment_id'=>'bigint unsigned','product_id'=>'bigint unsigned','sku_snapshot'=>'varchar(64)','name_snapshot'=>'varchar(191)','unit_snapshot'=>'varchar(32)','unit_step_snapshot'=>'decimal(18,3)','expected_qty'=>'decimal(18,3)','counted_qty'=>'decimal(18,3)','delta_qty'=>'decimal(18,3)'];
        foreach($expected as $column=>$type)if(($columns[$column]??null)!==$type)throw new RuntimeException('An existing inventory table is incompatible; it was left unchanged.');
        $unique=$suffix==='erp_stock_adjustments'?['adjustment_no'=>['adjustment_no'],'apply_request_key'=>['apply_request_key']]:['adjustment_product'=>['adjustment_id','product_id']];$r=$connection->query("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='{$db}' AND TABLE_NAME='{$name}' ORDER BY INDEX_NAME,SEQ_IN_INDEX");if(!$r instanceof \mysqli_result)throw new RuntimeException('Could not inspect inventory index metadata.');$indexes=[];while(($index=$r->fetch_assoc())!==null){$key=(string)$index['INDEX_NAME'];$indexes[$key]['columns'][]=(string)$index['COLUMN_NAME'];$indexes[$key]['unique']=(int)$index['NON_UNIQUE']===0;}$r->free();foreach($unique as $key=>$columns)if(($indexes[$key]['columns']??null)!==$columns||($indexes[$key]['unique']??false)!==true)throw new RuntimeException('An existing inventory table is missing a required unique index; it was left unchanged.');
    }

    private function scalar(\mysqli $connection,string $sql):mixed{$r=$connection->query($sql);if(!$r instanceof \mysqli_result)throw new RuntimeException('A required inventory schema operation failed.');$row=$r->fetch_row();$r->free();return $row[0]??null;}
}
