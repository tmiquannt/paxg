<?php
declare(strict_types=1);

use MiniERP\Application\Services\SalesOrderService;
use MiniERP\Application\Services\PurchaseOrderService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\AuditSchema;
use MiniERP\Infrastructure\Database\MasterSchema;
use MiniERP\Infrastructure\Database\SalesSchema;
use MiniERP\Infrastructure\Database\PurchaseSchema;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\RolesInstaller;

if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/wp-load.php')) { fwrite(STDERR, "Local WordPress CLI is required.\n"); exit(2); }
require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
global $wpdb;
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the local fixture database.');
$prefix = 'paxg_test_sales_' . bin2hex(random_bytes(5)) . '_';
$assert((bool) preg_match('/\Apaxg_test_sales_[a-f0-9]{10}_\z/D', $prefix), 'Generated sales fixture prefix is unsafe.');
$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST); $db->prefix = $prefix;
$tables = array_map(static fn (string $suffix): string => $prefix . 'erp_' . $suffix, ['categories','products','customers','suppliers','warehouses','audit_logs','sales_orders','sales_order_items','stock_balances','stock_movements','shipments','shipment_items','purchase_orders','purchase_order_items','goods_receipts','goods_receipt_items']);
$results = [];
$exit = 0;
$failureMessage = null;

try {
    (new MasterSchema($db))->migrate();
    (new AuditSchema($db))->migrate();
    (new SalesSchema($db))->migrate();
    (new PurchaseSchema($db))->migrate();
    foreach ($tables as $table) $assert((int) $db->get_var($db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $table)) === 1, 'An expected sales fixture table was not created.');
    $results[] = 'master, audit and six sales/inventory tables created in isolated prefix';

    $db->insert($prefix . 'erp_categories', ['code'=>'TEST','name'=>'Test']); $categoryId = (int) $db->insert_id;
    $db->insert($prefix . 'erp_products', ['sku'=>'TEST-1','name'=>'Test product','category_id'=>$categoryId,'unit'=>'ea','unit_step'=>'0.500','sale_price_vnd'=>100]); $productId = (int) $db->insert_id;
    $db->insert($prefix . 'erp_customers', ['code'=>'CUS-1','name'=>'Test customer']); $customerId = (int) $db->insert_id;
    $db->insert($prefix . 'erp_warehouses', ['code'=>'WH-1','name'=>'Test warehouse']); $warehouseId = (int) $db->insert_id;
    $db->insert($prefix . 'erp_stock_balances', ['warehouse_id'=>$warehouseId,'product_id'=>$productId,'quantity'=>'2.500']);
    $salesCaps = RolesInstaller::definitions()['erp_sales']['capabilities'];
    $warehouseCaps = RolesInstaller::definitions()['erp_warehouse']['capabilities'];
    $managerCaps = RolesInstaller::definitions()['erp_manager']['capabilities'];
    $sales = new Actor(10, 'erp_sales', $salesCaps);
    $wrongWarehouse = new Actor(11, 'erp_warehouse', $warehouseCaps, []);
    $warehouse = new Actor(12, 'erp_warehouse', $warehouseCaps, [$warehouseId]);
    $manager = new Actor(13, 'erp_manager', $managerCaps);
    $approver = new Actor(14, 'erp_manager', $managerCaps);
    $service = new SalesOrderService($db, new TransactionManager($db), new AuditLogger($prefix));

    $orderId = $service->createDraft($sales, $customerId, $warehouseId, [['product_id'=>$productId,'quantity'=>'2.000']]);
    $assert($service->transition($sales, $orderId, 'submit') === 'CONFIRMED', 'Zero-discount order should confirm under current default threshold.');
    $orderSnapshot = $db->get_row($db->prepare('SELECT subtotal_exact_vnd,rounding_adjustment_vnd,subtotal_vnd,total_vnd FROM `' . $prefix . 'erp_sales_orders` WHERE id=%d', $orderId), ARRAY_A);
    $assert($orderSnapshot['subtotal_exact_vnd'] === '200.000' && $orderSnapshot['rounding_adjustment_vnd'] === '0.000' && (int) $orderSnapshot['total_vnd'] === 200, 'Document price snapshots did not round and persist exactly.');
    $requestKey = wp_generate_uuid4();
    $shipmentOne = $service->ship($warehouse, $orderId, $requestKey, [['sales_item_id'=>1,'quantity'=>'1.000']]);
    $assert($shipmentOne > 0, 'First partial shipment was not persisted.');
    $row = $db->get_row($db->prepare('SELECT status,version FROM `' . $prefix . 'erp_sales_orders` WHERE id=%d', $orderId), ARRAY_A);
    $assert($row['status'] === 'PARTIALLY_SHIPPED' && (int) $row['version'] === 3, 'First shipment should leave an auditable partial order state.');
    try { $service->ship($warehouse, $orderId, wp_generate_uuid4(), [['sales_item_id'=>1,'quantity'=>'1.500']]); throw new RuntimeException('Over-shipment unexpectedly posted.'); }
    catch (DomainException) { }
    try { $service->ship($warehouse, $orderId, $requestKey, [['sales_item_id'=>1,'quantity'=>'1.000']]); throw new RuntimeException('Duplicate idempotency key unexpectedly posted.'); }
    catch (MiniERP\Infrastructure\Database\DatabaseException) { }
    $assert($service->ship($warehouse, $orderId, wp_generate_uuid4(), [['sales_item_id'=>1,'quantity'=>'1.000']]) > 0, 'Second shipment was not persisted.');
    $assert((string) $db->get_var($db->prepare('SELECT quantity FROM `' . $prefix . 'erp_stock_balances` WHERE warehouse_id=%d AND product_id=%d', $warehouseId, $productId)) === '0.500', 'Stock balance did not match both shipment postings.');
    $assert((int) $db->get_var($db->prepare('SELECT COUNT(*) FROM `' . $prefix . 'erp_stock_movements` WHERE source_type=%s AND source_id IN (SELECT id FROM `' . $prefix . 'erp_shipments` WHERE sales_order_id=%d)', 'SHIPMENT', $orderId)) === 2, 'Each shipment must have exactly one stock movement.');
    $results[] = 'exact money snapshots, partial shipments, overship guard, and unique idempotency key';

    try { $service->ship($wrongWarehouse, $orderId, wp_generate_uuid4(), [['sales_item_id'=>1,'quantity'=>'0.500']]); throw new RuntimeException('Unassigned warehouse actor unexpectedly shipped.'); }
    catch (DomainException) { }
    $results[] = 'warehouse scope is checked before posting';

    $discounted = $service->createDraft($sales, $customerId, $warehouseId, [['product_id'=>$productId,'quantity'=>'0.500']], '', '100');
    $assert($service->transition($sales, $discounted, 'submit') === 'PENDING_APPROVAL', 'Discount above the default zero-bps threshold must require approval.');
    $assert($service->transition($manager, $discounted, 'approve') === 'CONFIRMED', 'A different Manager should approve a pending discount order.');
    $assert((int) $db->get_var($db->prepare('SELECT approved_by FROM `' . $prefix . 'erp_sales_orders` WHERE id=%d', $discounted)) === $manager->id, 'Approver identity was not persisted.');
    $results[] = 'discount approval requires a second Manager identity';

    $insufficient = $service->createDraft($sales, $customerId, $warehouseId, [['product_id'=>$productId,'quantity'=>'1.000']]);
    $service->transition($sales, $insufficient, 'submit');
    try { $service->ship($warehouse, $insufficient, wp_generate_uuid4(), [['sales_item_id'=>3,'quantity'=>'1.000']]); throw new RuntimeException('Insufficient stock unexpectedly shipped.'); }
    catch (DomainException) { }
    $assert((string) $db->get_var($db->prepare('SELECT quantity FROM `' . $prefix . 'erp_stock_balances` WHERE warehouse_id=%d AND product_id=%d', $warehouseId, $productId)) === '0.500', 'Failed shipment changed the stock balance.');
    $assert((int) $db->get_var($db->prepare('SELECT COUNT(*) FROM `' . $prefix . 'erp_shipments` WHERE sales_order_id=%d', $insufficient)) === 0, 'Failed shipment left a partial document.');
    $results[] = 'insufficient stock rolls back shipment header and stock changes';

    $purchase = new PurchaseOrderService($db, new TransactionManager($db), new AuditLogger($prefix));
    $db->insert($prefix . 'erp_suppliers', ['code'=>'SUP-1','name'=>'Test supplier']); $supplierId=(int)$db->insert_id;
    $poId=$purchase->createDraft($manager,$supplierId,$warehouseId,[['product_id'=>$productId,'unit_cost_vnd'=>'333','quantity'=>'1.500']]);
    $assert($purchase->transition($manager,$poId,'submit')==='PENDING_APPROVAL','Purchase order submit did not require approval.');
    $assert($purchase->transition($approver,$poId,'approve')==='APPROVED','A second manager could not approve the purchase order.');
    $receiptOne=$purchase->receive($warehouse,$poId,wp_generate_uuid4(),[['purchase_item_id'=>1,'quantity'=>'1.000']]);
    $poStatus=$db->get_var($db->prepare('SELECT status FROM `' . $prefix . 'erp_purchase_orders` WHERE id=%d',$poId));
    $assert($poStatus==='PARTIALLY_RECEIVED','First receipt should leave a partial PO.');
    $purchase->receive($warehouse,$poId,wp_generate_uuid4(),[['purchase_item_id'=>1,'quantity'=>'0.500']]);
    $assert((string)$db->get_var($db->prepare('SELECT quantity FROM `' . $prefix . 'erp_stock_balances` WHERE warehouse_id=%d AND product_id=%d',$warehouseId,$productId))==='2.000','Goods receipt did not increase the stock ledger.');
    $assert((int)$db->get_var($db->prepare('SELECT COUNT(*) FROM `' . $prefix . 'erp_stock_movements` WHERE source_type=%s AND source_id IN (SELECT id FROM `' . $prefix . 'erp_goods_receipts` WHERE purchase_order_id=%d)','GOODS_RECEIPT',$poId))===2,'Each goods receipt must have one inbound stock movement.');
    try{$purchase->receive($wrongWarehouse,$poId,wp_generate_uuid4(),[['purchase_item_id'=>1,'quantity'=>'0.500']]);throw new RuntimeException('Unassigned warehouse actor unexpectedly received goods.');}catch(DomainException){}
    $results[]='maker-checker purchase approval and partial receipts replenish stock with ledger rows';

    (new SalesSchema($db))->migrate();
    $assert((int) $db->get_var($db->prepare('SELECT COUNT(*) FROM `' . $prefix . 'erp_sales_orders`')) === 3, 'Repeat migration altered saved orders.');
    (new PurchaseSchema($db))->migrate();
    $assert((int)$db->get_var($db->prepare('SELECT COUNT(*) FROM `' . $prefix . 'erp_purchase_orders`'))===1,'Repeat migration altered purchase records.');
    $results[] = 'sales and purchase schema repeat migrations preserve transaction rows';
} catch (Throwable $failure) {
    $exit = 1;
    $failureMessage = 'Sales workflow smoke failed: ' . get_class($failure) . ' (' . $failure->getCode() . ') ' . $failure->getMessage();
} finally {
    foreach (array_reverse($tables) as $table) {
        if (!preg_match('/\A' . preg_quote($prefix, '/') . 'erp_[a-z_]+\z/D', $table)) { $exit = 1; continue; }
        if ($db->query('DROP TABLE IF EXISTS `' . $table . '`') === false) { $exit = 1; continue; }
        $remaining = $db->get_var($db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', DB_NAME, $table));
        if ((string) $remaining !== '0') { $exit = 1; $failureMessage = 'Exact sales fixture cleanup could not be verified.'; }
    }
    $db->close();
}
if ($failureMessage !== null) fwrite(STDERR, $failureMessage . "\n");
foreach ($results as $result) fwrite(STDOUT, "PASS: {$result}\n");
if (!empty($exit)) exit($exit);
fwrite(STDOUT, "Sales workflow smoke passed; exact fixture tables cleaned.\n");
