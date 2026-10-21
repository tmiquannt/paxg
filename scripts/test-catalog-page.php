<?php
declare(strict_types=1);

use MiniERP\Admin\Controllers\CatalogController;
use MiniERP\Security\CapabilityPolicy;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Catalog page smoke is CLI-only.\n");
    exit(2);
}
$wpLoad = '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "Local WordPress bootstrap is unavailable.\n");
    exit(2);
}
require_once $wpLoad;
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

global $wpdb;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the isolated local WordPress site.');
$suffix = bin2hex(random_bytes(6));
$categoryCode = 'CAT-PAGE-' . strtoupper($suffix);
$activeSku = 'SKU-PAGE-' . strtoupper($suffix);
$inactiveSku = 'SKU-HIDDEN-' . strtoupper($suffix);
$categoryTable = '`' . $wpdb->prefix . 'erp_categories`';
$productTable = '`' . $wpdb->prefix . 'erp_products`';
$userIds = [];
$categoryId = 0;
$productIds = [];
$results = [];
$exitCode = 0;

try {
    $assert($wpdb->insert($wpdb->prefix . 'erp_categories', ['code' => $categoryCode, 'name' => 'UI smoke category', 'is_active' => 1]) === 1, 'Could not create the local UI fixture category.');
    $categoryId = (int) $wpdb->insert_id;
    foreach ([[$activeSku, 1, 125000], [$inactiveSku, 0, 990000]] as [$sku, $active, $price]) {
        $assert($wpdb->insert($wpdb->prefix . 'erp_products', [
            'sku' => $sku,
            'name' => 'UI smoke product ' . $sku,
            'category_id' => $categoryId,
            'unit' => 'kg',
            'unit_step' => '0.001',
            'sale_price_vnd' => $price,
            'is_active' => $active,
        ]) === 1, 'Could not create the local UI fixture product.');
        $productIds[] = (int) $wpdb->insert_id;
    }

    $createUser = static function (string $role) use (&$userIds, $suffix): int {
        $login = 'erp_' . $role . '_page_' . $suffix;
        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $login . '@example.invalid',
            'role' => $role,
        ]);
        if (is_wp_error($userId)) {
            throw new RuntimeException('Could not create a temporary local ERP role fixture.');
        }
        $userIds[] = (int) $userId;
        update_user_meta((int) $userId, '_mini_erp_enabled', '1');
        return (int) $userId;
    };

    $renderFor = static function (int $userId): string {
        wp_set_current_user($userId);
        if (CapabilityPolicy::currentActor() === null) {
            throw new RuntimeException('WordPress user did not resolve to an enabled ERP actor.');
        }
        $_GET = [];
        ob_start();
        CatalogController::render();
        return (string) ob_get_clean();
    };

    $managerHtml = $renderFor($createUser('erp_manager'));
    $assert(str_contains($managerHtml, $activeSku) && str_contains($managerHtml, $inactiveSku), 'Manager did not receive the complete catalog projection.');
    $assert(str_contains($managerHtml, '125.000') && str_contains($managerHtml, '990.000') && str_contains($managerHtml, 'Tạo sản phẩm'), 'Manager catalog controls or prices were not rendered.');
    $results[] = 'Manager sees active/inactive products, prices, and management controls: pass';

    $salesHtml = $renderFor($createUser('erp_sales'));
    $assert(str_contains($salesHtml, $activeSku) && !str_contains($salesHtml, $inactiveSku), 'Sales product view did not filter inactive catalog rows.');
    $assert(str_contains($salesHtml, '125.000') && !str_contains($salesHtml, 'Tạo sản phẩm'), 'Sales catalog price projection or read-only boundary is incorrect.');
    $results[] = 'Sales sees active products and sale prices without management controls: pass';

    $warehouseHtml = $renderFor($createUser('erp_warehouse'));
    $assert(str_contains($warehouseHtml, $activeSku) && !str_contains($warehouseHtml, $inactiveSku), 'Warehouse product view did not filter inactive catalog rows.');
    $assert(!str_contains($warehouseHtml, 'Giá bán (VND)') && !str_contains($warehouseHtml, '125.000') && !str_contains($warehouseHtml, 'Tạo sản phẩm'), 'Warehouse catalog leaked commercial values or management controls.');
    $results[] = 'Warehouse sees active product facts without prices or management controls: pass';
} catch (Throwable $failure) {
    $exitCode = 1;
    fwrite(STDERR, 'Catalog page smoke failed (' . get_class($failure) . ', code ' . $failure->getCode() . '). ' . $failure->getMessage() . "\n");
} finally {
    wp_set_current_user(1);
    foreach ($userIds as $userId) {
        if (!wp_delete_user($userId)) {
            $exitCode = 1;
            fwrite(STDERR, "Could not remove a temporary ERP user fixture.\n");
        }
    }
    if ($productIds !== []) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$productTable} WHERE id IN (" . implode(',', array_fill(0, count($productIds), '%d')) . ')', $productIds));
    }
    if ($categoryId > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_categories', ['id' => $categoryId]);
    }
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Catalog role-projection smoke passed; temporary users and rows were removed.\n - " . implode("\n - ", $results) . "\n");
}
exit($exitCode);
