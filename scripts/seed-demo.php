<?php
declare(strict_types=1);

/** Add a fictitious, role-scoped ERP demonstration dataset to a local WordPress site. */

use MiniERP\Application\Services\CatalogService;
use MiniERP\Application\Services\InvoiceService;
use MiniERP\Application\Services\PartyService;
use MiniERP\Application\Services\PaymentService;
use MiniERP\Application\Services\PurchaseOrderService;
use MiniERP\Application\Services\SalesOrderService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\RolesInstaller;
use MiniERP\Bootstrap;

const ERP_DEMO_SEED_VERSION = '2026.10.21.1';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Demo seed runs from CLI only.\n");
    exit(2);
}

$wordpressRoot = null;
$dryRun = false;
for ($index = 1; $index < $argc; ++$index) {
    $argument = $argv[$index];
    if ($argument === '--dry-run') {
        $dryRun = true;
    } elseif ($argument === '--wordpress-root' && isset($argv[$index + 1])) {
        $wordpressRoot = $argv[++$index];
    } elseif (str_starts_with($argument, '--wordpress-root=')) {
        $wordpressRoot = substr($argument, strlen('--wordpress-root='));
    } else {
        fwrite(STDERR, "Usage: php scripts/seed-demo.php [--dry-run] [--wordpress-root PATH]\n");
        exit(2);
    }
}

if ($wordpressRoot === null || $wordpressRoot === '') {
    $candidates = ['/var/www/html', dirname(__DIR__) . '/.runtime/wordpress'];
    foreach ($candidates as $candidate) {
        if (is_file(rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'wp-load.php')) {
            $wordpressRoot = $candidate;
            break;
        }
    }
}
$wpLoad = is_string($wordpressRoot) && $wordpressRoot !== ''
    ? rtrim($wordpressRoot, "\\/") . DIRECTORY_SEPARATOR . 'wp-load.php'
    : '';
if ($wpLoad === '' || !is_file($wpLoad)) {
    fwrite(STDERR, "WordPress wp-load.php was not found. Pass --wordpress-root PATH.\n");
    exit(2);
}
if (PHP_VERSION_ID < 80300 || !extension_loaded('mysqli') || !extension_loaded('bcmath')
    || !extension_loaded('dom') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "Mini ERP needs PHP 8.3+ with mysqli, BCMath, DOM and mbstring.\n");
    exit(2);
}

require $wpLoad;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

try {
    if (wp_get_environment_type() !== 'local') {
        throw new RuntimeException('Set WP_ENVIRONMENT_TYPE to local before seeding demo data.');
    }
    $siteHost = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
    if (!in_array($siteHost, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Demo data can only be seeded on localhost or loopback.');
    }
    if (!is_plugin_active('mini-erp/mini-erp.php') || !Bootstrap::schemaReady()) {
        throw new RuntimeException('Activate Mini ERP and finish its database upgrade before seeding.');
    }
    global $wpdb;
    if (!$wpdb instanceof wpdb || !is_string($wpdb->dbname) || $wpdb->dbname === ''
        || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', (string) $wpdb->prefix)) {
        throw new RuntimeException('The WordPress database configuration is not supported.');
    }

    $mysqlVersion = (string) $wpdb->get_var('SELECT VERSION()');
    if (stripos($mysqlVersion, 'mariadb') !== false
        || !preg_match('/\A(\d+)\.(\d+)\.(\d+)/', $mysqlVersion, $mysqlParts)
        || (int) $mysqlParts[1] < 8
        || ((int) $mysqlParts[1] === 8 && (int) $mysqlParts[2] === 0 && (int) $mysqlParts[3] < 16)) {
        throw new RuntimeException('Mini ERP requires MySQL 8.0.16+; XAMPP MariaDB is not supported by this migration.');
    }

    if ($dryRun) {
        echo json_encode([
            'ready' => true,
            'dry_run' => true,
            'site' => home_url('/'),
            'database' => $wpdb->dbname,
            'prefix' => $wpdb->prefix,
            'php' => PHP_VERSION,
            'mysql' => $mysqlVersion,
            'message' => 'Preflight passed; no users, settings or business rows were changed.',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }

    $lockName = 'mini_erp_demo_seed_' . substr(hash('sha256', $wpdb->dbname . ':' . $wpdb->prefix), 0, 32);
    if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5)) !== '1') {
        throw new RuntimeException('Could not acquire the local demo-seed lock.');
    }
    try {
        $result = seedDemoDataset($wpdb);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
    } finally {
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName)) !== '1') {
            throw new RuntimeException('The local demo-seed lock could not be released cleanly.');
        }
    }
} catch (Throwable $failure) {
    fwrite(STDERR, 'Demo seed stopped: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}

/** @return array<string, mixed> */
function seedDemoDataset(wpdb $db): array
{
    if (get_option('mini_erp_demo_seed_version', '') === ERP_DEMO_SEED_VERSION) {
        return [
            'seeded' => true,
            'already_seeded' => true,
            'version' => ERP_DEMO_SEED_VERSION,
            'message' => 'Demo data already exists. Existing records and passwords were left unchanged.',
        ];
    }

    foreach (['erp_manager', 'erp_sales', 'erp_warehouse', 'erp_accountant'] as $role) {
        if (!get_role($role)) {
            throw new RuntimeException('Mini ERP roles are missing. Reactivate the plugin before seeding.');
        }
    }
    $capabilities = RolesInstaller::definitions();
    $createdPasswords = [];
    $users = [];
    $userDefinitions = [
        'manager' => ['login' => 'demo_manager', 'email' => 'manager@demo.invalid', 'name' => 'Demo Quản lý', 'role' => 'erp_manager'],
        'approver' => ['login' => 'demo_manager_duyet', 'email' => 'approver@demo.invalid', 'name' => 'Demo Quản lý duyệt', 'role' => 'erp_manager'],
        'sales' => ['login' => 'demo_sales', 'email' => 'sales@demo.invalid', 'name' => 'Demo Nhân viên bán hàng', 'role' => 'erp_sales'],
        'warehouse' => ['login' => 'demo_kho', 'email' => 'warehouse@demo.invalid', 'name' => 'Demo Nhân viên kho', 'role' => 'erp_warehouse'],
        'accountant' => ['login' => 'demo_ketoan', 'email' => 'accountant@demo.invalid', 'name' => 'Demo Kế toán', 'role' => 'erp_accountant'],
    ];
    foreach ($userDefinitions as $key => $definition) {
        $users[$key] = ensureDemoUser($definition, $createdPasswords);
    }

    $managerBase = makeActor($users['manager'], 'erp_manager', $capabilities, [], []);
    $catalog = new CatalogService($db, new TransactionManager($db), new AuditLogger());
    $parties = new PartyService($db, new TransactionManager($db), new AuditLogger());

    $categories = [
        'DEMO-OFFICE' => ['name' => 'Hàng văn phòng phẩm'],
        'DEMO-DEVICE' => ['name' => 'Thiết bị văn phòng'],
    ];
    $categoryIds = [];
    foreach ($categories as $code => $values) {
        $categoryIds[$code] = ensureCategory($db, $catalog, $managerBase, $code, $values['name']);
    }

    $warehouses = [
        'DEMO-HN' => 'Kho demo Hà Nội',
        'DEMO-HCM' => 'Kho demo TP. Hồ Chí Minh',
    ];
    $warehouseIds = [];
    foreach ($warehouses as $code => $name) {
        $warehouseIds[$code] = ensureWarehouse($db, $parties, $managerBase, $code, $name);
    }

    $customers = [
        'DEMO-CUST-01' => ['name' => 'Công ty Demo Sao Mai', 'tax_id' => 'DEMO-CUST-001', 'phone' => '0900000001', 'email' => 'customer1@demo.invalid', 'address' => 'Quận Cầu Giấy, Hà Nội'],
        'DEMO-CUST-02' => ['name' => 'Cửa hàng Demo Bình Minh', 'tax_id' => '', 'phone' => '0900000002', 'email' => 'customer2@demo.invalid', 'address' => 'Quận 1, TP. Hồ Chí Minh'],
    ];
    $customerIds = [];
    foreach ($customers as $code => $values) {
        $customerIds[$code] = ensureParty($db, $parties, $managerBase, 'customer', $code, $values);
    }

    $suppliers = [
        'DEMO-SUP-01' => ['name' => 'Nhà cung cấp Demo Á Châu', 'tax_id' => 'DEMO-SUP-001', 'phone' => '02800000001', 'email' => 'supplier1@demo.invalid', 'address' => 'Quận Bình Thạnh, TP. Hồ Chí Minh'],
        'DEMO-SUP-02' => ['name' => 'Nhà cung cấp Demo Bắc Việt', 'tax_id' => 'DEMO-SUP-002', 'phone' => '02400000002', 'email' => 'supplier2@demo.invalid', 'address' => 'Quận Long Biên, Hà Nội'],
    ];
    $supplierIds = [];
    foreach ($suppliers as $code => $values) {
        $supplierIds[$code] = ensureParty($db, $parties, $managerBase, 'supplier', $code, $values);
    }

    $products = [
        'DEMO-PAPER-A4' => ['name' => 'Giấy A4 70gsm', 'category_id' => $categoryIds['DEMO-OFFICE'], 'unit' => 'ram', 'unit_step' => '1.000', 'sale_price_vnd' => '78000'],
        'DEMO-PEN-BLUE' => ['name' => 'Bút bi xanh', 'category_id' => $categoryIds['DEMO-OFFICE'], 'unit' => 'cái', 'unit_step' => '1.000', 'sale_price_vnd' => '6000'],
        'DEMO-MOUSE' => ['name' => 'Chuột không dây', 'category_id' => $categoryIds['DEMO-DEVICE'], 'unit' => 'cái', 'unit_step' => '1.000', 'sale_price_vnd' => '250000'],
        'DEMO-KEYBOARD' => ['name' => 'Bàn phím văn phòng', 'category_id' => $categoryIds['DEMO-DEVICE'], 'unit' => 'cái', 'unit_step' => '1.000', 'sale_price_vnd' => '350000'],
    ];
    $productIds = [];
    foreach ($products as $sku => $values) {
        $productIds[$sku] = ensureProduct($db, $catalog, $managerBase, $sku, $values);
    }

    $accountantBase = makeActor($users['accountant'], 'erp_accountant', $capabilities, [], []);
    $cashAccounts = [
        'DEMO-CASH' => ['name' => 'Quỹ tiền mặt demo', 'type' => 'CASH', 'opening' => '10000000'],
        'DEMO-BANK' => ['name' => 'Ngân hàng demo', 'type' => 'BANK', 'opening' => '80000000'],
    ];
    $cashAccountIds = [];
    foreach ($cashAccounts as $code => $values) {
        $cashAccountIds[$code] = ensureCashAccount($db, $accountantBase, $users['manager'], $capabilities, $code, $values);
    }

    setDemoScopes($users['manager'], array_values($warehouseIds), array_values($cashAccountIds));
    setDemoScopes($users['approver'], array_values($warehouseIds), array_values($cashAccountIds));
    setDemoScopes($users['sales'], [$warehouseIds['DEMO-HN']], []);
    setDemoScopes($users['warehouse'], [$warehouseIds['DEMO-HN']], []);
    setDemoScopes($users['accountant'], [], array_values($cashAccountIds));

    $manager = makeActor($users['manager'], 'erp_manager', $capabilities, array_values($warehouseIds), array_values($cashAccountIds));
    $approver = makeActor($users['approver'], 'erp_manager', $capabilities, array_values($warehouseIds), array_values($cashAccountIds));
    $salesActor = makeActor($users['sales'], 'erp_sales', $capabilities, [$warehouseIds['DEMO-HN']], []);
    $warehouseActor = makeActor($users['warehouse'], 'erp_warehouse', $capabilities, [$warehouseIds['DEMO-HN']], []);
    $accountant = makeActor($users['accountant'], 'erp_accountant', $capabilities, [], array_values($cashAccountIds));

    seedCompanyProfile();
    $transactions = new TransactionManager($db);
    $audit = new AuditLogger();
    $purchaseOrders = new PurchaseOrderService($db, $transactions, $audit);
    $salesOrders = new SalesOrderService($db, $transactions, $audit);
    $invoices = new InvoiceService($db, $transactions, $audit);
    $payments = new PaymentService($db, $transactions, $audit);
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d\\TH:i');
    $dueDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+30 days')->format('Y-m-d');

    $purchaseOrderId = ensureDemoPurchase($db, $purchaseOrders, $invoices, $payments, $manager, $approver, $warehouseActor, $accountant,
        $supplierIds['DEMO-SUP-01'], $warehouseIds['DEMO-HN'], $productIds['DEMO-PAPER-A4'], $cashAccountIds['DEMO-BANK'], $now, $dueDate);
    $salesOrderId = ensureDemoSale($db, $salesOrders, $invoices, $payments, $salesActor, $warehouseActor, $accountant,
        $customerIds['DEMO-CUST-01'], $warehouseIds['DEMO-HN'], $productIds['DEMO-PAPER-A4'], $cashAccountIds['DEMO-BANK'], $now, $dueDate);
    ensureDemoExpense($db, $payments, $accountant, $cashAccountIds['DEMO-CASH'], $now);

    update_option('mini_erp_demo_seed_version', ERP_DEMO_SEED_VERSION, false);
    foreach ($users as $userId) {
        if (function_exists('clean_user_cache')) clean_user_cache($userId);
    }

    return [
        'seeded' => true,
        'already_seeded' => false,
        'version' => ERP_DEMO_SEED_VERSION,
        'created_demo_users' => array_keys($createdPasswords),
        'logins' => array_map(static fn (array $definition): string => $definition['login'], $userDefinitions),
        'new_passwords' => $createdPasswords,
        'master_data' => ['categories' => count($categoryIds), 'products' => count($productIds), 'customers' => count($customerIds), 'suppliers' => count($supplierIds), 'warehouses' => count($warehouseIds), 'cash_accounts' => count($cashAccountIds)],
        'example_documents' => ['purchase_order_id' => $purchaseOrderId, 'sales_order_id' => $salesOrderId],
        'message' => 'Fictitious local demo dataset created through ERP services; existing users and business records were preserved.',
    ];
}

/** @param array<string,string> $createdPasswords */
function ensureDemoUser(array $definition, array &$createdPasswords): int
{
    $user = get_user_by('login', $definition['login']);
    if ($user instanceof WP_User) {
        if (get_user_meta((int) $user->ID, '_mini_erp_demo_seed', true) !== ERP_DEMO_SEED_VERSION
            || (string) $user->user_email !== $definition['email']
            || array_values(array_intersect(array_keys(RolesInstaller::definitions()), (array) $user->roles)) !== [$definition['role']]
            || in_array('administrator', (array) $user->roles, true)) {
            throw new RuntimeException('A demo account name is already used by an account the seeder does not own; no account was changed.');
        }
        update_user_meta((int) $user->ID, '_mini_erp_enabled', '1');
        return (int) $user->ID;
    }

    $password = wp_generate_password(24, true, true);
    $userId = wp_insert_user([
        'user_login' => $definition['login'],
        'user_pass' => $password,
        'user_email' => $definition['email'],
        'display_name' => $definition['name'],
        'role' => $definition['role'],
    ]);
    if (is_wp_error($userId) || (int) $userId < 1) {
        throw new RuntimeException('Could not create a local ERP demo user.');
    }
    update_user_meta((int) $userId, '_mini_erp_demo_seed', ERP_DEMO_SEED_VERSION);
    update_user_meta((int) $userId, '_mini_erp_enabled', '1');
    update_user_meta((int) $userId, '_mini_erp_warehouse_ids', []);
    update_user_meta((int) $userId, '_mini_erp_cash_account_ids', []);
    $createdPasswords[$definition['login']] = $password;
    return (int) $userId;
}

function makeActor(int $userId, string $role, array $definitions, array $warehouseIds, array $cashIds): Actor
{
    return new Actor($userId, $role, $definitions[$role]['capabilities'], array_values($warehouseIds), array_values($cashIds));
}

function setDemoScopes(int $userId, array $warehouseIds, array $cashIds): void
{
    update_user_meta($userId, '_mini_erp_enabled', '1');
    update_user_meta($userId, '_mini_erp_warehouse_ids', array_values(array_map('intval', $warehouseIds)));
    update_user_meta($userId, '_mini_erp_cash_account_ids', array_values(array_map('intval', $cashIds)));
}

function tableName(wpdb $db, string $suffix): string
{
    if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', (string) $db->prefix)
        || !in_array($suffix, ['erp_categories', 'erp_products', 'erp_customers', 'erp_suppliers', 'erp_warehouses', 'erp_cash_accounts', 'erp_purchase_orders', 'erp_purchase_order_items', 'erp_goods_receipts', 'erp_invoices', 'erp_payments', 'erp_sales_orders', 'erp_sales_order_items', 'erp_shipments', 'erp_expenses'], true)) {
        throw new RuntimeException('The ERP database table configuration is invalid.');
    }
    return '`' . $db->prefix . $suffix . '`';
}

/** @return array<string,mixed>|null */
function oneRow(wpdb $db, string $sql, mixed ...$arguments): ?array
{
    $prepared = $arguments === [] ? $sql : $db->prepare($sql, ...$arguments);
    $row = $db->get_row($prepared, ARRAY_A);
    if ($db->last_error !== '') throw new RuntimeException('Could not read the local demo data.');
    return is_array($row) ? $row : null;
}

/** @return list<array<string,mixed>> */
function manyRows(wpdb $db, string $sql, mixed ...$arguments): array
{
    $prepared = $arguments === [] ? $sql : $db->prepare($sql, ...$arguments);
    $rows = $db->get_results($prepared, ARRAY_A);
    if ($db->last_error !== '' || !is_array($rows)) throw new RuntimeException('Could not read the local demo data.');
    return $rows;
}

function assertValues(array $row, array $expected): void
{
    foreach ($expected as $key => $value) {
        if (!array_key_exists($key, $row) || (string) $row[$key] !== (string) $value) {
            throw new RuntimeException('An existing DEMO record conflicts with the expected demo data; it was left unchanged.');
        }
    }
}

function ensureCategory(wpdb $db, CatalogService $service, Actor $actor, string $code, string $name): int
{
    $table = tableName($db, 'erp_categories');
    $row = oneRow($db, "SELECT id,code,name,is_active FROM {$table} WHERE code=%s", $code);
    if ($row !== null) { assertValues($row, ['code' => $code, 'name' => $name, 'is_active' => 1]); return (int) $row['id']; }
    return $service->createCategory($actor, $code, $name);
}

function ensureWarehouse(wpdb $db, PartyService $service, Actor $actor, string $code, string $name): int
{
    $table = tableName($db, 'erp_warehouses');
    $row = oneRow($db, "SELECT id,code,name,is_active FROM {$table} WHERE code=%s", $code);
    if ($row !== null) { assertValues($row, ['code' => $code, 'name' => $name, 'is_active' => 1]); return (int) $row['id']; }
    return $service->save($actor, 'warehouse', ['code' => $code, 'name' => $name]);
}

function ensureParty(wpdb $db, PartyService $service, Actor $actor, string $entity, string $code, array $values): int
{
    $suffix = $entity === 'customer' ? 'erp_customers' : 'erp_suppliers';
    $table = tableName($db, $suffix);
    $row = oneRow($db, "SELECT id,code,name,tax_id,phone,email,address,is_active FROM {$table} WHERE code=%s", $code);
    if ($row !== null) { assertValues($row, $values + ['code' => $code, 'is_active' => 1]); return (int) $row['id']; }
    return $service->save($actor, $entity, ['code' => $code] + $values);
}

function ensureProduct(wpdb $db, CatalogService $service, Actor $actor, string $sku, array $values): int
{
    $table = tableName($db, 'erp_products');
    $row = oneRow($db, "SELECT id,sku,name,category_id,unit,unit_step,sale_price_vnd,is_active FROM {$table} WHERE sku=%s", $sku);
    if ($row !== null) { assertValues($row, $values + ['sku' => $sku, 'is_active' => 1]); return (int) $row['id']; }
    return $service->saveProduct($actor, ['sku' => $sku] + $values);
}

function ensureCashAccount(wpdb $db, Actor $accountant, int $managerUserId, array $capabilities, string $code, array $values): int
{
    $table = tableName($db, 'erp_cash_accounts');
    $row = oneRow($db, "SELECT id,account_code,name,type,opening_balance_vnd,status,created_by FROM {$table} WHERE account_code=%s", $code);
    if ($row === null) {
        $id = (new PaymentService($db, new TransactionManager($db), new AuditLogger()))->createCashAccount($accountant, $code, $values['name'], $values['type'], $values['opening']);
        $row = oneRow($db, "SELECT id,account_code,name,type,opening_balance_vnd,status,created_by FROM {$table} WHERE id=%d", $id);
        if ($row === null) throw new RuntimeException('The demo cash account was not saved.');
    }
    assertValues($row, ['account_code' => $code, 'name' => $values['name'], 'type' => $values['type'], 'opening_balance_vnd' => $values['opening']]);
    if ((string) $row['status'] === 'DRAFT') {
        if ((int) $row['created_by'] !== $accountant->id) throw new RuntimeException('A DEMO cash account is awaiting approval by someone else; it was left unchanged.');
        $manager = makeActor($managerUserId, 'erp_manager', $capabilities, [], [(int) $row['id']]);
        (new PaymentService($db, new TransactionManager($db), new AuditLogger()))->activateCashAccount($manager, (int) $row['id']);
    } elseif ((string) $row['status'] !== 'ACTIVE') {
        throw new RuntimeException('A DEMO cash account is inactive; it was left unchanged.');
    }
    return (int) $row['id'];
}

function seedCompanyProfile(): void
{
    foreach (['erp_company_name' => 'Công ty Demo Mini ERP', 'erp_company_address' => 'Địa chỉ mẫu — môi trường local', 'erp_company_tax_id' => 'DEMO-LOCAL-001'] as $key => $value) {
        if (trim((string) get_option($key, '')) === '') update_option($key, $value, false);
    }
}

function markerRow(wpdb $db, string $suffix, string $field, string $marker): ?array
{
    if (!in_array($field, ['note'], true)) throw new RuntimeException('The demo document marker is invalid.');
    $table = tableName($db, $suffix);
    $rows = manyRows($db, "SELECT id,status,{$field} FROM {$table} WHERE {$field}=%s ORDER BY id LIMIT 2", $marker);
    if (count($rows) > 1) throw new RuntimeException('Duplicate demo document markers were found; no document was changed.');
    return $rows[0] ?? null;
}

function ensureDemoPurchase(wpdb $db, PurchaseOrderService $orders, InvoiceService $invoices, PaymentService $payments, Actor $manager, Actor $approver, Actor $warehouse, Actor $accountant, int $supplierId, int $warehouseId, int $productId, int $cashId, string $now, string $dueDate): int
{
    $marker = '[ERP-DEMO-SEED-v1] stock replenishment';
    $table = tableName($db, 'erp_purchase_orders');
    $row = markerRow($db, 'erp_purchase_orders', 'note', $marker);
    if ($row === null) {
        $id = $orders->createDraft($manager, $supplierId, $warehouseId, [['product_id' => $productId, 'unit_cost_vnd' => '55000', 'quantity' => '100.000']], $marker);
    } else {
        $id = (int) $row['id'];
        $existing = oneRow($db, "SELECT id,created_by,supplier_id,warehouse_id FROM {$table} WHERE id=%d", $id);
        assertValues($existing ?? [], ['created_by' => $manager->id, 'supplier_id' => $supplierId, 'warehouse_id' => $warehouseId]);
    }
    $row = oneRow($db, "SELECT status FROM {$table} WHERE id=%d", $id) ?? throw new RuntimeException('Demo purchase order was not found.');
    if ($row['status'] === 'DRAFT') $orders->transition($manager, $id, 'submit');
    $row = oneRow($db, "SELECT status FROM {$table} WHERE id=%d", $id) ?? throw new RuntimeException('Demo purchase order was not found.');
    if ($row['status'] === 'PENDING_APPROVAL') $orders->transition($approver, $id, 'approve');
    $row = oneRow($db, "SELECT status FROM {$table} WHERE id=%d", $id) ?? throw new RuntimeException('Demo purchase order was not found.');
    if ($row['status'] === 'APPROVED') {
        $receiptTable = tableName($db, 'erp_goods_receipts');
        $receipt = oneRow($db, "SELECT id FROM {$receiptTable} WHERE purchase_order_id=%d", $id);
        if ($receipt === null) {
            $itemTable = tableName($db, 'erp_purchase_order_items');
            $items = manyRows($db, "SELECT id,quantity FROM {$itemTable} WHERE po_id=%d ORDER BY id", $id);
            if (count($items) !== 1) throw new RuntimeException('Demo purchase lines are not in the expected state.');
            $orders->receive($warehouse, $id, deterministicUuid('goods-receipt'), [['purchase_item_id' => (int) $items[0]['id'], 'quantity' => (string) $items[0]['quantity']]]);
            $receipt = oneRow($db, "SELECT id FROM {$receiptTable} WHERE purchase_order_id=%d", $id);
        }
        if ($receipt === null) throw new RuntimeException('Demo goods receipt was not saved.');
    } elseif (!in_array((string) $row['status'], ['RECEIVED', 'CLOSED'], true)) {
        throw new RuntimeException('Demo purchase order needs manual review before the seed can continue.');
    }
    $receiptTable = tableName($db, 'erp_goods_receipts');
    $receipt = oneRow($db, "SELECT id FROM {$receiptTable} WHERE purchase_order_id=%d ORDER BY id LIMIT 1", $id);
    if ($receipt === null) throw new RuntimeException('Demo goods receipt was not found.');
    $invoiceTable = tableName($db, 'erp_invoices');
    $invoice = oneRow($db, "SELECT id,total_vnd FROM {$invoiceTable} WHERE source_type='GOODS_RECEIPT' AND source_id=%d", (int) $receipt['id']);
    if ($invoice === null) {
        $invoiceId = $invoices->issueFromGoodsReceipt($accountant, (int) $receipt['id'], $dueDate);
        $invoice = oneRow($db, "SELECT id,total_vnd FROM {$invoiceTable} WHERE id=%d", $invoiceId);
    }
    if ($invoice === null) throw new RuntimeException('Demo purchase invoice was not saved.');
    ensureDemoPayment($db, $payments, $accountant, (int) $invoice['id'], $cashId, (string) $invoice['total_vnd'], 'DEMO-PO-PAYMENT', $now);
    return $id;
}

function ensureDemoSale(wpdb $db, SalesOrderService $orders, InvoiceService $invoices, PaymentService $payments, Actor $sales, Actor $warehouse, Actor $accountant, int $customerId, int $warehouseId, int $productId, int $cashId, string $now, string $dueDate): int
{
    $marker = '[ERP-DEMO-SEED-v1] sample customer sale';
    $table = tableName($db, 'erp_sales_orders');
    $row = markerRow($db, 'erp_sales_orders', 'note', $marker);
    if ($row === null) {
        $id = $orders->createDraft($sales, $customerId, $warehouseId, [['product_id' => $productId, 'quantity' => '3.000']], $marker);
    } else {
        $id = (int) $row['id'];
        $existing = oneRow($db, "SELECT id,created_by,customer_id,warehouse_id FROM {$table} WHERE id=%d", $id);
        assertValues($existing ?? [], ['created_by' => $sales->id, 'customer_id' => $customerId, 'warehouse_id' => $warehouseId]);
    }
    $row = oneRow($db, "SELECT status FROM {$table} WHERE id=%d", $id) ?? throw new RuntimeException('Demo sales order was not found.');
    if ($row['status'] === 'DRAFT') $orders->transition($sales, $id, 'submit');
    $row = oneRow($db, "SELECT status FROM {$table} WHERE id=%d", $id) ?? throw new RuntimeException('Demo sales order was not found.');
    if (in_array((string) $row['status'], ['CONFIRMED', 'PARTIALLY_SHIPPED'], true)) {
        $shipmentTable = tableName($db, 'erp_shipments');
        $shipment = oneRow($db, "SELECT id FROM {$shipmentTable} WHERE sales_order_id=%d ORDER BY id LIMIT 1", $id);
        if ($shipment === null) {
            $itemTable = tableName($db, 'erp_sales_order_items');
            $items = manyRows($db, "SELECT id,quantity FROM {$itemTable} WHERE order_id=%d ORDER BY id", $id);
            if (count($items) !== 1) throw new RuntimeException('Demo sales lines are not in the expected state.');
            $orders->ship($warehouse, $id, deterministicUuid('shipment'), [['sales_item_id' => (int) $items[0]['id'], 'quantity' => (string) $items[0]['quantity']]]);
            $shipment = oneRow($db, "SELECT id FROM {$shipmentTable} WHERE sales_order_id=%d ORDER BY id LIMIT 1", $id);
        }
        if ($shipment === null) throw new RuntimeException('Demo shipment was not saved.');
    } elseif (!in_array((string) $row['status'], ['SHIPPED', 'CLOSED'], true)) {
        throw new RuntimeException('Demo sales order needs manual review before the seed can continue.');
    }
    $shipmentTable = tableName($db, 'erp_shipments');
    $shipment = oneRow($db, "SELECT id FROM {$shipmentTable} WHERE sales_order_id=%d ORDER BY id LIMIT 1", $id);
    if ($shipment === null) throw new RuntimeException('Demo shipment was not found.');
    $invoiceTable = tableName($db, 'erp_invoices');
    $invoice = oneRow($db, "SELECT id,total_vnd FROM {$invoiceTable} WHERE source_type='SHIPMENT' AND source_id=%d", (int) $shipment['id']);
    if ($invoice === null) {
        $invoiceId = $invoices->issueFromShipment($accountant, (int) $shipment['id'], $dueDate);
        $invoice = oneRow($db, "SELECT id,total_vnd FROM {$invoiceTable} WHERE id=%d", $invoiceId);
    }
    if ($invoice === null) throw new RuntimeException('Demo sales invoice was not saved.');
    ensureDemoPayment($db, $payments, $accountant, (int) $invoice['id'], $cashId, (string) $invoice['total_vnd'], 'DEMO-SO-PAYMENT', $now);
    return $id;
}

function ensureDemoPayment(wpdb $db, PaymentService $service, Actor $accountant, int $invoiceId, int $cashId, string $amount, string $reference, string $paidAt): void
{
    $table = tableName($db, 'erp_payments');
    $requestKey = deterministicUuid(strtolower($reference));
    $row = oneRow($db, "SELECT id,invoice_id,cash_account_id,amount_vnd,status,request_key FROM {$table} WHERE request_key=%s", $requestKey);
    if ($row !== null) {
        assertValues($row, ['invoice_id' => $invoiceId, 'cash_account_id' => $cashId, 'amount_vnd' => $amount, 'status' => 'POSTED']);
        return;
    }
    $service->post($accountant, $invoiceId, $cashId, $amount, 'BANK_TRANSFER', $reference, $paidAt, $requestKey);
}

function ensureDemoExpense(wpdb $db, PaymentService $service, Actor $accountant, int $cashId, string $occurredAt): void
{
    $table = tableName($db, 'erp_expenses');
    $requestKey = deterministicUuid('expense-demo-office');
    $row = oneRow($db, "SELECT id,cash_account_id,category,description,amount_vnd,status,request_key FROM {$table} WHERE request_key=%s", $requestKey);
    if ($row !== null) {
        assertValues($row, ['cash_account_id' => $cashId, 'category' => 'OFFICE', 'description' => 'Chi phí văn phòng demo', 'amount_vnd' => '1250000', 'status' => 'POSTED']);
        return;
    }
    $service->postExpense($accountant, $cashId, 'OFFICE', 'Chi phí văn phòng demo', '1250000', $occurredAt, $requestKey);
}

function deterministicUuid(string $name): string
{
    $bytes = substr(hash('sha256', 'mini-erp-demo:' . ERP_DEMO_SEED_VERSION . ':' . $name, true), 0, 16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}
