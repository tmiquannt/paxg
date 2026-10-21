<?php
declare(strict_types=1);

use MiniERP\Admin\Controllers\CashController;
use MiniERP\Admin\Controllers\InvoiceController;
use MiniERP\Admin\Controllers\InventoryController;
use MiniERP\Admin\Controllers\PurchaseController;
use MiniERP\Admin\Controllers\ReportController;
use MiniERP\Admin\Controllers\ReconciliationController;
use MiniERP\Admin\Controllers\SalesController;
use MiniERP\Bootstrap;
use MiniERP\Security\UserAccessController;
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
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the local fixture database.');
wp_set_current_user(1);
Bootstrap::upgradeSchemaIfNeeded();
$assert(Bootstrap::schemaReady(), 'The ERP System Admin schema upgrade did not complete before the role-projection smoke.');
$suffix = strtoupper(bin2hex(random_bytes(5)));
$now = gmdate('Y-m-d H:i:s') . '.000000';
$userIds = [];
$rowIds = ['category' => 0, 'customer' => 0, 'supplier' => 0, 'warehouse' => 0, 'hidden_warehouse' => 0, 'product' => 0, 'order' => 0, 'sales_orders' => [], 'sales_item' => 0, 'purchase_orders' => [], 'purchase_item' => 0, 'receipts' => [], 'receipt_items' => [], 'shipment' => 0, 'extra_shipments' => [], 'extra_shipment_items' => [], 'invoice' => 0, 'void_invoice' => 0, 'extra_invoices' => [], 'accounts' => [], 'payments' => [], 'expenses' => [], 'reconciliations' => [], 'reconciliation_items' => []];
$movementKeys = [];
$results = [];
$exitCode = 0;
$insert = static function (string $table, array $values) use ($wpdb): int {
    if ($wpdb->insert($wpdb->prefix . $table, $values) !== 1) {
        throw new RuntimeException('Could not create exact cash-page fixture row in ' . $table . ': ' . $wpdb->last_error);
    }
    return (int) $wpdb->insert_id;
};
$createUser = static function (string $role) use (&$userIds, $suffix): int {
    $login = 'erp_' . $role . '_cash_' . strtolower($suffix) . '_' . (count($userIds) + 1);
    $userId = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $login . '@example.invalid',
        'role' => $role,
    ]);
    if (is_wp_error($userId)) {
        throw new RuntimeException('Could not create a temporary local ERP user: ' . $userId->get_error_message());
    }
    $userIds[] = (int) $userId;
    update_user_meta((int) $userId, '_mini_erp_enabled', '1');
    return (int) $userId;
};
$render = static function (int $userId, callable $screen, array $query = []): string {
    wp_set_current_user($userId);
    if (CapabilityPolicy::currentActor() === null) {
        throw new RuntimeException('Temporary WordPress user did not resolve to an ERP Actor.');
    }
    $_GET = $query;
    ob_start();
    $screen();
    return (string) ob_get_clean();
};
$preflight = static function (int $userId, callable $check, array $query): ?int {
    wp_set_current_user($userId);
    $actor = CapabilityPolicy::currentActor();
    if ($actor === null) {
        throw new RuntimeException('Temporary WordPress user did not resolve to an ERP Actor for preflight.');
    }
    $_GET = $query;
    return $check($actor);
};

try {
    $managerId = $createUser('erp_manager');
    $accountantId = $createUser('erp_accountant');
    $warehouseUserId = $createUser('erp_warehouse');
    $unassignedManagerId = $createUser('erp_manager');
    $systemAdminId = $createUser('erp_system_admin');

    $rowIds['category'] = $insert('erp_categories', ['code' => 'CASH-PAGE-' . $suffix, 'name' => 'Cash page smoke']);
    $rowIds['customer'] = $insert('erp_customers', ['code' => 'CASH-PAGE-' . $suffix, 'name' => 'Cash page buyer']);
    $rowIds['supplier'] = $insert('erp_suppliers', ['code' => 'CASH-PAGE-' . $suffix, 'name' => 'Cash page supplier']);
    $rowIds['warehouse'] = $insert('erp_warehouses', ['code' => 'CASH-PAGE-' . $suffix, 'name' => 'Cash page warehouse']);
    $rowIds['hidden_warehouse'] = $insert('erp_warehouses', ['code' => 'CASH-HIDDEN-' . $suffix, 'name' => 'Unassigned warehouse']);
    update_user_meta($warehouseUserId, '_mini_erp_warehouse_ids', [$rowIds['warehouse']]);
    $rowIds['product'] = $insert('erp_products', [
        'sku' => 'CASH-PAGE-' . $suffix, 'name' => 'Cash page item', 'category_id' => $rowIds['category'],
        'unit' => 'cái', 'unit_step' => '1.000', 'sale_price_vnd' => 10000,
    ]);
    foreach ([[$rowIds['warehouse'], '3.000'], [$rowIds['hidden_warehouse'], '2.000']] as [$warehouseId, $quantity]) {
        $insert('erp_stock_balances', ['warehouse_id' => $warehouseId, 'product_id' => $rowIds['product'], 'quantity' => $quantity]);
    }
    $rowIds['order'] = $insert('erp_sales_orders', [
        'order_no' => 'SO-CASHPAGE-' . $suffix, 'customer_id' => $rowIds['customer'], 'warehouse_id' => $rowIds['warehouse'],
        'created_by' => $managerId, 'status' => 'SHIPPED', 'discount_bps' => 0, 'tax_bps' => 0,
        'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
        'discount_vnd' => 0, 'tax_vnd' => 0, 'total_vnd' => 10000, 'version' => 1,
        'note' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $rowIds['sales_orders'][] = $rowIds['order'];
    $rowIds['sales_item'] = $insert('erp_sales_order_items', [
        'order_id' => $rowIds['order'], 'product_id' => $rowIds['product'], 'sku_snapshot' => 'CASH-PAGE-' . $suffix,
        'name_snapshot' => 'Cash page item', 'unit_snapshot' => 'cái', 'unit_step_snapshot' => '1.000',
        'unit_price_vnd' => 10000, 'quantity' => '1000.000', 'line_subtotal_exact_vnd' => '10000000.000',
        'rounding_allocation_vnd' => '0.000', 'line_subtotal_vnd' => 10000000, 'discount_vnd' => 0,
        'tax_vnd' => 0, 'line_total_vnd' => 10000000,
    ]);
    $draftOrderNumber = 'SO-CASHPAGE-DRAFT-' . $suffix;
    $rowIds['sales_orders'][] = $insert('erp_sales_orders', [
        'order_no' => $draftOrderNumber, 'customer_id' => $rowIds['customer'], 'warehouse_id' => $rowIds['warehouse'],
        'created_by' => $managerId, 'status' => 'DRAFT', 'discount_bps' => 0, 'tax_bps' => 0,
        'subtotal_exact_vnd' => '0.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 0,
        'discount_vnd' => 0, 'tax_vnd' => 0, 'total_vnd' => 0, 'version' => 1,
        'note' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $salesQueueNumbers = [
        'confirmed' => 'SO-WC-' . $suffix,
        'partial' => 'SO-WP-' . $suffix,
        'shipped' => 'SO-WS-' . $suffix,
        'hidden' => 'SO-WH-' . $suffix,
    ];
    $salesQueueIds = [];
    foreach ([
        ['confirmed', 'CONFIRMED', $rowIds['warehouse']],
        ['partial', 'PARTIALLY_SHIPPED', $rowIds['warehouse']],
        ['shipped', 'SHIPPED', $rowIds['warehouse']],
        ['hidden', 'CONFIRMED', $rowIds['hidden_warehouse']],
    ] as [$key, $status, $warehouseId]) {
        $salesQueueIds[$key] = $insert('erp_sales_orders', [
            'order_no' => $salesQueueNumbers[$key], 'customer_id' => $rowIds['customer'], 'warehouse_id' => $warehouseId,
            'created_by' => $managerId, 'status' => $status, 'discount_bps' => 0, 'tax_bps' => 0,
            'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
            'discount_vnd' => 0, 'tax_vnd' => 0, 'total_vnd' => 10000, 'version' => 1,
            'note' => '', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $rowIds['sales_orders'][] = $salesQueueIds[$key];
    }
    foreach ([['APPROVED', 'PO-CASHPAGE-APPROVED-'], ['DRAFT', 'PO-CASHPAGE-DRAFT-']] as [$status, $numberPrefix]) {
        $rowIds['purchase_orders'][] = $insert('erp_purchase_orders', [
            'po_no' => $numberPrefix . $suffix, 'supplier_id' => $rowIds['supplier'], 'warehouse_id' => $rowIds['warehouse'],
            'created_by' => $managerId, 'approved_by' => $status === 'APPROVED' ? $accountantId : null, 'status' => $status,
            'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
            'total_vnd' => 10000, 'version' => 1, 'note' => '', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $rowIds['purchase_orders'][] = $insert('erp_purchase_orders', [
        'po_no' => 'PO-CASHPAGE-RECEIPT-' . $suffix, 'supplier_id' => $rowIds['supplier'], 'warehouse_id' => $rowIds['warehouse'],
        'created_by' => $managerId, 'approved_by' => $accountantId, 'status' => 'PARTIALLY_RECEIVED',
        'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
        'total_vnd' => 10000, 'version' => 1, 'note' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $receivedPurchaseOrderNumber = 'PO-CASHPAGE-RECEIVED-' . $suffix;
    $rowIds['purchase_orders'][] = $insert('erp_purchase_orders', [
        'po_no' => $receivedPurchaseOrderNumber, 'supplier_id' => $rowIds['supplier'], 'warehouse_id' => $rowIds['warehouse'],
        'created_by' => $managerId, 'approved_by' => $accountantId, 'status' => 'RECEIVED',
        'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
        'total_vnd' => 10000, 'version' => 2, 'note' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $hiddenPurchaseOrderNumber = 'PO-CASHPAGE-HIDDEN-' . $suffix;
    $rowIds['purchase_orders'][] = $insert('erp_purchase_orders', [
        'po_no' => $hiddenPurchaseOrderNumber, 'supplier_id' => $rowIds['supplier'], 'warehouse_id' => $rowIds['hidden_warehouse'],
        'created_by' => $managerId, 'approved_by' => $accountantId, 'status' => 'APPROVED',
        'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
        'total_vnd' => 10000, 'version' => 1, 'note' => '', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $rowIds['purchase_item'] = $insert('erp_purchase_order_items', [
        'po_id' => $rowIds['purchase_orders'][2], 'product_id' => $rowIds['product'], 'sku_snapshot' => 'CASH-PAGE-' . $suffix,
        'name_snapshot' => 'Cash page item', 'unit_snapshot' => 'cái', 'unit_step_snapshot' => '1.000',
        'unit_cost_vnd' => 10, 'quantity' => '1000.000', 'line_subtotal_exact_vnd' => '10000.000',
        'rounding_allocation_vnd' => '0.000', 'line_total_vnd' => 10000,
    ]);
    for ($index = 1; $index <= 101; $index++) {
        $receiptId = $insert('erp_goods_receipts', [
            'receipt_no' => sprintf('RCPT-PAGE-%s-%03d', $suffix, $index), 'purchase_order_id' => $rowIds['purchase_orders'][2],
            'warehouse_id' => $rowIds['warehouse'], 'received_by' => $accountantId,
            'received_at' => gmdate('Y-m-d H:i:s', strtotime('2001-01-01 00:00:00 UTC') + $index) . '.000000',
            'request_key' => wp_generate_uuid4(),
        ]);
        $rowIds['receipts'][] = $receiptId;
        $rowIds['receipt_items'][] = $insert('erp_goods_receipt_items', [
            'goods_receipt_id' => $receiptId, 'purchase_item_id' => $rowIds['purchase_item'],
            'product_id' => $rowIds['product'], 'quantity' => '1.000',
        ]);
    }
    $rowIds['shipment'] = $insert('erp_shipments', [
        'shipment_no' => 'SHP-CASHPAGE-' . $suffix, 'sales_order_id' => $rowIds['order'], 'warehouse_id' => $rowIds['warehouse'],
        'shipped_by' => $managerId, 'shipped_at' => $now, 'request_key' => wp_generate_uuid4(),
    ]);
    for ($index = 1; $index <= 101; $index++) {
        $shipmentId = $insert('erp_shipments', [
            'shipment_no' => sprintf('SHP-PAGE-%s-%03d', $suffix, $index), 'sales_order_id' => $rowIds['order'],
            'warehouse_id' => $rowIds['warehouse'], 'shipped_by' => $managerId,
            'shipped_at' => gmdate('Y-m-d H:i:s', strtotime('2001-01-01 00:00:00 UTC') + $index) . '.000000',
            'request_key' => wp_generate_uuid4(),
        ]);
        $rowIds['extra_shipments'][] = $shipmentId;
        $rowIds['extra_shipment_items'][] = $insert('erp_shipment_items', [
            'shipment_id' => $shipmentId, 'sales_item_id' => $rowIds['sales_item'], 'product_id' => $rowIds['product'], 'quantity' => '1.000',
        ]);
    }
    $rowIds['invoice'] = $insert('erp_invoices', [
        'invoice_no' => 'INV-' . gmdate('Y') . '-' . substr($suffix, 0, 8), 'source_type' => 'SHIPMENT', 'source_id' => $rowIds['shipment'],
        'direction' => 'IN', 'party_id' => $rowIds['customer'], 'seller_name' => 'Mini ERP', 'seller_address' => 'Hà Nội',
        'seller_tax_id' => '0100000000', 'buyer_name' => 'Cash page buyer', 'buyer_address' => 'Hồ Chí Minh', 'buyer_tax_id' => '0200000000',
        'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
        'discount_vnd' => 0, 'tax_vnd' => 0, 'total_vnd' => 10000, 'status' => 'ISSUED', 'issued_at' => '2000-01-01 00:00:00.000000',
        'due_at' => gmdate('Y-m-d') . ' 23:59:59.999999', 'issued_by' => $accountantId,
    ]);
    $insert('erp_invoice_items', [
        'invoice_id' => $rowIds['invoice'], 'source_item_id' => 1, 'sku_snapshot' => 'CASH-PAGE', 'name_snapshot' => 'Cash page item',
        'unit_snapshot' => 'cái', 'quantity' => '1.000', 'unit_price_vnd' => 10000,
        'line_subtotal_exact_vnd' => '10000.000', 'rounding_allocation_vnd' => '0.000', 'line_subtotal_vnd' => 10000,
        'discount_vnd' => 0, 'tax_vnd' => 0, 'line_total_vnd' => 10000,
    ]);
    $sourceBase = (int) $wpdb->get_var('SELECT COALESCE(MAX(source_id),0) FROM ' . $wpdb->prefix . 'erp_invoices') + 1000;
    for ($index = 1; $index <= 101; $index++) {
        $rowIds['extra_invoices'][] = $insert('erp_invoices', [
            'invoice_no' => sprintf('INV-%s-%s-%03d', gmdate('Y'), substr($suffix, 0, 8), $index),
            'source_type' => 'GOODS_RECEIPT', 'source_id' => $sourceBase + $index, 'direction' => 'OUT',
            'party_id' => $rowIds['supplier'], 'seller_name' => 'Cash page supplier', 'seller_address' => '', 'seller_tax_id' => '',
            'buyer_name' => 'Mini ERP', 'buyer_address' => '', 'buyer_tax_id' => '', 'subtotal_exact_vnd' => '1.000',
            'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 1, 'discount_vnd' => 0, 'tax_vnd' => 0,
            'total_vnd' => 1, 'status' => 'ISSUED',
            'issued_at' => gmdate('Y-m-d H:i:s', strtotime('2001-01-01 00:00:00 UTC') + $index) . '.000000',
            'due_at' => '2001-01-31 23:59:59.999999', 'issued_by' => $accountantId,
        ]);
    }

    $assignedAccount = $insert('erp_cash_accounts', [
        'account_code' => 'CASH-PAGE-' . $suffix, 'name' => 'Assigned cash page account', 'type' => 'CASH', 'currency' => 'VND',
        'opening_balance_vnd' => 5000, 'status' => 'ACTIVE', 'created_by' => $accountantId, 'activated_by' => $managerId,
        'activated_at' => $now, 'created_at' => $now,
    ]);
    $unassignedAccount = $insert('erp_cash_accounts', [
        'account_code' => 'CASH-HIDDEN-' . $suffix, 'name' => 'Unassigned cash page account', 'type' => 'CASH', 'currency' => 'VND',
        'opening_balance_vnd' => 0, 'status' => 'ACTIVE', 'created_by' => $accountantId, 'activated_by' => $managerId,
        'activated_at' => $now, 'created_at' => $now,
    ]);
    $unassignedDraft = $insert('erp_cash_accounts', [
        'account_code' => 'CASH-DRAFT-' . $suffix, 'name' => 'Unassigned draft cash account', 'type' => 'CASH', 'currency' => 'VND',
        'opening_balance_vnd' => 0, 'status' => 'DRAFT', 'created_by' => $unassignedManagerId, 'created_at' => $now,
    ]);
    $rowIds['accounts'] = [$assignedAccount, $unassignedAccount, $unassignedDraft];
    update_user_meta($managerId, '_mini_erp_cash_account_ids', [$assignedAccount]);
    update_user_meta($accountantId, '_mini_erp_cash_account_ids', [$assignedAccount]);

    foreach ([[$assignedAccount, 'PAY-CASHPAGE-' . $suffix, 1000, 'REF-' . $suffix], [$unassignedAccount, 'PAY-HIDDEN-' . $suffix, 700, 'HIDDEN-' . $suffix]] as [$accountId, $number, $amount, $reference]) {
        $paymentId = $insert('erp_payments', [
            'payment_no' => $number, 'invoice_id' => $rowIds['invoice'], 'direction' => 'IN', 'amount_vnd' => $amount,
            'cash_account_id' => $accountId, 'method' => 'BANK_TRANSFER', 'external_reference' => $reference,
            'paid_at' => $now, 'posted_at' => $now, 'status' => 'POSTED', 'created_by' => $accountantId,
            'request_key' => wp_generate_uuid4(),
        ]);
        $rowIds['payments'][] = $paymentId;
        $eventKey = 'PAYMENT_POST:' . $paymentId;
        $insert('erp_cash_movements', [
            'cash_account_id' => $accountId, 'event_key' => $eventKey, 'source_type' => 'PAYMENT',
            'source_id' => $paymentId, 'direction' => 'IN', 'amount_vnd' => $amount, 'occurred_at' => $now,
            'actor_id' => $accountantId, 'external_reference' => $reference, 'created_at' => $now,
        ]);
        $movementKeys[] = $eventKey;
    }
    for ($index = 1; $index <= 101; $index++) {
        $paymentId = $insert('erp_payments', [
            'payment_no' => sprintf('PAY-PAGE-%s-%03d', $suffix, $index), 'invoice_id' => $rowIds['invoice'], 'direction' => 'IN', 'amount_vnd' => 1,
            'cash_account_id' => $assignedAccount, 'method' => 'BANK_TRANSFER', 'external_reference' => 'PAGE-' . $index,
            'paid_at' => $now, 'posted_at' => $now, 'status' => 'POSTED', 'created_by' => $accountantId,
            'request_key' => wp_generate_uuid4(),
        ]);
        $rowIds['payments'][] = $paymentId;
        $eventKey = 'PAYMENT_POST:' . $paymentId;
        $insert('erp_cash_movements', [
            'cash_account_id' => $assignedAccount, 'event_key' => $eventKey, 'source_type' => 'PAYMENT',
            'source_id' => $paymentId, 'direction' => 'IN', 'amount_vnd' => 1, 'occurred_at' => $now,
            'actor_id' => $accountantId, 'external_reference' => 'PAGE-' . $index, 'created_at' => $now,
        ]);
        $movementKeys[] = $eventKey;
    }

    foreach ([[$assignedAccount, 'EXP-CASHPAGE-' . $suffix, 'Assigned office expense'], [$unassignedAccount, 'EXP-HIDDEN-' . $suffix, 'Hidden office expense']] as [$accountId, $number, $description]) {
        $expenseId = $insert('erp_expenses', [
            'expense_no' => $number, 'category' => 'Office', 'description' => $description, 'amount_vnd' => 200,
            'occurred_at' => $now, 'recorded_by' => $accountantId, 'cash_account_id' => $accountId,
            'status' => 'POSTED', 'request_key' => wp_generate_uuid4(),
        ]);
        $rowIds['expenses'][] = $expenseId;
        $eventKey = 'EXPENSE_POST:' . $expenseId;
        $insert('erp_cash_movements', [
            'cash_account_id' => $accountId, 'event_key' => $eventKey, 'source_type' => 'EXPENSE',
            'source_id' => $expenseId, 'direction' => 'OUT', 'amount_vnd' => 200, 'occurred_at' => $now,
            'actor_id' => $accountantId, 'external_reference' => '', 'created_at' => $now,
        ]);
        $movementKeys[] = $eventKey;
    }

    $historicalStart = new DateTimeImmutable('2000-01-01', new DateTimeZone('UTC'));
    for ($index = 1; $index <= 101; $index++) {
        $day = $historicalStart->modify('+' . $index . ' days')->format('Y-m-d');
        $rowIds['reconciliations'][] = $insert('erp_reconciliations', [
            'rec_no' => sprintf('REC-CASHPAGE-%s-%03d', $suffix, $index), 'cash_account_id' => $assignedAccount,
            'period_from' => $day, 'period_to' => $day, 'statement_opening_vnd' => 0, 'statement_closing_vnd' => 0,
            'status' => 'DRAFT', 'created_by' => $accountantId,
        ]);
    }
    $reconciliationDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
    $detailBatch = $insert('erp_reconciliations', [
        'rec_no' => 'REC-CASHPAGE-' . $suffix . '-OPEN', 'cash_account_id' => $assignedAccount,
        'period_from' => $reconciliationDate, 'period_to' => $reconciliationDate, 'statement_opening_vnd' => 0,
        'statement_closing_vnd' => 0, 'status' => 'DRAFT', 'created_by' => $accountantId,
    ]);
    $rowIds['reconciliations'][] = $detailBatch;
    $hiddenBatch = $insert('erp_reconciliations', [
        'rec_no' => 'REC-HIDDEN-' . $suffix, 'cash_account_id' => $unassignedAccount,
        'period_from' => $reconciliationDate, 'period_to' => $reconciliationDate, 'statement_opening_vnd' => 0,
        'statement_closing_vnd' => 0, 'status' => 'DRAFT', 'created_by' => $accountantId,
    ]);
    $rowIds['reconciliations'][] = $hiddenBatch;

    $managerAccounts = $render($managerId, static fn() => CashController::renderAccounts());
    $assert(str_contains($managerAccounts, 'CASH-PAGE-' . $suffix) && str_contains($managerAccounts, '5.901'), 'Manager did not see the assigned cash account and its derived balance.');
    $assert(!str_contains($managerAccounts, 'CASH-HIDDEN-' . $suffix) && !str_contains($managerAccounts, 'CASH-DRAFT-' . $suffix), 'Manager saw an unassigned cash account or creator-owned draft.');
    $results[] = 'Manager cash-account screen shows only assigned account and its derived balance';

    $reconciliationPageThree = $render($accountantId, static fn() => ReconciliationController::render(), ['paged' => '3']);
    $assert(str_contains($reconciliationPageThree, 'Trang 3 / 3 · 102 batch')
        && str_contains($reconciliationPageThree, 'REC-CASHPAGE-' . $suffix . '-001')
        && !str_contains($reconciliationPageThree, 'REC-CASHPAGE-' . $suffix . '-101')
        && !str_contains($reconciliationPageThree, 'REC-HIDDEN-' . $suffix),
        'Reconciliation list pagination omitted an older assigned batch or exposed an unassigned account.');
    $lastPaymentId = $rowIds['payments'][array_key_last($rowIds['payments'])];
    $firstPaymentId = $rowIds['payments'][0];
    $reconciliationDetailPageThree = $render($accountantId, static fn() => ReconciliationController::render(), ['batch' => (string) $detailBatch, 'movement_page' => '3']);
    $assert(str_contains($reconciliationDetailPageThree, 'Trang 3 / 3 · 103 dòng')
        && str_contains($reconciliationDetailPageThree, 'PAYMENT #' . $lastPaymentId)
        && !str_contains($reconciliationDetailPageThree, 'PAYMENT #' . $firstPaymentId)
        && str_contains($reconciliationDetailPageThree, 'data-finance-form="reconciliation-match"')
        && str_contains($reconciliationDetailPageThree, 'data-finance-scope="match-' . $detailBatch . '-')
        && str_contains($reconciliationDetailPageThree, 'data-finance-form="reconciliation-create"')
        && str_contains($reconciliationDetailPageThree, 'movement_page=2'),
        'Reconciliation detail did not expose paginated unmatched movements, form recovery scopes, and navigation after the first 100 rows.');
    $reconciliationPreview = $render($managerId, static fn() => ReconciliationController::render(), ['batch' => (string) $detailBatch]);
    $assert(str_contains($reconciliationPreview, 'Số dư sổ đầu/cuối hiện tại: 5.000 / 5.901 ₫')
        && str_contains($reconciliationPreview, 'Chênh lệch sao kê − sổ: -5.000 / -5.901 ₫')
        && str_contains($reconciliationPreview, 'Còn 103 dòng chưa ghép.')
        && str_contains($reconciliationPreview, 'Chưa đủ điều kiện chốt')
        && !str_contains($reconciliationPreview, 'mini_erp_reconciliation_close'),
        'Reconciliation preview did not explain ledger variance/unmatched movements or incorrectly exposed the close action.');

    $historyBatch = $insert('erp_reconciliations', [
        'rec_no' => 'REC-CASHPAGE-' . $suffix . '-MATCHED', 'cash_account_id' => $assignedAccount,
        'period_from' => '2001-01-01', 'period_to' => '2001-01-01', 'statement_opening_vnd' => 5000,
        'statement_closing_vnd' => 15300, 'status' => 'DRAFT', 'created_by' => $accountantId,
    ]);
    $rowIds['reconciliations'][] = $historyBatch;
    for ($index = 1; $index <= 103; $index++) {
        $eventKey = sprintf('RECON-MATCHED-%s-%03d', $suffix, $index);
        $movementId = $insert('erp_cash_movements', [
            'cash_account_id' => $assignedAccount, 'event_key' => $eventKey, 'source_type' => 'TEST',
            'source_id' => $index, 'direction' => 'IN', 'amount_vnd' => 100,
            'occurred_at' => '2000-12-31 19:00:00.000000', 'actor_id' => $accountantId,
            'external_reference' => '', 'created_at' => $now,
        ]);
        $movementKeys[] = $eventKey;
        if ($index <= 102) {
            $rowIds['reconciliation_items'][] = $insert('erp_reconciliation_items', [
                'reconciliation_id' => $historyBatch, 'cash_movement_id' => $movementId,
                'external_reference' => sprintf('MATCH-CASHPAGE-%s-%03d', $suffix, $index),
                'external_amount_vnd' => 100, 'matched_by' => $accountantId,
                'matched_at' => '2001-01-02 12:00:00.000000',
            ]);
        }
    }
    $matchedHistoryPageThree = $render($managerId, static fn() => ReconciliationController::render(), [
        'batch' => (string) $historyBatch, 'matched_page' => '3', 'movement_page' => '1',
    ]);
    $assert(str_contains($matchedHistoryPageThree, 'Trang 3 / 3 · 102 dòng')
        && str_contains($matchedHistoryPageThree, 'MATCH-CASHPAGE-' . $suffix . '-')
        && str_contains($matchedHistoryPageThree, 'Người ghép')
        && str_contains($matchedHistoryPageThree, '#' . $accountantId)
        && str_contains($matchedHistoryPageThree, '2001-01-01 02:00:00')
        && str_contains($matchedHistoryPageThree, 'matched_page=2')
        && str_contains($matchedHistoryPageThree, 'Số dư sổ đầu/cuối hiện tại: 5.000 / 15.300 ₫')
        && str_contains($matchedHistoryPageThree, 'Còn 1 dòng chưa ghép.')
        && !str_contains($matchedHistoryPageThree, 'mini_erp_reconciliation_close'),
        'Matched reconciliation history did not paginate, localize time, preserve scope, or correctly block close with an unmatched row.');
    $results[] = 'Reconciliation matched-history pages, local ledger preview, Vietnam time rendering, and close readiness are visible and scoped';
    $unassignedReconciliation = $preflight($unassignedManagerId, static fn($actor) => ReconciliationController::preflightDetail($actor), ['batch' => (string) $detailBatch]);
    $assert($unassignedReconciliation === 404, 'A Manager without cash-account scope did not receive a generic reconciliation-detail 404.');
    $results[] = 'Reconciliation batches and unmatched cash rows paginate beyond 100 while enforcing assigned-account scope';

    $managerDashboard = $render($managerId, static fn() => ReportController::dashboard());
    $assert(str_contains($managerDashboard, 'Mã hàng trong tồn kho')
        && str_contains($managerDashboard, 'Tổng hợp từ tất cả kho.')
        && !str_contains($managerDashboard, 'Chưa được gán kho'),
        'Manager dashboard incorrectly treated an unassigned warehouse list as no stock visibility.');
    $managerStockReport = $render($managerId, static fn() => ReportController::render(), ['view' => 'stock']);
    $assert(str_contains($managerStockReport, 'Tổng hợp tồn kho tại tất cả kho.')
        && str_contains($managerStockReport, 'CASH-PAGE-' . $suffix)
        && str_contains($managerStockReport, 'CASH-HIDDEN-' . $suffix)
        && str_contains($managerStockReport, 'Tải CSV')
        && str_contains($managerStockReport, 'mini_erp_report_export'),
        'Manager stock report omitted company-wide stock or described its scope incorrectly.');
    $warehouseInventory = $render($warehouseUserId, static fn() => InventoryController::render());
    $assert(str_contains($warehouseInventory, 'Cash page warehouse')
        && !str_contains($warehouseInventory, 'Unassigned warehouse')
        && str_contains($warehouseInventory, 'Cash page item')
        && str_contains($warehouseInventory, 'data-inventory-form="inventory-start"')
        && str_contains($warehouseInventory, 'data-inventory-scope="start"'),
        'Warehouse inventory screen did not stay within its assigned warehouse.');
    $results[] = 'Manager dashboard and stock report show all warehouses while Warehouse inventory remains assigned-scope only';

    $salesFiltered = $render($managerId, static fn() => SalesController::render(), ['status' => 'SHIPPED']);
    $assert(str_contains($salesFiltered, 'SO-CASHPAGE-' . $suffix) && !str_contains($salesFiltered, $draftOrderNumber), 'Sales status filter did not narrow the rendered order list.');
    $salesHistoryFirst = $render($managerId, static fn() => SalesController::render(), ['order' => (string) $rowIds['order']]);
    $salesHistoryLast = $render($managerId, static fn() => SalesController::render(), ['order' => (string) $rowIds['order'], 'shipment_page' => '3']);
    $assert(str_contains($salesHistoryFirst, 'Lịch sử phiếu giao hàng')
        && str_contains($salesHistoryFirst, 'Trang 1 / 3')
        && str_contains($salesHistoryFirst, 'SHP-CASHPAGE-' . $suffix)
        && str_contains($salesHistoryLast, 'SHP-PAGE-' . $suffix . '-001')
        && str_contains($salesHistoryLast, 'SHP-PAGE-' . $suffix . '-002')
        && !str_contains($salesHistoryLast, 'SHP-PAGE-' . $suffix . '-003'),
        'Sales order detail did not show its immutable shipment history with bounded pagination.');
    $purchaseFiltered = $render($managerId, static fn() => PurchaseController::render(), ['status' => 'APPROVED']);
    $assert(str_contains($purchaseFiltered, 'PO-CASHPAGE-APPROVED-' . $suffix) && !str_contains($purchaseFiltered, 'PO-CASHPAGE-DRAFT-' . $suffix), 'Purchase status filter did not narrow the rendered order list.');
    $results[] = 'Sales and purchase status filters select only the requested uppercase workflow state';

    $warehouseSalesQueue = $render($warehouseUserId, static fn() => SalesController::render());
    $assert(str_contains($warehouseSalesQueue, $salesQueueNumbers['confirmed'])
        && str_contains($warehouseSalesQueue, $salesQueueNumbers['partial'])
        && !str_contains($warehouseSalesQueue, $salesQueueNumbers['shipped'])
        && !str_contains($warehouseSalesQueue, $salesQueueNumbers['hidden']),
        'Warehouse shipment queue included a completed order or an unassigned warehouse.');
    $warehouseShippedDetail = $preflight($warehouseUserId, static fn($actor) => SalesController::preflightDetail($actor), ['order' => (string) $salesQueueIds['shipped']]);
    $assert($warehouseShippedDetail === 404, 'Warehouse detail did not return a generic 404 for a completed order outside its actionable queue.');
    $warehousePurchaseQueue = $render($warehouseUserId, static fn() => PurchaseController::render());
    $assert(str_contains($warehousePurchaseQueue, 'PO-CASHPAGE-APPROVED-' . $suffix)
        && str_contains($warehousePurchaseQueue, 'PO-CASHPAGE-RECEIPT-' . $suffix)
        && !str_contains($warehousePurchaseQueue, 'PO-CASHPAGE-DRAFT-' . $suffix)
        && !str_contains($warehousePurchaseQueue, $receivedPurchaseOrderNumber)
        && !str_contains($warehousePurchaseQueue, $hiddenPurchaseOrderNumber),
        'Warehouse receipt queue included a non-actionable status or an unassigned warehouse.');
    $warehouseReceiptDetail = $render($warehouseUserId, static fn() => PurchaseController::render(), ['po' => (string) $rowIds['purchase_orders'][2]]);
    $warehouseReceiptHistoryLast = $render($warehouseUserId, static fn() => PurchaseController::render(), ['po' => (string) $rowIds['purchase_orders'][2], 'receipt_page' => '3']);
    $assert(str_contains($warehouseReceiptDetail, 'PO-CASHPAGE-RECEIPT-' . $suffix)
        && str_contains($warehouseReceiptDetail, 'mini_erp_goods_receipt')
        && str_contains($warehouseReceiptDetail, 'Lịch sử phiếu nhận hàng')
        && str_contains($warehouseReceiptDetail, 'Trang 1 / 3')
        && !str_contains($warehouseReceiptDetail, 'Cash page supplier')
        && !str_contains($warehouseReceiptDetail, 'Đơn giá')
        && str_contains($warehouseReceiptHistoryLast, 'RCPT-PAGE-' . $suffix . '-001')
        && !str_contains($warehouseReceiptHistoryLast, 'RCPT-PAGE-' . $suffix . '-002'),
        'Warehouse receipt detail exposed supplier/cost fields or omitted the authorized receive action.');
    $warehouseDraftDetail = $preflight($warehouseUserId, static fn($actor) => PurchaseController::preflightDetail($actor), ['po' => (string) $rowIds['purchase_orders'][1]]);
    $warehouseHiddenDetail = $preflight($warehouseUserId, static fn($actor) => PurchaseController::preflightDetail($actor), ['po' => (string) $rowIds['purchase_orders'][4]]);
    $assert($warehouseDraftDetail === 404 && $warehouseHiddenDetail === 404,
        'Warehouse detail did not return generic 404 responses for a draft or an order in an unassigned warehouse.');
    $results[] = 'Warehouse sales/purchase queues enforce actionable status, assigned warehouse, generic detail denial, and cost-free receipt projections';

    $managerPayments = $render($managerId, static fn() => CashController::renderPayments());
    $managerPaymentsPageThree = $render($managerId, static fn() => CashController::renderPayments(), ['paged' => '3']);
    $assert(!str_contains($managerPayments, 'PAY-CASHPAGE-' . $suffix) && str_contains($managerPaymentsPageThree, 'PAY-CASHPAGE-' . $suffix) && !str_contains($managerPaymentsPageThree, 'PAY-HIDDEN-' . $suffix), 'Manager payment pagination or assigned-account scope is incorrect.');
    $assert(str_contains($managerPaymentsPageThree, 'Trang 3 / 3') && str_contains($managerPaymentsPageThree, 'mini_erp_payment_voucher_print') && str_contains($managerPaymentsPageThree, 'mini_erp_payment_voucher_pdf'), 'Payment older than the first 100 rows omitted its page or protected voucher actions.');
    $invoiceRowsAhead = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'erp_invoices WHERE issued_at>%s OR (issued_at=%s AND id>%d)',
        '2000-01-01 00:00:00.000000', '2000-01-01 00:00:00.000000', $rowIds['invoice'],
    ));
    $invoicePage = intdiv($invoiceRowsAhead, 50) + 1;
    $oldInvoice = $render($accountantId, static fn() => InvoiceController::render(), ['paged' => (string) $invoicePage]);
    $oldInvoiceNumber = 'INV-' . gmdate('Y') . '-' . substr($suffix, 0, 8);
    $assert(str_contains($oldInvoice, $oldInvoiceNumber)
        && str_contains($oldInvoice, 'invoice=' . $rowIds['invoice'])
        && str_contains($oldInvoice, 'Đã phát hành'),
        'Invoice older than the first 100 rows lost its detail route or visible status.');
    $sourceRows = $wpdb->get_col(
        'SELECT sh.id FROM ' . $wpdb->prefix . 'erp_shipments sh JOIN ' . $wpdb->prefix . 'erp_sales_orders so ON so.id=sh.sales_order_id JOIN ' . $wpdb->prefix . 'erp_shipment_items si ON si.shipment_id=sh.id LEFT JOIN ' . $wpdb->prefix . 'erp_invoices i ON i.source_type="SHIPMENT" AND i.source_id=sh.id WHERE i.id IS NULL AND so.status IN ("PARTIALLY_SHIPPED","SHIPPED","CLOSED") ORDER BY sh.shipped_at DESC,sh.id DESC',
    );
    $sourceIndex = array_search((string) $rowIds['extra_shipments'][0], array_map('strval', is_array($sourceRows) ? $sourceRows : []), true);
    $assert($sourceIndex !== false, 'Test shipment was not eligible for invoice issuance.');
    $sourcePage = intdiv((int) $sourceIndex, 50) + 1;
    $sourcePageCount = max(1, (int) ceil(count(is_array($sourceRows) ? $sourceRows : []) / 50));
    $oldSources = $render($accountantId, static fn() => InvoiceController::render(), ['shipments_page' => (string) $sourcePage]);
    $assert(str_contains($oldSources, 'SHP-PAGE-' . $suffix . '-001') && str_contains($oldSources, 'mini_erp_invoice_issue'), 'Older eligible shipment lost its invoice issue action.');
    $sourceNavigationTarget = $sourcePage < $sourcePageCount ? $sourcePage + 1 : $sourcePage - 1;
    $assert($sourcePageCount === 1 || str_contains($oldSources, 'shipments_page=' . $sourceNavigationTarget), 'Invoice source navigation omitted adjacent-page links.');
    $receiptRows = $wpdb->get_col(
        'SELECT gr.id FROM ' . $wpdb->prefix . 'erp_goods_receipts gr JOIN ' . $wpdb->prefix . 'erp_purchase_orders po ON po.id=gr.purchase_order_id JOIN ' . $wpdb->prefix . 'erp_goods_receipt_items ri ON ri.goods_receipt_id=gr.id LEFT JOIN ' . $wpdb->prefix . 'erp_invoices i ON i.source_type="GOODS_RECEIPT" AND i.source_id=gr.id WHERE i.id IS NULL AND po.status IN ("PARTIALLY_RECEIVED","RECEIVED","CLOSED") ORDER BY gr.received_at DESC,gr.id DESC',
    );
    $receiptIndex = array_search((string) $rowIds['receipts'][0], array_map('strval', is_array($receiptRows) ? $receiptRows : []), true);
    $assert($receiptIndex !== false, 'Test receipt was not eligible for invoice issuance.');
    $receiptPage = intdiv((int) $receiptIndex, 50) + 1;
    $receiptPageCount = max(1, (int) ceil(count(is_array($receiptRows) ? $receiptRows : []) / 50));
    $oldReceipts = $render($accountantId, static fn() => InvoiceController::render(), ['receipts_page' => (string) $receiptPage]);
    $assert(str_contains($oldReceipts, 'RCPT-PAGE-' . $suffix . '-001') && str_contains($oldReceipts, 'mini_erp_invoice_issue'), 'Older eligible receipt lost its invoice issue action.');
    $receiptNavigationTarget = $receiptPage < $receiptPageCount ? $receiptPage + 1 : $receiptPage - 1;
    $assert($receiptPageCount === 1 || str_contains($oldReceipts, 'receipts_page=' . $receiptNavigationTarget), 'Receipt source navigation omitted adjacent-page links.');
    $issuedInvoiceDetail = $render($accountantId, static fn() => InvoiceController::render(), ['invoice' => (string) $rowIds['invoice']]);
    $assert(str_contains($issuedInvoiceDetail, 'Trạng thái: Đã phát hành')
        && str_contains($issuedInvoiceDetail, 'name="action" value="mini_erp_payment_post"')
        && str_contains($issuedInvoiceDetail, 'data-finance-form="payment"')
        && str_contains($issuedInvoiceDetail, 'data-finance-scope="invoice-' . $rowIds['invoice'] . '"'),
        'An issued invoice did not show its status, assigned-account payment action, and invoice-specific recovery scope.');
    $voidSourceItem = (int) $rowIds['extra_shipment_items'][0];
    $rowIds['void_invoice'] = $insert('erp_invoices', [
        'invoice_no' => 'INV-VOID-' . $suffix, 'source_type' => 'SHIPMENT', 'source_id' => $rowIds['extra_shipments'][0],
        'direction' => 'IN', 'party_id' => $rowIds['customer'], 'seller_name' => 'Mini ERP', 'seller_address' => 'Hà Nội',
        'seller_tax_id' => '0100000000', 'buyer_name' => 'Cash page buyer', 'buyer_address' => 'Hồ Chí Minh', 'buyer_tax_id' => '0200000000',
        'subtotal_exact_vnd' => '10000.000', 'rounding_adjustment_vnd' => '0.000', 'subtotal_vnd' => 10000,
        'discount_vnd' => 0, 'tax_vnd' => 0, 'total_vnd' => 10000, 'status' => 'VOID',
        'issued_at' => '2000-01-01 00:00:00.000000', 'due_at' => '2001-01-31 23:59:59.999999',
        'issued_by' => $accountantId, 'void_reason' => 'Local smoke fixture', 'voided_by' => $accountantId, 'voided_at' => $now,
    ]);
    $insert('erp_invoice_items', [
        'invoice_id' => $rowIds['void_invoice'], 'source_item_id' => $voidSourceItem, 'sku_snapshot' => 'CASH-PAGE',
        'name_snapshot' => 'Cash page item', 'unit_snapshot' => 'cái', 'quantity' => '1.000', 'unit_price_vnd' => 10000,
        'line_subtotal_exact_vnd' => '10000.000', 'rounding_allocation_vnd' => '0.000', 'line_subtotal_vnd' => 10000,
        'discount_vnd' => 0, 'tax_vnd' => 0, 'line_total_vnd' => 10000,
    ]);
    $voidInvoiceDetail = $render($accountantId, static fn() => InvoiceController::render(), ['invoice' => (string) $rowIds['void_invoice']]);
    $assert(str_contains($voidInvoiceDetail, 'Trạng thái: Đã hủy')
        && !str_contains($voidInvoiceDetail, 'name="action" value="mini_erp_payment_post"')
        && !str_contains($voidInvoiceDetail, 'name="action" value="mini_erp_invoice_void"'),
        'A void invoice still offered a payment or void action.');
    $results[] = 'Invoice list and detail show issued/void status; void invoices suppress payment and void controls';
    $results[] = 'Invoice and eligible-shipment pages keep detail and issue actions available beyond 100 records';
    $managerExpenses = $render($managerId, static fn() => CashController::renderExpenses());
    $assert(str_contains($managerExpenses, 'EXP-CASHPAGE-' . $suffix) && !str_contains($managerExpenses, 'EXP-HIDDEN-' . $suffix), 'Manager expense list escaped its assigned-account scope.');
    $assert(str_contains($managerExpenses, 'chỉ đọc') && !str_contains($managerExpenses, 'name="action" value="mini_erp_expense_post"'), 'Manager expense view exposed the write form.');
    $assert(str_contains($managerExpenses, 'mini_erp_expense_voucher_print') && str_contains($managerExpenses, 'mini_erp_expense_voucher_pdf'), 'Expense list omitted protected voucher actions.');
    $results[] = 'Manager retains payment vouchers after 100 rows and sees assigned expenses without write controls';

    $accessSettings = $render($systemAdminId, static fn() => UserAccessController::render());
    $selectedCashScopeCount = preg_match_all('/<option value="' . preg_quote((string) $assignedAccount, '/') . '"[^>]*selected/i', $accessSettings);
    $selectedWarehouseScopeCount = preg_match_all('/<option value="' . preg_quote((string) $rowIds['warehouse'], '/') . '"[^>]*selected/i', $accessSettings);
    $assert(str_contains($accessSettings, 'name="cash_account_ids[]"')
        && $selectedCashScopeCount >= 2
        && $selectedWarehouseScopeCount >= 1
        && str_contains($accessSettings, 'CASH-PAGE-' . $suffix . ' · Assigned cash page account · Tiền mặt · Đang hoạt động')
        && str_contains($accessSettings, 'CASH-DRAFT-' . $suffix . ' · Unassigned draft cash account · Tiền mặt · Nháp')
        && !str_contains($accessSettings, '5.901'),
        'ERP user-access screen did not render readable, balance-free cash assignments or preserve existing selections.');
    $results[] = 'ERP System Admin assigns readable warehouse and cash-account options while preserving scopes without exposing balances';

    $accountantExpenses = $render($accountantId, static fn() => CashController::renderExpenses());
    $assert(str_contains($accountantExpenses, 'EXP-CASHPAGE-' . $suffix)
        && str_contains($accountantExpenses, 'name="action" value="mini_erp_expense_post"')
        && str_contains($accountantExpenses, 'data-finance-form="expense"')
        && str_contains($accountantExpenses, 'data-finance-scope="expense"'),
        'Accountant did not receive the assigned expense list and recoverable posting form.');
    $unassignedAccounts = $render($unassignedManagerId, static fn() => CashController::renderAccounts());
    $assert(!str_contains($unassignedAccounts, 'CASH-DRAFT-' . $suffix), 'Account creator saw an unassigned DRAFT account.');
    $unassignedExpenses = $render($unassignedManagerId, static fn() => CashController::renderExpenses());
    $assert(!str_contains($unassignedExpenses, 'EXP-CASHPAGE-' . $suffix) && !str_contains($unassignedExpenses, 'EXP-HIDDEN-' . $suffix), 'Manager without cash assignments saw expense rows.');
    $results[] = 'Accountant retains expense posting; an unassigned Manager sees no cash account or expense rows';
} catch (Throwable $failure) {
    $exitCode = 1;
    fwrite(STDERR, 'Cash page smoke failed (' . get_class($failure) . ', code ' . $failure->getCode() . '). ' . $failure->getMessage() . "\n");
} finally {
    wp_set_current_user(1);
    $_GET = [];
    foreach ($rowIds['reconciliation_items'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_reconciliation_items', ['id' => $id]);
    }
    foreach ($rowIds['payments'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_payments', ['id' => $id]);
    }
    foreach ($rowIds['expenses'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_expenses', ['id' => $id]);
    }
    foreach ($movementKeys as $eventKey) {
        $wpdb->delete($wpdb->prefix . 'erp_cash_movements', ['event_key' => $eventKey]);
    }
    foreach ($rowIds['reconciliations'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_reconciliations', ['id' => $id]);
    }
    $reconciliationIds = array_values(array_filter(array_map('intval', $rowIds['reconciliations']), static fn (int $id): bool => $id > 0));
    if ($reconciliationIds !== []) {
        $slots = implode(',', array_fill(0, count($reconciliationIds), '%d'));
        $remainingBatches = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'erp_reconciliations WHERE id IN (' . $slots . ')', $reconciliationIds));
        $remainingItems = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'erp_reconciliation_items WHERE reconciliation_id IN (' . $slots . ')', $reconciliationIds));
        if ($remainingBatches !== 0 || $remainingItems !== 0 || $wpdb->last_error !== '') {
            $exitCode = 1;
            fwrite(STDERR, "Could not verify exact reconciliation fixture cleanup.\n");
        }
    }
    foreach ($rowIds['accounts'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_cash_accounts', ['id' => $id]);
    }
    foreach ($rowIds['extra_invoices'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_invoices', ['id' => $id]);
    }
    if ($rowIds['invoice'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_invoice_items', ['invoice_id' => $rowIds['invoice']]);
        $wpdb->delete($wpdb->prefix . 'erp_invoices', ['id' => $rowIds['invoice']]);
    }
    if ($rowIds['void_invoice'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_invoice_items', ['invoice_id' => $rowIds['void_invoice']]);
        $wpdb->delete($wpdb->prefix . 'erp_invoices', ['id' => $rowIds['void_invoice']]);
    }
    if ($rowIds['shipment'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_shipments', ['id' => $rowIds['shipment']]);
    }
    foreach ($rowIds['extra_shipment_items'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_shipment_items', ['id' => $id]);
    }
    foreach ($rowIds['extra_shipments'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_shipments', ['id' => $id]);
    }
    foreach ($rowIds['receipt_items'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_goods_receipt_items', ['id' => $id]);
    }
    foreach ($rowIds['receipts'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_goods_receipts', ['id' => $id]);
    }
    if ($rowIds['purchase_item'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_purchase_order_items', ['id' => $rowIds['purchase_item']]);
    }
    if ($rowIds['sales_item'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_sales_order_items', ['id' => $rowIds['sales_item']]);
    }
    if ($rowIds['order'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_sales_orders', ['id' => $rowIds['order']]);
    }
    foreach ($rowIds['sales_orders'] as $id) {
        if ($id !== $rowIds['order']) {
            $wpdb->delete($wpdb->prefix . 'erp_sales_orders', ['id' => $id]);
        }
    }
    foreach ($rowIds['purchase_orders'] as $id) {
        $wpdb->delete($wpdb->prefix . 'erp_purchase_orders', ['id' => $id]);
    }
    if ($rowIds['supplier'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_suppliers', ['id' => $rowIds['supplier']]);
    }
    if ($rowIds['customer'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_customers', ['id' => $rowIds['customer']]);
    }
    if ($rowIds['product'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_stock_balances', ['product_id' => $rowIds['product']]);
    }
    if ($rowIds['warehouse'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_warehouses', ['id' => $rowIds['warehouse']]);
    }
    if ($rowIds['hidden_warehouse'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_warehouses', ['id' => $rowIds['hidden_warehouse']]);
    }
    if ($rowIds['product'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_products', ['id' => $rowIds['product']]);
    }
    if ($rowIds['category'] > 0) {
        $wpdb->delete($wpdb->prefix . 'erp_categories', ['id' => $rowIds['category']]);
    }
    foreach ($userIds as $userId) {
        if (!wp_delete_user($userId)) {
            $exitCode = 1;
            fwrite(STDERR, "Could not remove a temporary ERP user fixture.\n");
        }
    }
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Cash page role-projection smoke passed; temporary users and rows were removed.\n - " . implode("\n - ", $results) . "\n");
}
exit($exitCode);
