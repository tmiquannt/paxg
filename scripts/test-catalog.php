<?php
declare(strict_types=1);

use MiniERP\Application\Services\CatalogService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\AuditSchema;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\MasterSchema;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\RolesInstaller;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Catalog integration smoke is CLI-only.\n");
    exit(2);
}

$wpLoad = '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "Local WordPress bootstrap is unavailable.\n");
    exit(2);
}
require_once $wpLoad;
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';

global $wpdb;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(defined('WP_ENVIRONMENT_TYPE') && wp_get_environment_type() === 'local', 'Refusing to run outside the local WordPress environment.');
$assert(defined('DB_NAME') && DB_NAME === 'mini_erp_local', 'Refusing to run against an unexpected database.');
$assert(isset($wpdb->prefix) && $wpdb->prefix === 'paxg_', 'Refusing to run with an unexpected WordPress table prefix.');

$prefix = 'paxg_test_' . bin2hex(random_bytes(6)) . '_';
$assert((bool) preg_match('/\Apaxg_test_[a-f0-9]{12}_\z/D', $prefix), 'Generated fixture prefix failed its safety allowlist.');
$fixtureDb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$fixtureDb->prefix = $prefix;
$schema = new MasterSchema($fixtureDb);
$fixtureNames = array_merge($schema->tableNames(), [$prefix . 'erp_audit_logs']);
$allowedNames = array_fill_keys([
    $prefix . 'erp_categories',
    $prefix . 'erp_products',
    $prefix . 'erp_customers',
    $prefix . 'erp_suppliers',
    $prefix . 'erp_warehouses',
    $prefix . 'erp_audit_logs',
], true);
$results = [];
$exitCode = 0;

try {
    foreach ($fixtureNames as $table) {
        $assert(isset($allowedNames[$table]), 'Refusing to touch a table outside the exact fixture allowlist.');
    }
    $schema->migrate();
    (new AuditSchema($fixtureDb))->migrate();

    $actor = new Actor(2, 'erp_manager', RolesInstaller::definitions()['erp_manager']['capabilities']);
    $service = new CatalogService($fixtureDb, new TransactionManager($fixtureDb), new AuditLogger($prefix));
    $categoryId = $service->createCategory($actor, 'CAT-SMOKE', 'Danh mục thử nghiệm');
    $values = [
        'sku' => 'SKU-SMOKE',
        'name' => 'Sản phẩm thử nghiệm',
        'category_id' => $categoryId,
        'unit' => 'kg',
        'unit_step' => '0.001',
        'sale_price_vnd' => '125000',
    ];
    $productId = $service->saveProduct($actor, $values);
    $productTable = '`' . $prefix . 'erp_products`';
    $categoryTable = '`' . $prefix . 'erp_categories`';
    $auditTable = '`' . $prefix . 'erp_audit_logs`';
    $row = $fixtureDb->get_row($fixtureDb->prepare("SELECT id, sku, category_id, sale_price_vnd, updated_at FROM {$productTable} WHERE id = %d", $productId), ARRAY_A);
    $assert(is_array($row) && $row['sku'] === 'SKU-SMOKE' && (int) $row['category_id'] === $categoryId && (string) $row['sale_price_vnd'] === '125000', 'Product creation did not persist the expected values.');

    $updatedValues = $values;
    $updatedValues['name'] = 'Sản phẩm đã sửa';
    $updatedValues['sale_price_vnd'] = '130000';
    $service->saveProduct($actor, $updatedValues, $productId, (string) $row['updated_at']);
    $updated = $fixtureDb->get_row($fixtureDb->prepare("SELECT name, sale_price_vnd, updated_at FROM {$productTable} WHERE id = %d", $productId), ARRAY_A);
    $assert(is_array($updated) && $updated['name'] === 'Sản phẩm đã sửa' && (string) $updated['sale_price_vnd'] === '130000', 'Product update did not persist the expected values.');

    $staleRejected = false;
    try {
        $service->saveProduct($actor, $values, $productId, (string) $row['updated_at']);
    } catch (DomainException) {
        $staleRejected = true;
    }
    $assert($staleRejected, 'A stale product update was not rejected.');
    $service->setProductActive($actor, $productId, false, (string) $updated['updated_at']);
    $category = $fixtureDb->get_row($fixtureDb->prepare("SELECT updated_at FROM {$categoryTable} WHERE id = %d", $categoryId), ARRAY_A);
    $assert(is_array($category), 'Created category is missing.');
    $service->setCategoryActive($actor, $categoryId, false, (string) $category['updated_at']);

    $auditCount = (int) $fixtureDb->get_var("SELECT COUNT(*) FROM {$auditTable} WHERE actor_user_id = 2 AND result = 'success'");
    $assert($auditCount === 5, 'Catalog operations were not audited atomically.');
    $results[] = 'category/product create, edit, stale-update rejection and inactivation: pass';
    $results[] = 'catalog audit rows committed with their source writes: pass';

    $duplicateRejected = false;
    try {
        $service->createCategory($actor, 'CAT-SMOKE', 'Duplicate');
    } catch (DatabaseException $failure) {
        $duplicateRejected = $failure->getCode() === 1062;
    }
    $assert($duplicateRejected, 'Duplicate category code was not reported as a database conflict.');
    $assert((int) $fixtureDb->get_var("SELECT COUNT(*) FROM {$auditTable} WHERE actor_user_id = 2 AND result = 'success'") === 5, 'Failed duplicate write emitted a success audit event.');
    $results[] = 'duplicate master code rejected without success audit: pass';
} catch (Throwable $failure) {
    $exitCode = 1;
    fwrite(STDERR, 'Catalog integration smoke failed (' . get_class($failure) . ', code ' . $failure->getCode() . '). ' . $failure->getMessage() . "\n");
} finally {
    foreach (array_reverse($fixtureNames) as $table) {
        if (!isset($allowedNames[$table])) {
            $exitCode = 1;
            fwrite(STDERR, "Refusing cleanup outside the exact fixture allowlist.\n");
            continue;
        }
        if ($fixtureDb->query("DROP TABLE IF EXISTS `{$table}`") === false) {
            $exitCode = 1;
            fwrite(STDERR, "Could not remove an allowlisted catalog fixture table.\n");
        }
    }
    $fixtureDb->close();
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Catalog integration smoke passed; isolated fixtures were removed.\n - " . implode("\n - ", $results) . "\n");
}
exit($exitCode);
