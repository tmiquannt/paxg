<?php
declare(strict_types=1);

use MiniERP\Security\Actor;
use MiniERP\Security\ReadPolicy;
use MiniERP\Security\RolesInstaller;
use PHPUnit\Framework\TestCase;

/** Pure policy tests; these do not claim SQL or route-level authorization coverage. */
final class ReadPolicyTest extends TestCase
{
    private ReadPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReadPolicy();
    }

    public function testAllSourceInvoiceReadUsesPersistedShipmentAndGoodsReceiptTypes(): void
    {
        $manager = $this->actor('erp_manager');

        self::assertTrue($this->policy->canReadInvoice($manager, ['source_type' => 'SHIPMENT']));
        self::assertTrue($this->policy->canReadInvoice($manager, ['source_type' => 'GOODS_RECEIPT']));
        self::assertFalse($this->policy->canReadInvoice($manager, ['source_type' => 'SALES_ORDER']));
        self::assertFalse($this->policy->canReadInvoice($manager, []));
    }

    public function testSalesInvoiceReadIsOwnShipmentOnly(): void
    {
        $sales = $this->actor('erp_sales', 42);

        self::assertTrue($this->policy->canReadInvoice($sales, ['source_type' => 'SHIPMENT', 'source_creator_id' => 42]));
        self::assertFalse($this->policy->canReadInvoice($sales, ['source_type' => 'SHIPMENT', 'source_creator_id' => 43]));
        self::assertFalse($this->policy->canReadInvoice($sales, ['source_type' => 'GOODS_RECEIPT', 'source_creator_id' => 42]));
        self::assertFalse($this->policy->canReadInvoice($sales, ['source_type' => 'SHIPMENT']));
    }

    public function testCashAccountReadRequiresCapabilityAndExplicitAssignment(): void
    {
        $manager = $this->actor('erp_manager', 7, [8]);
        $unassigned = $this->actor('erp_manager', 7);

        self::assertTrue($this->policy->canReadCashAccount($manager, 8));
        self::assertFalse($this->policy->canReadCashAccount($manager, 9));
        self::assertFalse($this->policy->canReadCashAccount($unassigned, 8));
        self::assertFalse($this->policy->canReadCashAccount($manager, 0));
    }

    public function testPaymentRequiresReadableInvoiceAndAssignedCashAccount(): void
    {
        $manager = $this->actor('erp_manager', 7, [8]);
        $source = ['source_type' => 'SHIPMENT'];

        self::assertTrue($this->policy->canReadPayment($manager, ['cash_account_id' => 8], $source));
        self::assertFalse($this->policy->canReadPayment($manager, ['cash_account_id' => 9], $source));
        self::assertFalse($this->policy->canReadPayment($manager, ['cash_account_id' => 8], ['source_type' => 'INVALID']));
    }

    public function testCashMovementRequiresDetailCapabilityAndAssignedAccount(): void
    {
        $manager = $this->actor('erp_manager', 7, [8]);
        $warehouse = $this->actor('erp_warehouse', 7, [8]);

        self::assertTrue($this->policy->canReadCashDetail($manager, ['cash_account_id' => 8], 'erp_cash_book_view'));
        self::assertFalse($this->policy->canReadCashDetail($manager, ['cash_account_id' => 9], 'erp_cash_book_view'));
        self::assertFalse($this->policy->canReadCashDetail($warehouse, ['cash_account_id' => 8], 'erp_cash_book_view'));
        self::assertFalse($this->policy->canReadCashDetail($manager, ['cash_account_id' => 8], 'erp_users_view'));
    }

    public function testWarehouseOrderScopesMatchActionableStatesAndAssignedWarehouses(): void
    {
        $warehouse = $this->actor('erp_warehouse', 12, [], [5]);

        self::assertSame([
            'kind' => 'warehouse_ids',
            'warehouse_ids' => [5],
            'statuses' => ['CONFIRMED', 'PARTIALLY_SHIPPED'],
        ], $this->policy->orderScope($warehouse, 'sales_orders'));
        self::assertSame([
            'kind' => 'warehouse_ids',
            'warehouse_ids' => [5],
            'statuses' => ['APPROVED', 'PARTIALLY_RECEIVED'],
        ], $this->policy->orderScope($warehouse, 'purchase_orders'));
        self::assertSame(['kind' => 'none'], $this->policy->orderScope($this->actor('erp_warehouse'), 'purchase_orders'));
        self::assertSame(['kind' => 'all'], $this->policy->orderScope($this->actor('erp_manager'), 'purchase_orders'));

        foreach (['CONFIRMED', 'PARTIALLY_SHIPPED'] as $status) {
            self::assertTrue($this->policy->canReadSalesOrder($warehouse, ['id' => 1, 'warehouse_id' => 5, 'status' => $status]));
        }
        self::assertFalse($this->policy->canReadSalesOrder($warehouse, ['id' => 1, 'warehouse_id' => 5, 'status' => 'SHIPPED']));
        self::assertFalse($this->policy->canReadSalesOrder($warehouse, ['id' => 1, 'warehouse_id' => 6, 'status' => 'CONFIRMED']));

        foreach (['APPROVED', 'PARTIALLY_RECEIVED'] as $status) {
            self::assertTrue($this->policy->canReadPurchaseOrder($warehouse, ['id' => 1, 'warehouse_id' => 5, 'status' => $status]));
        }
        self::assertFalse($this->policy->canReadPurchaseOrder($warehouse, ['id' => 1, 'warehouse_id' => 5, 'status' => 'DRAFT']));
        self::assertFalse($this->policy->canReadPurchaseOrder($warehouse, ['id' => 1, 'warehouse_id' => 5, 'status' => 'RECEIVED']));
        self::assertFalse($this->policy->canReadPurchaseOrder($warehouse, ['id' => 1, 'warehouse_id' => 6, 'status' => 'APPROVED']));
    }

    /** @param list<int> $cashAccountIds */
    private function actor(string $role, int $id = 1, array $cashAccountIds = [], array $warehouseIds = []): Actor
    {
        $capabilities = RolesInstaller::definitions()[$role]['capabilities'];
        return new Actor($id, $role, $capabilities, $warehouseIds, $cashAccountIds);
    }
}
