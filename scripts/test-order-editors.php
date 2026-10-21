<?php
declare(strict_types=1);

use MiniERP\Admin\Controllers\PurchaseController;
use MiniERP\Admin\Controllers\SalesController;
use MiniERP\Bootstrap;
use MiniERP\Security\CapabilityPolicy;

if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/wp-load.php')) {
    fwrite(STDERR, "Local WordPress CLI is required.\n");
    exit(2);
}
require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the isolated local WordPress ERP database.');
wp_set_current_user(1);
Bootstrap::upgradeSchemaIfNeeded();
$assert(Bootstrap::schemaReady(), 'The ERP schema is not ready for the order-editor smoke.');

$suffix = strtoupper(bin2hex(random_bytes(5)));
$now = gmdate('Y-m-d H:i:s') . '.000000';
$userId = 0;
$categoryId = 0;
$customerId = 0;
$supplierId = 0;
$warehouseId = 0;
$productId = 0;
$inactiveProductId = 0;
$otherUserIds = [];
$salesOrderIds = [];
$salesItemIds = [];
$purchaseOrderIds = [];
$purchaseItemIds = [];
$assertRow = static function (string $table, array $values) use ($wpdb, $assert): int {
    if ($wpdb->insert($wpdb->prefix . $table, $values) !== 1) {
        throw new RuntimeException('Could not create isolated order-editor fixture in ' . $table . '.');
    }
    return (int) $wpdb->insert_id;
};
$makeSalesOrder = static function (int $lineCount, bool $includeInactive, ?int $creatorId = null) use (&$salesOrderIds, &$salesItemIds, $assertRow, $suffix, &$customerId, &$warehouseId, &$userId, &$productId, &$inactiveProductId, $now): int {
    $orderId = $assertRow('erp_sales_orders', [
        'order_no' => 'SO-EDITOR-' . $suffix . '-' . $lineCount . '-' . (count($salesOrderIds) + 1),
        'customer_id' => $customerId,
        'warehouse_id' => $warehouseId,
        'created_by' => $creatorId ?? $userId,
        'status' => 'DRAFT',
        'discount_bps' => 0,
        'tax_bps' => 0,
        'subtotal_exact_vnd' => (string) ($lineCount * 100) . '.000',
        'rounding_adjustment_vnd' => '0.000',
        'subtotal_vnd' => $lineCount * 100,
        'discount_vnd' => 0,
        'tax_vnd' => 0,
        'total_vnd' => $lineCount * 100,
        'version' => 1,
        'note' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $salesOrderIds[] = $orderId;
    for ($index = 0; $index < $lineCount; $index++) {
        $useInactive = $includeInactive && $index === $lineCount - 1;
        $lineProductId = $useInactive ? $inactiveProductId : $productId;
        $sku = $useInactive ? 'EDITOR-OLD-' . $suffix : 'EDITOR-ACTIVE-' . $suffix;
        $salesItemIds[] = $assertRow('erp_sales_order_items', [
            'order_id' => $orderId,
            'product_id' => $lineProductId,
            'sku_snapshot' => $sku,
            'name_snapshot' => $useInactive ? 'Inactive editor product' : 'Active editor product',
            'unit_snapshot' => 'ea',
            'unit_step_snapshot' => '1.000',
            'unit_price_vnd' => 100,
            'quantity' => '1.000',
            'line_subtotal_exact_vnd' => '100.000',
            'rounding_allocation_vnd' => '0.000',
            'line_subtotal_vnd' => 100,
            'discount_vnd' => 0,
            'tax_vnd' => 0,
            'line_total_vnd' => 100,
        ]);
    }
    return $orderId;
};
$makePurchaseOrder = static function (int $lineCount, bool $includeInactive, ?int $creatorId = null) use (&$purchaseOrderIds, &$purchaseItemIds, $assertRow, $suffix, &$supplierId, &$warehouseId, &$userId, &$productId, &$inactiveProductId, $now): int {
    $orderId = $assertRow('erp_purchase_orders', [
        'po_no' => 'PO-EDITOR-' . $suffix . '-' . $lineCount . '-' . (count($purchaseOrderIds) + 1),
        'supplier_id' => $supplierId,
        'warehouse_id' => $warehouseId,
        'created_by' => $creatorId ?? $userId,
        'status' => 'DRAFT',
        'subtotal_exact_vnd' => (string) ($lineCount * 100) . '.000',
        'rounding_adjustment_vnd' => '0.000',
        'subtotal_vnd' => $lineCount * 100,
        'total_vnd' => $lineCount * 100,
        'version' => 1,
        'note' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $purchaseOrderIds[] = $orderId;
    for ($index = 0; $index < $lineCount; $index++) {
        $useInactive = $includeInactive && $index === $lineCount - 1;
        $lineProductId = $useInactive ? $inactiveProductId : $productId;
        $sku = $useInactive ? 'EDITOR-OLD-' . $suffix : 'EDITOR-ACTIVE-' . $suffix;
        $purchaseItemIds[] = $assertRow('erp_purchase_order_items', [
            'po_id' => $orderId,
            'product_id' => $lineProductId,
            'sku_snapshot' => $sku,
            'name_snapshot' => $useInactive ? 'Inactive editor product' : 'Active editor product',
            'unit_snapshot' => 'ea',
            'unit_step_snapshot' => '1.000',
            'unit_cost_vnd' => 100,
            'quantity' => '1.000',
            'line_subtotal_exact_vnd' => '100.000',
            'rounding_allocation_vnd' => '0.000',
            'line_total_vnd' => 100,
        ]);
    }
    return $orderId;
};
$renderAsManager = static function (callable $screen, array $query) use (&$userId): string {
    wp_set_current_user($userId);
    if (CapabilityPolicy::currentActor() === null) throw new RuntimeException('The temporary Manager did not resolve to an ERP Actor.');
    $_GET = $query;
    ob_start();
    $screen();
    return (string) ob_get_clean();
};
$createAdditionalUser = static function (string $role) use (&$otherUserIds, $suffix): int {
    $login = 'erp_order_editor_' . strtolower($suffix) . '_' . count($otherUserIds);
    $createdUser = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $login . '@example.invalid',
        'role' => $role,
    ]);
    if (is_wp_error($createdUser)) throw new RuntimeException('Could not create an additional order-editor fixture user.');
    $userId = (int) $createdUser;
    $otherUserIds[] = $userId;
    update_user_meta($userId, '_mini_erp_enabled', '1');
    return $userId;
};

$results = [];
$exitCode = 0;
$failureMessage = null;
try {
    $login = 'erp_order_editor_' . strtolower($suffix);
    $createdUser = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $login . '@example.invalid',
        'role' => 'erp_manager',
    ]);
    $assert(!is_wp_error($createdUser), 'Could not create the temporary Manager.');
    $userId = (int) $createdUser;
    update_user_meta($userId, '_mini_erp_enabled', '1');
    wp_set_current_user(0);
    $assert(CapabilityPolicy::currentActor() === null, 'A logged-out request unexpectedly resolved to an ERP Actor.');
    wp_set_current_user($userId);
    $assert(CapabilityPolicy::currentActor() !== null, 'The temporary Manager did not resolve after login context changed.');

    $categoryId = $assertRow('erp_categories', ['code' => 'EDITOR-' . $suffix, 'name' => 'Order editor smoke']);
    $customerId = $assertRow('erp_customers', ['code' => 'EDITOR-' . $suffix, 'name' => 'Order editor buyer']);
    $supplierId = $assertRow('erp_suppliers', ['code' => 'EDITOR-' . $suffix, 'name' => 'Order editor supplier']);
    $warehouseId = $assertRow('erp_warehouses', ['code' => 'EDITOR-' . $suffix, 'name' => 'Order editor warehouse']);
    $productId = $assertRow('erp_products', [
        'sku' => 'EDITOR-ACTIVE-' . $suffix, 'name' => 'Active editor product', 'category_id' => $categoryId,
        'unit' => 'ea', 'unit_step' => '1.000', 'sale_price_vnd' => 100,
    ]);
    $inactiveProductId = $assertRow('erp_products', [
        'sku' => 'EDITOR-OLD-' . $suffix, 'name' => 'Inactive editor product', 'category_id' => $categoryId,
        'unit' => 'ea', 'unit_step' => '1.000', 'sale_price_vnd' => 100, 'is_active' => 0,
    ]);

    $sales20Id = $makeSalesOrder(20, true);
    $purchase20Id = $makePurchaseOrder(20, true);
    $otherManagerId = $createAdditionalUser('erp_manager');
    $salesOwnerId = $createAdditionalUser('erp_sales');
    $otherSalesOwnerId = $createAdditionalUser('erp_sales');
    $ownedSalesDraftId = $makeSalesOrder(1, false, $salesOwnerId);
    $foreignSalesDraftId = $makeSalesOrder(1, false, $otherSalesOwnerId);
    $foreignPurchaseDraftId = $makePurchaseOrder(1, false, $otherManagerId);
    $salesHtml = $renderAsManager(static fn() => SalesController::render(), ['edit' => (string) $sales20Id]);
    $purchaseHtml = $renderAsManager(static fn() => PurchaseController::render(), ['edit' => (string) $purchase20Id]);
    $lineMarker = 'class="erp-order-line" data-order-line';
    $assert(substr_count($salesHtml, $lineMarker) === 20, 'Sales editor did not render every one of the 20 saved rows.');
    $assert(substr_count($purchaseHtml, $lineMarker) === 20, 'Purchase editor did not render every one of the 20 saved rows.');
    foreach ([$salesHtml, $purchaseHtml] as $html) {
        $assert(str_contains($html, 'data-max-lines="20"') && str_contains($html, 'data-add-order-line'), 'The 20-line add control was missing.');
        $assert(str_contains($html, 'EDITOR-OLD-' . $suffix) && str_contains($html, 'Ngừng kinh doanh'), 'The selected inactive product was not preserved with an explanatory label.');
    }
    $assert(str_contains($salesHtml, 'data-order-form-state="sales"') && str_contains($purchaseHtml, 'data-order-form-state="purchase"'), 'The order forms are missing their scoped input-recovery identity.');
    $results[] = 'Sales and purchase editors preserve all 20 saved rows and selected inactive products';

    wp_set_current_user($salesOwnerId);
    $salesActor = CapabilityPolicy::currentActor();
    $assert($salesActor !== null, 'The temporary Sales user did not resolve to an ERP Actor.');
    $_GET = ['edit' => (string) $ownedSalesDraftId];
    $assert(SalesController::preflightDetail($salesActor) === null, 'A Sales user could not open their own draft editor.');
    $_GET = ['edit' => (string) $foreignSalesDraftId];
    $assert(SalesController::preflightDetail($salesActor) === 404, 'A Sales user did not receive a generic 404 for another user draft editor.');
    wp_set_current_user($userId);
    $managerActor = CapabilityPolicy::currentActor();
    $assert($managerActor !== null, 'The temporary Manager did not resolve to an ERP Actor for edit checks.');
    $_GET = ['edit' => (string) $foreignSalesDraftId];
    $assert(SalesController::preflightDetail($managerActor) === null, 'Manager access to another Sales user draft no longer matches the existing workflow.');
    $_GET = ['edit' => (string) $foreignPurchaseDraftId];
    $assert(PurchaseController::preflightDetail($managerActor) === 404, 'A Manager did not receive a generic 404 for another user purchase draft editor.');
    $results[] = 'Draft editors enforce owner scope, preserve Manager Sales access, and deny cross-owner Purchase edits';

    $salesOverflowId = $makeSalesOrder(21, false);
    $purchaseOverflowId = $makePurchaseOrder(21, false);
    $salesOverflow = $renderAsManager(static fn() => SalesController::render(), ['edit' => (string) $salesOverflowId]);
    $purchaseOverflow = $renderAsManager(static fn() => PurchaseController::render(), ['edit' => (string) $purchaseOverflowId]);
    $assert(str_contains($salesOverflow, 'vượt giới hạn 20 dòng') && !str_contains($salesOverflow, 'data-order-line'), 'Sales editor did not fail closed for a corrupt 21-line order.');
    $assert(str_contains($purchaseOverflow, 'vượt giới hạn 20 dòng') && !str_contains($purchaseOverflow, 'data-order-line'), 'Purchase editor did not fail closed for a corrupt 21-line order.');
    $results[] = 'Both editors fail closed instead of rendering incomplete 21-line drafts';
} catch (Throwable $failure) {
    $exitCode = 1;
    $failureMessage = 'Order-editor smoke failed: ' . get_class($failure) . ' (' . $failure->getCode() . ') ' . $failure->getMessage();
} finally {
    foreach ($purchaseItemIds as $id) $wpdb->delete($wpdb->prefix . 'erp_purchase_order_items', ['id' => $id]);
    foreach ($salesItemIds as $id) $wpdb->delete($wpdb->prefix . 'erp_sales_order_items', ['id' => $id]);
    foreach ($purchaseOrderIds as $id) $wpdb->delete($wpdb->prefix . 'erp_purchase_orders', ['id' => $id]);
    foreach ($salesOrderIds as $id) $wpdb->delete($wpdb->prefix . 'erp_sales_orders', ['id' => $id]);
    if ($inactiveProductId > 0) $wpdb->delete($wpdb->prefix . 'erp_products', ['id' => $inactiveProductId]);
    if ($productId > 0) $wpdb->delete($wpdb->prefix . 'erp_products', ['id' => $productId]);
    if ($categoryId > 0) $wpdb->delete($wpdb->prefix . 'erp_categories', ['id' => $categoryId]);
    if ($customerId > 0) $wpdb->delete($wpdb->prefix . 'erp_customers', ['id' => $customerId]);
    if ($supplierId > 0) $wpdb->delete($wpdb->prefix . 'erp_suppliers', ['id' => $supplierId]);
    if ($warehouseId > 0) $wpdb->delete($wpdb->prefix . 'erp_warehouses', ['id' => $warehouseId]);
    wp_set_current_user(0);
    foreach ($otherUserIds as $otherUserId) {
        if (!wp_delete_user($otherUserId)) {
            $exitCode = 1;
            $failureMessage = 'Could not remove an additional temporary order-editor fixture user.';
        }
    }
    if ($userId > 0 && !wp_delete_user($userId)) {
        $exitCode = 1;
        $failureMessage = 'Could not remove the temporary Manager fixture.';
    }
    $_GET = [];
}

if ($failureMessage !== null) fwrite(STDERR, $failureMessage . "\n");
foreach ($results as $result) fwrite(STDOUT, "PASS: {$result}\n");
if ($exitCode === 0) fwrite(STDOUT, "Order-editor smoke passed; temporary rows and user were removed.\n");
exit($exitCode);
