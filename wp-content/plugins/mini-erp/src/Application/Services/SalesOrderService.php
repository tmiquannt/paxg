<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Domain\BusinessConflictException;
use MiniERP\Domain\ExactAmounts;
use MiniERP\Domain\PricingCalculator;
use MiniERP\Domain\RecordNotFoundException;
use MiniERP\Domain\SalesOrderStateMachine;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/** Persisted sales-order lifecycle and atomic partial shipment/stock posting. */
final class SalesOrderService
{
    public function __construct(private readonly wpdb $db, private readonly TransactionManager $transactions, private readonly AuditLogger $audit) {}

    /** @param list<array{product_id:int,quantity:string}> $items */
    public function createDraft(Actor $actor, int $customerId, int $warehouseId, array $items, string $note = '', string $discountBps = '0', string $taxBps = '0'): int
    {
        if (!$actor->can('erp_sales_create') || $customerId < 1 || $warehouseId < 1 || mb_strlen($note) > 1000 || !array_is_list($items) || $items === []) {
            throw new DomainException('Thông tin đơn bán không hợp lệ hoặc bạn không có quyền tạo đơn.');
        }
        $orders = $this->table('erp_sales_orders');
        $orderItems = $this->table('erp_sales_order_items');
        $customers = $this->table('erp_customers');
        $warehouses = $this->table('erp_warehouses');
        $products = $this->table('erp_products');

        return $this->transactions->run(function (TransactionManager $tx) use ($actor, $customerId, $warehouseId, $items, $note, $discountBps, $taxBps, $orders, $orderItems, $customers, $warehouses, $products): int {
            if ($tx->fetchAll("SELECT id FROM {$customers} WHERE id = %d AND is_active = 1 FOR UPDATE", [$customerId]) === []) throw new DomainException('Khách hàng không còn hoạt động.');
            if ($tx->fetchAll("SELECT id FROM {$warehouses} WHERE id = %d AND is_active = 1 FOR UPDATE", [$warehouseId]) === []) throw new DomainException('Kho không còn hoạt động.');
            $requested = $this->normalizeItems($items);
            $productIds = array_column($requested, 'product_id');
            $slots = implode(',', array_fill(0, count($productIds), '%d'));
            $productRows = $tx->fetchAll("SELECT id,sku,name,unit,unit_step,sale_price_vnd FROM {$products} WHERE is_active = 1 AND id IN ({$slots}) ORDER BY id FOR UPDATE", $productIds);
            if (count($productRows) !== count($productIds)) throw new DomainException('Một sản phẩm không còn hoạt động. Tải lại đơn.');
            $productsById = [];
            foreach ($productRows as $row) $productsById[(int) $row['id']] = $row;

            $pricingLines = [];
            foreach ($requested as $item) {
                $product = $productsById[$item['product_id']] ?? null;
                if (!is_array($product)) throw new DomainException('Sản phẩm đã thay đổi.');
                $pricingLines[] = [
                    'line_id' => (string) $item['product_id'],
                    'unit_price_vnd' => (string) $product['sale_price_vnd'],
                    'quantity' => $item['quantity'],
                    'unit_step' => (string) $product['unit_step'],
                ];
            }
            $totals = PricingCalculator::calculate($pricingLines, $discountBps, $taxBps);
            $now = $this->now();
            $temporaryNumber = 'TMP-' . strtoupper(str_replace('-', '', $this->uuid()));
            $tx->execute("INSERT INTO {$orders} (order_no,customer_id,warehouse_id,created_by,status,discount_bps,tax_bps,subtotal_exact_vnd,rounding_adjustment_vnd,subtotal_vnd,discount_vnd,tax_vnd,total_vnd,version,note,created_at,updated_at) VALUES (%s,%d,%d,%d,'DRAFT',%d,%s,%s,%s,%s,%s,%s,%s,1,%s,%s,%s)", [
                $temporaryNumber, $customerId, $warehouseId, $actor->id, (int) $discountBps, $taxBps,
                $totals['subtotal_exact_vnd'], $totals['rounding_adjustment_vnd'], $totals['subtotal_vnd'], $totals['discount_vnd'], $totals['tax_vnd'], $totals['total_vnd'], $note, $now, $now,
            ]);
            $orderId = $tx->insertId();
            $orderNo = 'SO-' . gmdate('Y') . '-' . str_pad((string) $orderId, 8, '0', STR_PAD_LEFT);
            $tx->execute("UPDATE {$orders} SET order_no = %s WHERE id = %d", [$orderNo, $orderId]);
            foreach ($totals['lines'] as $line) {
                $product = $productsById[(int) $line['line_id']];
                $tx->execute("INSERT INTO {$orderItems} (order_id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_step_snapshot,unit_price_vnd,quantity,line_subtotal_exact_vnd,rounding_allocation_vnd,line_subtotal_vnd,discount_vnd,tax_vnd,line_total_vnd) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", [
                    $orderId, (int) $line['line_id'], (string) $product['sku'], (string) $product['name'], (string) $product['unit'], (string) $product['unit_step'],
                    $line['unit_price_vnd'], $line['quantity'], $line['line_subtotal_exact_vnd'], $line['rounding_allocation_vnd'], $line['line_subtotal_vnd'], $line['discount_vnd'], $line['tax_vnd'], $line['line_total_vnd'],
                ]);
            }
            $this->audit->record($tx, $this->uuid(), $actor->id, 'sales.order.created', 'sales_order', $orderId, 'success', [], ['status' => 'DRAFT', 'amount_vnd' => $totals['total_vnd']]);
            return $orderId;
        });
    }

    /** Replace an unposted draft after an optimistic version check. */
    public function updateDraft(Actor $actor,int $orderId,int $expectedVersion,int $customerId,int $warehouseId,array $items,string $note='',string $discountBps='0',string $taxBps='0'): int
    {
        if(!$actor->can('erp_sales_create')||$orderId<1||$expectedVersion<1||$customerId<1||$warehouseId<1||mb_strlen($note)>1000||!array_is_list($items)||$items===[])throw new DomainException('Thông tin cập nhật đơn bán không hợp lệ.');
        $orders=$this->table('erp_sales_orders');$orderItems=$this->table('erp_sales_order_items');$customers=$this->table('erp_customers');$warehouses=$this->table('erp_warehouses');$products=$this->table('erp_products');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$orderId,$expectedVersion,$customerId,$warehouseId,$items,$note,$discountBps,$taxBps,$orders,$orderItems,$customers,$warehouses,$products):int{
            $current=$tx->fetchAll("SELECT created_by,status,version FROM {$orders} WHERE id=%d FOR UPDATE",[$orderId]);$order=$current[0]??null;
            if(!is_array($order)||((int)$order['created_by']!==$actor->id&&!$actor->can('erp_sales_view_all')))throw new RecordNotFoundException('Không tìm thấy đơn bán hoặc bạn không có quyền truy cập.');
            if($order['status']!=='DRAFT'||(int)$order['version']!==$expectedVersion)throw new BusinessConflictException('Đơn không còn là bản nháp hoặc đã được cập nhật nơi khác.');
            if($tx->fetchAll("SELECT id FROM {$customers} WHERE id=%d AND is_active=1 FOR UPDATE",[$customerId])===[]||$tx->fetchAll("SELECT id FROM {$warehouses} WHERE id=%d AND is_active=1 FOR UPDATE",[$warehouseId])===[])throw new DomainException('Khách hàng hoặc kho không còn hoạt động.');
            $requested=$this->normalizeItems($items);$ids=array_column($requested,'product_id');$slots=implode(',',array_fill(0,count($ids),'%d'));$productRows=$tx->fetchAll("SELECT id,sku,name,unit,unit_step,sale_price_vnd FROM {$products} WHERE is_active=1 AND id IN ({$slots}) ORDER BY id FOR UPDATE",$ids);
            if(count($productRows)!==count($ids))throw new DomainException('Một sản phẩm không còn hoạt động.');$byId=[];foreach($productRows as $product)$byId[(int)$product['id']]=$product;$pricing=[];
            foreach($requested as $item){$product=$byId[$item['product_id']];$pricing[]=['line_id'=>(string)$item['product_id'],'unit_price_vnd'=>(string)$product['sale_price_vnd'],'quantity'=>$item['quantity'],'unit_step'=>(string)$product['unit_step']];}
            $totals=PricingCalculator::calculate($pricing,$discountBps,$taxBps);$tx->execute("DELETE FROM {$orderItems} WHERE order_id=%d",[$orderId]);
            foreach($totals['lines'] as $line){$product=$byId[(int)$line['line_id']];$tx->execute("INSERT INTO {$orderItems} (order_id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_step_snapshot,unit_price_vnd,quantity,line_subtotal_exact_vnd,rounding_allocation_vnd,line_subtotal_vnd,discount_vnd,tax_vnd,line_total_vnd) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",[$orderId,(int)$line['line_id'],(string)$product['sku'],(string)$product['name'],(string)$product['unit'],(string)$product['unit_step'],$line['unit_price_vnd'],$line['quantity'],$line['line_subtotal_exact_vnd'],$line['rounding_allocation_vnd'],$line['line_subtotal_vnd'],$line['discount_vnd'],$line['tax_vnd'],$line['line_total_vnd']]);}
            $tx->execute("UPDATE {$orders} SET customer_id=%d,warehouse_id=%d,discount_bps=%d,tax_bps=%s,subtotal_exact_vnd=%s,rounding_adjustment_vnd=%s,subtotal_vnd=%s,discount_vnd=%s,tax_vnd=%s,total_vnd=%s,note=%s,version=version+1,updated_at=GREATEST(UTC_TIMESTAMP(6),TIMESTAMPADD(MICROSECOND,1,updated_at)) WHERE id=%d AND version=%d",[$customerId,$warehouseId,(int)$discountBps,$taxBps,$totals['subtotal_exact_vnd'],$totals['rounding_adjustment_vnd'],$totals['subtotal_vnd'],$totals['discount_vnd'],$totals['tax_vnd'],$totals['total_vnd'],$note,$orderId,$expectedVersion]);
            $this->audit->record($tx,$this->uuid(),$actor->id,'sales.order.updated','sales_order',$orderId,'success',['status'=>'DRAFT'],['status'=>'DRAFT','amount_vnd'=>$totals['total_vnd']]);return $orderId;
        });
    }

    public function transition(Actor $actor, int $orderId, string $action, string $reason = ''): string
    {
        $this->requireVisibleOrder($actor);
        if ($orderId < 1 || !in_array($action, ['submit', 'approve', 'reject', 'cancel'], true) || mb_strlen($reason) > 1000) throw new DomainException('Yêu cầu cập nhật đơn không hợp lệ.');
        if (in_array($action, ['cancel'], true) && trim($reason) === '') throw new DomainException('Cần ghi lý do đóng đơn.');
        $orders = $this->table('erp_sales_orders');
        return $this->transactions->run(function (TransactionManager $tx) use ($actor, $orderId, $action, $reason, $orders): string {
            $rows = $tx->fetchAll("SELECT id,created_by,warehouse_id,status,discount_bps,version FROM {$orders} WHERE id = %d FOR UPDATE", [$orderId]);
            $order = $rows[0] ?? null;
            if (!is_array($order) || ((int) $order['created_by'] !== $actor->id && !$actor->can('erp_sales_view_all'))) throw new RecordNotFoundException('Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
            $oldStatus = (string) $order['status'];
            $threshold = $this->discountApprovalThreshold();
            try {
                $newStatus = (new SalesOrderStateMachine())->transition($actor, $oldStatus, $action, (int) $order['created_by'], (int) $order['warehouse_id'], (string) $order['discount_bps'], $threshold);
            } catch (DomainException $failure) {
                throw new BusinessConflictException($failure->getMessage(), 0, $failure);
            }
            $approvedBy = $action === 'approve' ? $actor->id : null;
            $closureReason = $action === 'cancel' ? $reason : '';
            $tx->execute("UPDATE {$orders} SET status = %s, approved_by = CASE WHEN %d > 0 THEN %d ELSE approved_by END, closure_reason = CASE WHEN %s <> '' THEN %s ELSE closure_reason END, version = version + 1, updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND,1,updated_at)) WHERE id = %d AND version = %d", [$newStatus, $approvedBy ?? 0, $approvedBy ?? 0, $closureReason, $closureReason, $orderId, (int) $order['version']]);
            $this->audit->record($tx, $this->uuid(), $actor->id, 'sales.order.' . $action, 'sales_order', $orderId, 'success', ['status' => $oldStatus], ['status' => $newStatus]);
            return $newStatus;
        });
    }

    /** @param list<array{sales_item_id:int,quantity:string}> $requestedLines */
    public function ship(Actor $actor, int $orderId, string $requestKey, array $requestedLines): int
    {
        if (!$actor->can('erp_stock_ship') || $orderId < 1 || !$this->validUuid($requestKey) || !array_is_list($requestedLines) || $requestedLines === []) {
            throw new DomainException('Thông tin giao hàng không hợp lệ hoặc bạn không có quyền xuất kho.');
        }
        $orders = $this->table('erp_sales_orders'); $orderItems = $this->table('erp_sales_order_items'); $warehouses = $this->table('erp_warehouses');
        $shipments = $this->table('erp_shipments'); $shipmentItems = $this->table('erp_shipment_items');
        $balances = $this->table('erp_stock_balances'); $movements = $this->table('erp_stock_movements');
        return $this->transactions->run(function (TransactionManager $tx) use ($actor, $orderId, $requestKey, $requestedLines, $orders, $orderItems, $warehouses, $shipments, $shipmentItems, $balances, $movements): int {
            $orderRows = $tx->fetchAll("SELECT id,warehouse_id,status,total_vnd,version FROM {$orders} WHERE id = %d FOR UPDATE", [$orderId]);
            $order = $orderRows[0] ?? null;
            if (!is_array($order)) throw new RecordNotFoundException('Không tìm thấy đơn bán.');
            $warehouseId = (int) $order['warehouse_id'];
            if (!in_array($warehouseId, $actor->warehouseIds, true)) throw new RecordNotFoundException('Không tìm thấy đơn bán hoặc bạn không có quyền truy cập.');
            if ($tx->fetchAll("SELECT id FROM {$warehouses} WHERE id = %d AND is_active = 1 FOR UPDATE", [$warehouseId]) === []) throw new BusinessConflictException('Kho của đơn không còn hoạt động.');
            if (!in_array((string) $order['status'], [SalesOrderStateMachine::CONFIRMED, SalesOrderStateMachine::PARTIALLY_SHIPPED], true)) throw new BusinessConflictException('Đơn chưa sẵn sàng xuất kho.');

            $items = $tx->fetchAll("SELECT id,product_id,quantity,unit_step_snapshot FROM {$orderItems} WHERE order_id = %d ORDER BY product_id,id FOR UPDATE", [$orderId]);
            $shippedRows = $tx->fetchAll("SELECT si.sales_item_id,SUM(si.quantity) AS shipped FROM {$shipmentItems} si INNER JOIN {$shipments} sh ON sh.id = si.shipment_id WHERE sh.sales_order_id = %d GROUP BY si.sales_item_id", [$orderId]);
            $shipped = [];
            foreach ($shippedRows as $row) $shipped[(int) $row['sales_item_id']] = (string) $row['shipped'];
            $byId = [];
            foreach ($items as $item) $byId[(int) $item['id']] = $item;
            $requested = [];
            foreach ($requestedLines as $line) {
                if (!is_array($line) || !isset($line['sales_item_id'], $line['quantity']) || !is_int($line['sales_item_id']) || !is_string($line['quantity']) || isset($requested[$line['sales_item_id']])) throw new DomainException('Dòng giao hàng không hợp lệ hoặc bị trùng.');
                $requested[$line['sales_item_id']] = $line['quantity'];
            }
            $itemPlan = [];
            $allFulfilled = true;
            foreach ($items as $item) {
                $id = (int) $item['id'];
                $old = $shipped[$id] ?? '0.000';
                $remaining = bcsub((string) $item['quantity'], $old, 3);
                $quantity = $requested[$id] ?? '0';
                if ($quantity !== '0') {
                    ExactAmounts::quantity($quantity, (string) $item['unit_step_snapshot']);
                    if (bccomp($quantity, $remaining, 3) > 0) throw new BusinessConflictException('Số lượng giao vượt phần còn lại của đơn.');
                    $itemPlan[] = ['id' => $id, 'product_id' => (int) $item['product_id'], 'quantity' => $quantity];
                    unset($requested[$id]);
                }
                if (bccomp($remaining, $quantity, 3) > 0) $allFulfilled = false;
            }
            if ($requested !== [] || $itemPlan === []) throw new DomainException('Đơn giao không có dòng hợp lệ hoặc tham chiếu dòng ngoài đơn.');

            $now = $this->now();
            $temporaryNo = 'TMP-' . strtoupper(str_replace('-', '', $requestKey));
            $tx->execute("INSERT INTO {$shipments} (shipment_no,sales_order_id,warehouse_id,shipped_by,shipped_at,request_key) VALUES (%s,%d,%d,%d,%s,%s)", [$temporaryNo, $orderId, $warehouseId, $actor->id, $now, $requestKey]);
            $shipmentId = $tx->insertId();
            $shipmentNo = 'SH-' . gmdate('Y') . '-' . str_pad((string) $shipmentId, 8, '0', STR_PAD_LEFT);
            $tx->execute("UPDATE {$shipments} SET shipment_no = %s WHERE id = %d", [$shipmentNo, $shipmentId]);

            foreach ($itemPlan as $plan) {
                $productId = $plan['product_id'];
                $tx->execute("INSERT INTO {$balances} (warehouse_id,product_id,quantity,updated_at) VALUES (%d,%d,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE updated_at = updated_at", [$warehouseId, $productId]);
                $balanceRows = $tx->fetchAll("SELECT quantity FROM {$balances} WHERE warehouse_id = %d AND product_id = %d FOR UPDATE", [$warehouseId, $productId]);
                if ($balanceRows === []) throw new \RuntimeException('Không tìm thấy số dư kho.');
                $before = (string) $balanceRows[0]['quantity'];
                if (bccomp($before, $plan['quantity'], 3) < 0) throw new BusinessConflictException('Tồn kho thực tế không đủ để xuất số lượng này.');
                $after = bcsub($before, $plan['quantity'], 3);
                $tx->execute("UPDATE {$balances} SET quantity = %s, updated_at = UTC_TIMESTAMP(6) WHERE warehouse_id = %d AND product_id = %d", [$after, $warehouseId, $productId]);
                $tx->execute("INSERT INTO {$shipmentItems} (shipment_id,sales_item_id,product_id,quantity) VALUES (%d,%d,%d,%s)", [$shipmentId, $plan['id'], $productId, $plan['quantity']]);
                $tx->execute("INSERT INTO {$movements} (warehouse_id,product_id,movement_key,direction,qty,before_qty,after_qty,source_type,source_id,actor_id,occurred_at) VALUES (%d,%d,%s,'OUT',%s,%s,%s,'SHIPMENT',%d,%d,%s)", [$warehouseId, $productId, 'SHIPMENT:' . $shipmentId . ':' . $plan['id'], $plan['quantity'], $before, $after, $shipmentId, $actor->id, $now]);
            }
            $nextStatus = (new SalesOrderStateMachine())->transition($actor, (string) $order['status'], 'ship', $actor->id, $warehouseId, '0', '0', $allFulfilled);
            $tx->execute("UPDATE {$orders} SET status = %s, version = version + 1, updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND,1,updated_at)) WHERE id = %d AND version = %d", [$nextStatus, $orderId, (int) $order['version']]);
            $this->audit->record($tx, $this->uuid(), $actor->id, 'sales.shipment.posted', 'shipment', $shipmentId, 'success', [], ['status' => $nextStatus, 'amount_vnd' => (int) $order['total_vnd']]);
            return $shipmentId;
        });
    }

    private function normalizeItems(array $items): array
    {
        $byProduct = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['product_id'], $item['quantity']) || !is_int($item['product_id']) || $item['product_id'] < 1 || !is_string($item['quantity']) || isset($byProduct[$item['product_id']])) throw new DomainException('Dòng sản phẩm không hợp lệ hoặc bị trùng.');
            $byProduct[$item['product_id']] = $item['quantity'];
        }
        ksort($byProduct, SORT_NUMERIC);
        return array_map(static fn (int $productId, string $quantity): array => ['product_id' => $productId, 'quantity' => $quantity], array_keys($byProduct), array_values($byProduct));
    }

    private function discountApprovalThreshold(): string
    {
        $value = function_exists('get_option') ? get_option('mini_erp_discount_approval_bps', 0) : 0;
        return is_scalar($value) && preg_match('/\A[0-9]{1,5}\z/D', (string) $value) && (int) $value <= 10000 ? (string) $value : '0';
    }

    private function requireVisibleOrder(Actor $actor): void
    {
        if (!$actor->can('erp_sales_view_own') && !$actor->can('erp_sales_view_all')) throw new RecordNotFoundException('Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
    }

    private function table(string $suffix): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $this->db->prefix) || !in_array($suffix, ['erp_sales_orders','erp_sales_order_items','erp_stock_balances','erp_stock_movements','erp_shipments','erp_shipment_items','erp_customers','erp_warehouses','erp_products'], true)) throw new DomainException('Cấu hình bảng Mini ERP không hợp lệ.');
        return '`' . $this->db->prefix . $suffix . '`';
    }

    private function now(): string { return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) return wp_generate_uuid4();
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
    private function validUuid(string $value): bool { return (bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $value); }
}
