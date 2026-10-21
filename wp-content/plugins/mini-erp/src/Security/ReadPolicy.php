<?php
declare(strict_types=1);

namespace MiniERP\Security;

/** Pure row-scope and response-field policy. Repositories must apply the descriptor before querying. */
final class ReadPolicy
{
    /** @return array{kind: string, user_id?: int, warehouse_ids?: list<int>, statuses?: list<string>} */
    public function orderScope(Actor $actor, string $resource): array
    {
        if ($resource === 'sales_orders') {
            if ($actor->can('erp_sales_view_all')) {
                return ['kind' => 'all'];
            }
            if ($actor->can('erp_sales_view_own')) {
                return ['kind' => 'own_sales', 'user_id' => $actor->id];
            }
            if ($actor->can('erp_stock_ship') && $actor->warehouseIds !== []) {
                return ['kind' => 'warehouse_ids', 'warehouse_ids' => $actor->warehouseIds, 'statuses' => ['CONFIRMED', 'PARTIALLY_SHIPPED']];
            }
            return ['kind' => 'none'];
        }

        if ($resource === 'purchase_orders') {
            if ($actor->can('erp_stock_receive')) {
                if ($actor->warehouseIds === []) {
                    return ['kind' => 'none'];
                }
                return ['kind' => 'warehouse_ids', 'warehouse_ids' => $actor->warehouseIds, 'statuses' => ['APPROVED', 'PARTIALLY_RECEIVED']];
            }
            if ($actor->can('erp_purchases_view')) {
                return ['kind' => 'all'];
            }
        }
        return ['kind' => 'none'];
    }

    /** @return array{kind: 'all'|'own_sales'|'none', user_id?: int} */
    public function invoiceScope(Actor $actor): array
    {
        if (!$actor->can('erp_invoices_view')) {
            return ['kind' => 'none'];
        }
        if ($actor->can('erp_invoice_sources_view_all')) {
            return ['kind' => 'all'];
        }
        if ($actor->can('erp_invoice_sources_view_own')) {
            return ['kind' => 'own_sales', 'user_id' => $actor->id];
        }
        return ['kind' => 'none'];
    }

    /** @param array{source_type?: mixed, source_creator_id?: mixed, source_warehouse_id?: mixed} $trustedSource */
    public function canReadInvoice(Actor $actor, array $trustedSource): bool
    {
        $scope = $this->invoiceScope($actor);
        if ($scope['kind'] === 'all') {
            return in_array($trustedSource['source_type'] ?? null, ['SHIPMENT', 'GOODS_RECEIPT'], true);
        }
        if ($scope['kind'] !== 'own_sales' || ($trustedSource['source_type'] ?? null) !== 'SHIPMENT') {
            return false;
        }

        return filter_var($trustedSource['source_creator_id'] ?? null, FILTER_VALIDATE_INT) === $scope['user_id'];
    }

    /** Warehouse reads intentionally exclude customer contact and all commercial values. */
    public function salesOrderProjection(Actor $actor, array $row): array
    {
        if (!$this->canReadSalesOrder($actor, $row)) {
            return [];
        }

        $fields = $actor->can('erp_stock_ship')
            ? ['id', 'order_no', 'warehouse_id', 'status', 'version', 'created_at']
            : ['id', 'order_no', 'customer_id', 'warehouse_id', 'created_by', 'approved_by', 'status', 'discount_bps', 'subtotal_vnd', 'discount_vnd', 'tax_vnd', 'total_vnd', 'version', 'created_at', 'note'];
        return $this->pick($row, $fields);
    }

    /** @param array<string, mixed> $row */
    public function canReadSalesOrder(Actor $actor, array $row): bool
    {
        if (($id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT)) === false || $id < 1) {
            return false;
        }
        if ($actor->can('erp_sales_view_all')) {
            return true;
        }
        if ($actor->can('erp_sales_view_own') && (int) ($row['created_by'] ?? 0) === $actor->id) {
            return true;
        }
        return $actor->can('erp_stock_ship')
            && in_array((int) ($row['warehouse_id'] ?? 0), $actor->warehouseIds, true)
            && in_array($row['status'] ?? null, ['CONFIRMED', 'PARTIALLY_SHIPPED'], true);
    }

    /** @param array<string, mixed> $row */
    public function purchaseOrderProjection(Actor $actor, array $row): array
    {
        if (!$this->canReadPurchaseOrder($actor, $row)) {
            return [];
        }

        $fields = $actor->can('erp_stock_receive')
            ? ['id', 'po_no', 'warehouse_id', 'status', 'version', 'created_at']
            : ['id', 'po_no', 'supplier_id', 'warehouse_id', 'created_by', 'approved_by', 'status', 'total_vnd', 'version', 'created_at'];
        return $this->pick($row, $fields);
    }

    /** @param array<string, mixed> $row */
    public function canReadPurchaseOrder(Actor $actor, array $row): bool
    {
        if (($id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT)) === false || $id < 1) {
            return false;
        }
        if ($actor->can('erp_stock_receive')) {
            return in_array((int) ($row['warehouse_id'] ?? 0), $actor->warehouseIds, true)
                && in_array($row['status'] ?? null, ['APPROVED', 'PARTIALLY_RECEIVED'], true);
        }
        return $actor->can('erp_purchases_view');
    }

    /** Cash, payment and reconciliation detail is always scoped to explicit account assignments. */
    public function canReadCashAccount(Actor $actor, mixed $cashAccountId): bool
    {
        $id = filter_var($cashAccountId, FILTER_VALIDATE_INT);
        return $actor->can('erp_cash_accounts_view') && $id !== false && $id > 0
            && in_array($id, $actor->cashAccountIds, true);
    }

    /** @param array{cash_account_id?: mixed} $row */
    public function canReadCashDetail(Actor $actor, array $row, string $capability): bool
    {
        return in_array($capability, ['erp_cash_book_view', 'erp_reconciliations_view'], true)
            && $actor->can($capability)
            && $this->canReadCashAccount($actor, $row['cash_account_id'] ?? null);
    }

    /** @param array{cash_account_id?: mixed} $payment @param array{source_type?: mixed, source_creator_id?: mixed, source_warehouse_id?: mixed} $trustedInvoiceSource */
    public function canReadPayment(Actor $actor, array $payment, array $trustedInvoiceSource): bool
    {
        return $actor->can('erp_payments_view')
            && $this->canReadInvoice($actor, $trustedInvoiceSource)
            && $this->canReadCashAccount($actor, $payment['cash_account_id'] ?? null);
    }

    /** @param array<string, mixed> $row @param list<string> $fields @return array<string, mixed> */
    private function pick(array $row, array $fields): array
    {
        $projection = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $projection[$field] = $row[$field];
            }
        }
        return $projection;
    }
}
