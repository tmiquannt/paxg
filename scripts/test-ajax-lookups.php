<?php
declare(strict_types=1);

use MiniERP\Bootstrap;

if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/wp-load.php')) {
    fwrite(STDERR, "Local WordPress CLI is required.\n");
    exit(2);
}

require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the local fixture database.');

wp_set_current_user(1);
Bootstrap::upgradeSchemaIfNeeded();
$assert(Bootstrap::schemaReady(), 'ERP schema must be ready before the AJAX lookup smoke.');

$previousActionExists = array_key_exists('action', $_REQUEST);
$previousAction = $_REQUEST['action'] ?? null;
$previousSchemaVersion = get_option('mini_erp_schema_version', null);
$gateResponse = null;
$gateHandler = static function ($message, $title = '', $args = []) use (&$gateResponse): void {
    $gateResponse = is_array($args) ? ($args['response'] ?? null) : null;
};
$gateHandlerFilter = static fn () => $gateHandler;
add_filter('wp_die_handler', $gateHandlerFilter, 99);
try {
    update_option('mini_erp_schema_version', 'schema-pending-test', false);
    $_REQUEST['action'] = 'erp_search_products';
    Bootstrap::enforceSchemaForErpAction();
    $assert((int) $gateResponse === 503, 'ERP AJAX lookup was not blocked with HTTP 503 while schema migration was pending.');
    $gateResponse = null;
    $_REQUEST['action'] = 'profile_update';
    Bootstrap::enforceSchemaForErpAction();
    $assert($gateResponse === null, 'Schema gate intercepted an unrelated WordPress action.');
} finally {
    remove_filter('wp_die_handler', $gateHandlerFilter, 99);
    if ($previousSchemaVersion === null) delete_option('mini_erp_schema_version');
    else update_option('mini_erp_schema_version', $previousSchemaVersion, false);
    if ($previousActionExists) $_REQUEST['action'] = $previousAction;
    else unset($_REQUEST['action']);
    $assert(Bootstrap::schemaReady(), 'The schema readiness smoke did not restore the original schema version.');
}

$suffix = strtoupper(bin2hex(random_bytes(5)));
$categoryId = 0;
$productIds = [];
$customerId = 0;
$warehouseId = 0;
$userIds = [];
$results = [];
$failure = null;
$childFile = null;

$createUser = static function (string $role, string $tag) use (&$userIds, $suffix): int {
    $login = 'erp_' . $role . '_ajax_' . strtolower($suffix) . '_' . $tag;
    $userId = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $login . '@example.invalid',
        'role' => $role,
    ]);
    if (is_wp_error($userId)) {
        throw new RuntimeException('Could not create an isolated ERP lookup user.');
    }
    $userIds[] = (int) $userId;
    update_user_meta((int) $userId, '_mini_erp_enabled', '1');
    return (int) $userId;
};

$childFile = tempnam(sys_get_temp_dir(), 'mini-erp-ajax-');
if (!is_string($childFile) || $childFile === '') {
    throw new RuntimeException('Could not create the temporary AJAX request runner.');
}
$childSource = <<<'PHP'
<?php
require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
wp_set_current_user((int) $argv[1]);
$action = (string) $argv[2];
$payload = json_decode(base64_decode((string) $argv[3], true), true, 512, JSON_THROW_ON_ERROR);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = '127.0.0.1:18088';
$_SERVER['PHP_SELF'] = '/wp-admin/admin-ajax.php';
$_POST = ['action' => $action, 'nonce' => wp_create_nonce($action), ...$payload];
$_REQUEST = $_POST;
require '/var/www/html/wp-admin/admin-ajax.php';
PHP;
if (file_put_contents($childFile, $childSource) === false) {
    @unlink($childFile);
    throw new RuntimeException('Could not write the temporary AJAX request runner.');
}

$request = static function (int $userId, string $action, array $payload) use ($childFile): array {
    $encoded = base64_encode((string) wp_json_encode($payload));
    $process = proc_open(
        [PHP_BINARY, $childFile, (string) $userId, $action, $encoded],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        '/var/www/html'
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start an isolated AJAX request process.');
    }
    fclose($pipes[0]);
    $body = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (!is_string($body) || trim($body) === '' || $exitCode !== 0) {
        throw new RuntimeException('AJAX request failed (exit=' . $exitCode . ', body=' . substr((string) $body, 0, 200) . ', stderr=' . substr((string) $errors, 0, 600) . ').');
    }
    $decoded = json_decode(trim($body), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('AJAX request did not return a JSON object: ' . substr($body, 0, 300));
    }
    return $decoded;
};

try {
    $assert($wpdb->insert($wpdb->prefix . 'erp_categories', ['code' => 'AJAX-' . $suffix, 'name' => 'AJAX lookup fixture']) === 1, 'Could not create the lookup category.');
    $categoryId = (int) $wpdb->insert_id;
    foreach ([['AJAX-A-' . $suffix, 1], ['AJAX-X-' . $suffix, 0]] as [$sku, $active]) {
        $assert($wpdb->insert($wpdb->prefix . 'erp_products', [
            'sku' => $sku,
            'name' => 'AJAX lookup product ' . $sku,
            'category_id' => $categoryId,
            'unit' => 'hộp',
            'unit_step' => '1.000',
            'sale_price_vnd' => 125000,
            'is_active' => $active,
        ]) === 1, 'Could not create a lookup product.');
        $productIds[] = (int) $wpdb->insert_id;
    }
    $assert($wpdb->insert($wpdb->prefix . 'erp_customers', [
        'code' => 'AJAX-C-' . $suffix,
        'name' => 'Khách hàng tra cứu',
        'tax_id' => '0101234567',
        'phone' => '0900000000',
        'email' => 'private@example.invalid',
        'address' => 'Địa chỉ không được trả về',
    ]) === 1, 'Could not create the lookup customer.');
    $customerId = (int) $wpdb->insert_id;
    $assert($wpdb->insert($wpdb->prefix . 'erp_warehouses', ['code' => 'AJAX-W-' . $suffix, 'name' => 'Kho tra cứu']) === 1, 'Could not create the lookup warehouse.');
    $warehouseId = (int) $wpdb->insert_id;
    $assert($wpdb->insert($wpdb->prefix . 'erp_stock_balances', ['warehouse_id' => $warehouseId, 'product_id' => $productIds[0], 'quantity' => '4.000']) === 1, 'Could not create the lookup stock balance.');

    $salesId = $createUser('erp_sales', 'sales');
    $productResponse = $request($salesId, 'erp_search_products', ['q' => 'AJAX-A-' . $suffix, 'warehouse_id' => (string) $warehouseId]);
    $product = $productResponse['items'][0] ?? null;
    $assert(isset($productResponse['items']) && is_array($productResponse['items']) && count($productResponse['items']) === 1, 'Product search did not return the unique active fixture.');
    $assert(is_array($product) && (int) ($product['id'] ?? 0) === $productIds[0] && (string) ($product['available_qty'] ?? '') === '4.000', 'Sales product search omitted its permitted stock preview.');
    $assert(isset($product['sale_price_vnd']) && (int) $productResponse['total'] === 1 && (int) $productResponse['page_size'] === 20, 'Product lookup projection or pagination envelope is incorrect.');

    $productWithoutWarehouse = $request($salesId, 'erp_search_products', ['q' => 'AJAX-A-' . $suffix]);
    $assert(!array_key_exists('available_qty', $productWithoutWarehouse['items'][0] ?? []), 'Product search exposed stock without a requested warehouse.');
    $results[] = 'Authenticated Sales product lookup returns active bounded results and only requested-warehouse stock';

    $warehouseUserId = $createUser('erp_warehouse', 'warehouse');
    update_user_meta($warehouseUserId, '_mini_erp_warehouse_ids', [$warehouseId]);
    $warehouseResponse = $request($warehouseUserId, 'erp_search_products', ['q' => 'AJAX-A-' . $suffix, 'warehouse_id' => (string) $warehouseId]);
    $warehouseProduct = $warehouseResponse['items'][0] ?? null;
    $assert(is_array($warehouseProduct) && (string) ($warehouseProduct['available_qty'] ?? '') === '4.000' && !array_key_exists('sale_price_vnd', $warehouseProduct), 'Warehouse lookup did not enforce assigned scope and hide sale prices.');
    $results[] = 'Warehouse product lookup respects assigned warehouse and omits sale price';

    $customerResponse = $request($salesId, 'erp_search_customers', ['q' => 'AJAX-C-' . $suffix]);
    $customer = $customerResponse['items'][0] ?? null;
    $assert(is_array($customer) && (int) ($customer['id'] ?? 0) === $customerId && array_keys($customer) === ['id', 'code', 'name'], 'Customer lookup returned private fields or the wrong projection.');
    $assert((int) $customerResponse['total'] === 1, 'Customer search count did not use the filtered result scope.');
    $results[] = 'Sales customer lookup returns only id/code/name with a scoped count';
    $results[] = 'ERP AJAX actions fail closed with 503 while the schema is pending without intercepting unrelated actions';
} catch (Throwable $error) {
    $failure = 'AJAX lookup smoke failed: ' . get_class($error) . ' ' . $error->getMessage();
} finally {
    if (is_string($childFile) && is_file($childFile)) {
        @unlink($childFile);
    }
    if ($warehouseId > 0 && $productIds !== []) {
        $wpdb->delete($wpdb->prefix . 'erp_stock_balances', ['warehouse_id' => $warehouseId, 'product_id' => $productIds[0]]);
    }
    if ($customerId > 0) $wpdb->delete($wpdb->prefix . 'erp_customers', ['id' => $customerId]);
    if ($warehouseId > 0) $wpdb->delete($wpdb->prefix . 'erp_warehouses', ['id' => $warehouseId]);
    foreach ($productIds as $productId) $wpdb->delete($wpdb->prefix . 'erp_products', ['id' => $productId]);
    if ($categoryId > 0) $wpdb->delete($wpdb->prefix . 'erp_categories', ['id' => $categoryId]);
    foreach ($userIds as $userId) {
        foreach (['erp_search_products', 'erp_search_customers'] as $action) {
            delete_transient('mini_erp_ajax_' . substr(hash('sha256', $action . ':' . $userId), 0, 40));
        }
        wp_delete_user($userId);
    }
    wp_set_current_user(1);
}

if ($failure !== null) fwrite(STDERR, $failure . "\n");
foreach ($results as $result) fwrite(STDOUT, 'PASS: ' . $result . "\n");
if ($failure !== null) exit(1);
fwrite(STDOUT, "AJAX lookup smoke passed; exact fixture rows and temporary users cleaned.\n");
