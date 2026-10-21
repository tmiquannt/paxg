<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Domain\BusinessConflictException;
use MiniERP\Domain\ExactAmounts;
use MiniERP\Domain\PricingCalculator;
use MiniERP\Domain\PurchaseOrderStateMachine;
use MiniERP\Domain\RecordNotFoundException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/** Purchase order lifecycle and atomic partial receipt/stock posting. */
final class PurchaseOrderService
{
    public function __construct(private readonly wpdb $db, private readonly TransactionManager $transactions, private readonly AuditLogger $audit) {}

    /** @param list<array{product_id:int,unit_cost_vnd:string,quantity:string}> $items */
    public function createDraft(Actor $actor, int $supplierId, int $warehouseId, array $items, string $note = ''): int
    {
        if (!$actor->can('erp_purchases_create') || $supplierId < 1 || $warehouseId < 1 || mb_strlen($note) > 1000 || !array_is_list($items) || $items === []) throw new DomainException('Thông tin đơn mua không hợp lệ hoặc bạn không có quyền tạo đơn.');
        $orders=$this->table('erp_purchase_orders'); $orderItems=$this->table('erp_purchase_order_items'); $suppliers=$this->table('erp_suppliers'); $warehouses=$this->table('erp_warehouses'); $products=$this->table('erp_products');
        return $this->transactions->run(function (TransactionManager $tx) use ($actor,$supplierId,$warehouseId,$items,$note,$orders,$orderItems,$suppliers,$warehouses,$products): int {
            if ($tx->fetchAll("SELECT id FROM {$suppliers} WHERE id=%d AND is_active=1 FOR UPDATE",[$supplierId])===[]) throw new DomainException('Nhà cung cấp không còn hoạt động.');
            if ($tx->fetchAll("SELECT id FROM {$warehouses} WHERE id=%d AND is_active=1 FOR UPDATE",[$warehouseId])===[]) throw new DomainException('Kho không còn hoạt động.');
            $requested=$this->normalizeItems($items); $ids=array_column($requested,'product_id'); $slots=implode(',',array_fill(0,count($ids),'%d'));
            $productRows=$tx->fetchAll("SELECT id,sku,name,unit,unit_step FROM {$products} WHERE is_active=1 AND id IN ({$slots}) ORDER BY id FOR UPDATE",$ids);
            if (count($productRows)!==count($ids)) throw new DomainException('Một sản phẩm không còn hoạt động.');
            $byId=[]; foreach ($productRows as $row) $byId[(int)$row['id']]=$row;
            $pricingLines=[];
            foreach ($requested as $item) {
                $product=$byId[$item['product_id']];
                $pricingLines[]=['line_id'=>(string)$item['product_id'],'unit_price_vnd'=>$item['unit_cost_vnd'],'quantity'=>$item['quantity'],'unit_step'=>(string)$product['unit_step']];
            }
            $totals=PricingCalculator::calculate($pricingLines);
            $now=$this->now(); $tmp='TMP-'.strtoupper(str_replace('-','',$this->uuid()));
            $tx->execute("INSERT INTO {$orders} (po_no,supplier_id,warehouse_id,created_by,status,subtotal_exact_vnd,rounding_adjustment_vnd,subtotal_vnd,total_vnd,version,note,created_at,updated_at) VALUES (%s,%d,%d,%d,'DRAFT',%s,%s,%s,%s,1,%s,%s,%s)",[$tmp,$supplierId,$warehouseId,$actor->id,$totals['subtotal_exact_vnd'],$totals['rounding_adjustment_vnd'],$totals['subtotal_vnd'],$totals['total_vnd'],$note,$now,$now]);
            $poId=$tx->insertId(); $poNo='PO-'.gmdate('Y').'-'.str_pad((string)$poId,8,'0',STR_PAD_LEFT);
            $tx->execute("UPDATE {$orders} SET po_no=%s WHERE id=%d",[$poNo,$poId]);
            foreach ($totals['lines'] as $line) {
                $product=$byId[(int)$line['line_id']];
                $tx->execute("INSERT INTO {$orderItems} (po_id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_step_snapshot,unit_cost_vnd,quantity,line_subtotal_exact_vnd,rounding_allocation_vnd,line_total_vnd) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s)",[$poId,(int)$line['line_id'],(string)$product['sku'],(string)$product['name'],(string)$product['unit'],(string)$product['unit_step'],$line['unit_price_vnd'],$line['quantity'],$line['line_subtotal_exact_vnd'],$line['rounding_allocation_vnd'],$line['line_total_vnd']]);
            }
            $this->audit->record($tx,$this->uuid(),$actor->id,'purchase.order.created','purchase_order',$poId,'success',[],['status'=>'DRAFT','amount_vnd'=>$totals['total_vnd']]);
            return $poId;
        });
    }

    /** Replace an unposted purchase draft after an optimistic version check. */
    public function updateDraft(Actor $actor,int $poId,int $expectedVersion,int $supplierId,int $warehouseId,array $items,string $note=''): int
    {
        if(!$actor->can('erp_purchases_create')||$poId<1||$expectedVersion<1||$supplierId<1||$warehouseId<1||mb_strlen($note)>1000||!array_is_list($items)||$items===[])throw new DomainException('Thông tin cập nhật đơn mua không hợp lệ.');
        $orders=$this->table('erp_purchase_orders');$orderItems=$this->table('erp_purchase_order_items');$suppliers=$this->table('erp_suppliers');$warehouses=$this->table('erp_warehouses');$products=$this->table('erp_products');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$poId,$expectedVersion,$supplierId,$warehouseId,$items,$note,$orders,$orderItems,$suppliers,$warehouses,$products):int{
            $rows=$tx->fetchAll("SELECT created_by,status,version FROM {$orders} WHERE id=%d FOR UPDATE",[$poId]);$po=$rows[0]??null;if(!is_array($po)||(int)$po['created_by']!==$actor->id)throw new RecordNotFoundException('Không tìm thấy đơn mua hoặc bạn không có quyền truy cập.');if($po['status']!=='DRAFT'||(int)$po['version']!==$expectedVersion)throw new BusinessConflictException('Đơn mua không còn là bản nháp hoặc đã được cập nhật nơi khác.');
            if($tx->fetchAll("SELECT id FROM {$suppliers} WHERE id=%d AND is_active=1 FOR UPDATE",[$supplierId])===[]||$tx->fetchAll("SELECT id FROM {$warehouses} WHERE id=%d AND is_active=1 FOR UPDATE",[$warehouseId])===[])throw new DomainException('Nhà cung cấp hoặc kho không còn hoạt động.');
            $requested=$this->normalizeItems($items);$ids=array_column($requested,'product_id');$slots=implode(',',array_fill(0,count($ids),'%d'));$rows=$tx->fetchAll("SELECT id,sku,name,unit,unit_step FROM {$products} WHERE is_active=1 AND id IN ({$slots}) ORDER BY id FOR UPDATE",$ids);if(count($rows)!==count($ids))throw new DomainException('Một sản phẩm không còn hoạt động.');$byId=[];foreach($rows as $row)$byId[(int)$row['id']]=$row;$pricing=[];foreach($requested as $item){$p=$byId[$item['product_id']];$pricing[]=['line_id'=>(string)$item['product_id'],'unit_price_vnd'=>$item['unit_cost_vnd'],'quantity'=>$item['quantity'],'unit_step'=>(string)$p['unit_step']];}$totals=PricingCalculator::calculate($pricing);
            $tx->execute("DELETE FROM {$orderItems} WHERE po_id=%d",[$poId]);foreach($totals['lines'] as $line){$p=$byId[(int)$line['line_id']];$tx->execute("INSERT INTO {$orderItems} (po_id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_step_snapshot,unit_cost_vnd,quantity,line_subtotal_exact_vnd,rounding_allocation_vnd,line_total_vnd) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s)",[$poId,(int)$line['line_id'],(string)$p['sku'],(string)$p['name'],(string)$p['unit'],(string)$p['unit_step'],$line['unit_price_vnd'],$line['quantity'],$line['line_subtotal_exact_vnd'],$line['rounding_allocation_vnd'],$line['line_total_vnd']]);}
            $tx->execute("UPDATE {$orders} SET supplier_id=%d,warehouse_id=%d,subtotal_exact_vnd=%s,rounding_adjustment_vnd=%s,subtotal_vnd=%s,total_vnd=%s,note=%s,version=version+1,updated_at=GREATEST(UTC_TIMESTAMP(6),TIMESTAMPADD(MICROSECOND,1,updated_at)) WHERE id=%d AND version=%d",[$supplierId,$warehouseId,$totals['subtotal_exact_vnd'],$totals['rounding_adjustment_vnd'],$totals['subtotal_vnd'],$totals['total_vnd'],$note,$poId,$expectedVersion]);
            $this->audit->record($tx,$this->uuid(),$actor->id,'purchase.order.updated','purchase_order',$poId,'success',['status'=>'DRAFT'],['status'=>'DRAFT','amount_vnd'=>$totals['total_vnd']]);return $poId;
        });
    }

    public function transition(Actor $actor,int $poId,string $action,string $reason=''): string
    {
        if (!$actor->can('erp_purchases_view') || $poId<1 || !in_array($action,['submit','approve','reject','revise','cancel'],true) || mb_strlen($reason)>1000) throw new DomainException('Yêu cầu cập nhật đơn mua không hợp lệ.');
        if ($action==='cancel' && trim($reason)==='') throw new DomainException('Cần ghi lý do đóng đơn mua.');
        $orders=$this->table('erp_purchase_orders');
        return $this->transactions->run(function(TransactionManager $tx) use($actor,$poId,$action,$reason,$orders): string {
            $rows=$tx->fetchAll("SELECT id,created_by,warehouse_id,status,version FROM {$orders} WHERE id=%d FOR UPDATE",[$poId]); $po=$rows[0]??null;
            if (!is_array($po) || ((int)$po['created_by']!==$actor->id && !$actor->can('erp_purchases_approve') && !$actor->can('erp_purchases_view'))) throw new RecordNotFoundException('Không tìm thấy đơn mua hoặc bạn không có quyền truy cập.');
            $old=(string)$po['status'];
            try { $next=(new PurchaseOrderStateMachine())->transition($actor,$old,$action,(int)$po['created_by'],(int)$po['warehouse_id']); }
            catch (DomainException $failure) { throw new BusinessConflictException($failure->getMessage(),0,$failure); }
            $approved=$action==='approve'?$actor->id:0; $closure=$action==='cancel'?$reason:'';
            $tx->execute("UPDATE {$orders} SET status=%s,approved_by=CASE WHEN %d>0 THEN %d ELSE approved_by END,closure_reason=CASE WHEN %s<>'' THEN %s ELSE closure_reason END,version=version+1,updated_at=GREATEST(UTC_TIMESTAMP(6),TIMESTAMPADD(MICROSECOND,1,updated_at)) WHERE id=%d AND version=%d",[$next,$approved,$approved,$closure,$closure,$poId,(int)$po['version']]);
            $this->audit->record($tx,$this->uuid(),$actor->id,'purchase.order.'.$action,'purchase_order',$poId,'success',['status'=>$old],['status'=>$next]);
            return $next;
        });
    }

    /** @param list<array{purchase_item_id:int,quantity:string}> $requestedLines */
    public function receive(Actor $actor,int $poId,string $requestKey,array $requestedLines): int
    {
        if (!$actor->can('erp_stock_receive') || $poId<1 || !$this->validUuid($requestKey) || !array_is_list($requestedLines) || $requestedLines===[]) throw new DomainException('Thông tin nhận hàng không hợp lệ hoặc bạn không có quyền nhập kho.');
        $orders=$this->table('erp_purchase_orders');$orderItems=$this->table('erp_purchase_order_items');$receipts=$this->table('erp_goods_receipts');$receiptItems=$this->table('erp_goods_receipt_items');$balances=$this->table('erp_stock_balances');$movements=$this->table('erp_stock_movements');$warehouses=$this->table('erp_warehouses');
        return $this->transactions->run(function(TransactionManager $tx) use($actor,$poId,$requestKey,$requestedLines,$orders,$orderItems,$receipts,$receiptItems,$balances,$movements,$warehouses): int {
            $rows=$tx->fetchAll("SELECT id,created_by,warehouse_id,status,total_vnd,version FROM {$orders} WHERE id=%d FOR UPDATE",[$poId]);$po=$rows[0]??null;
            if (!is_array($po)) throw new RecordNotFoundException('Không tìm thấy đơn mua.');$warehouseId=(int)$po['warehouse_id'];
            if (!in_array($warehouseId,$actor->warehouseIds,true)) throw new RecordNotFoundException('Không tìm thấy đơn mua hoặc bạn không có quyền truy cập.');
            if (!in_array((string)$po['status'],[PurchaseOrderStateMachine::APPROVED,PurchaseOrderStateMachine::PARTIALLY_RECEIVED],true)) throw new BusinessConflictException('Đơn mua chưa sẵn sàng nhận hàng.');
            if ($tx->fetchAll("SELECT id FROM {$warehouses} WHERE id=%d AND is_active=1 FOR UPDATE",[$warehouseId])===[]) throw new BusinessConflictException('Kho của đơn không còn hoạt động.');
            $items=$tx->fetchAll("SELECT id,product_id,quantity,unit_step_snapshot FROM {$orderItems} WHERE po_id=%d ORDER BY product_id,id FOR UPDATE",[$poId]);
            $receivedRows=$tx->fetchAll("SELECT ri.purchase_item_id,SUM(ri.quantity) AS received FROM {$receiptItems} ri JOIN {$receipts} gr ON gr.id=ri.goods_receipt_id WHERE gr.purchase_order_id=%d GROUP BY ri.purchase_item_id",[$poId]);$received=[];foreach($receivedRows as $r)$received[(int)$r['purchase_item_id']]=(string)$r['received'];
            $requested=[];foreach($requestedLines as $line){if(!is_array($line)||!isset($line['purchase_item_id'],$line['quantity'])||!is_int($line['purchase_item_id'])||!is_string($line['quantity'])||isset($requested[$line['purchase_item_id']]))throw new DomainException('Dòng nhận hàng không hợp lệ hoặc bị trùng.');$requested[$line['purchase_item_id']]=$line['quantity'];}
            $byId=[];foreach($items as $item)$byId[(int)$item['id']]=$item;$plan=[];$all=true;
            foreach($items as $item){$id=(int)$item['id'];$old=$received[$id]??'0.000';$remaining=bcsub((string)$item['quantity'],$old,3);$qty=$requested[$id]??'0';if($qty!=='0'){ExactAmounts::quantity($qty,(string)$item['unit_step_snapshot']);if(bccomp($qty,$remaining,3)>0)throw new BusinessConflictException('Số lượng nhận vượt phần còn lại của đơn mua.');$plan[]=['id'=>$id,'product_id'=>(int)$item['product_id'],'quantity'=>$qty];unset($requested[$id]);}if(bccomp($remaining,$qty,3)>0)$all=false;}
            if($requested!==[]||$plan===[])throw new DomainException('Phiếu nhận không có dòng hợp lệ hoặc tham chiếu dòng ngoài đơn.');
            $now=$this->now();$tmp='TMP-'.strtoupper(str_replace('-','',$requestKey));
            $tx->execute("INSERT INTO {$receipts} (receipt_no,purchase_order_id,warehouse_id,received_by,received_at,request_key) VALUES (%s,%d,%d,%d,%s,%s)",[$tmp,$poId,$warehouseId,$actor->id,$now,$requestKey]);$receiptId=$tx->insertId();$receiptNo='GR-'.gmdate('Y').'-'.str_pad((string)$receiptId,8,'0',STR_PAD_LEFT);$tx->execute("UPDATE {$receipts} SET receipt_no=%s WHERE id=%d",[$receiptNo,$receiptId]);
            foreach($plan as $line){$productId=$line['product_id'];$tx->execute("INSERT INTO {$balances} (warehouse_id,product_id,quantity,updated_at) VALUES (%d,%d,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE updated_at=updated_at",[$warehouseId,$productId]);$b=$tx->fetchAll("SELECT quantity FROM {$balances} WHERE warehouse_id=%d AND product_id=%d FOR UPDATE",[$warehouseId,$productId]);if($b===[])throw new \RuntimeException('Không tìm thấy số dư kho.');$before=(string)$b[0]['quantity'];$after=bcadd($before,$line['quantity'],3);$tx->execute("UPDATE {$balances} SET quantity=%s,updated_at=UTC_TIMESTAMP(6) WHERE warehouse_id=%d AND product_id=%d",[$after,$warehouseId,$productId]);$tx->execute("INSERT INTO {$receiptItems} (goods_receipt_id,purchase_item_id,product_id,quantity) VALUES (%d,%d,%d,%s)",[$receiptId,$line['id'],$productId,$line['quantity']]);$tx->execute("INSERT INTO {$movements} (warehouse_id,product_id,movement_key,direction,qty,before_qty,after_qty,source_type,source_id,actor_id,occurred_at) VALUES (%d,%d,%s,'IN',%s,%s,%s,'GOODS_RECEIPT',%d,%d,%s)",[$warehouseId,$productId,'RECEIPT:'.$receiptId.':'.$line['id'],$line['quantity'],$before,$after,$receiptId,$actor->id,$now]);}
            try { $next=(new PurchaseOrderStateMachine())->transition($actor,(string)$po['status'],'receive',(int)$po['created_by'],$warehouseId,$all); }
            catch (DomainException $failure) { throw new BusinessConflictException($failure->getMessage(),0,$failure); }
            $tx->execute("UPDATE {$orders} SET status=%s,version=version+1,updated_at=GREATEST(UTC_TIMESTAMP(6),TIMESTAMPADD(MICROSECOND,1,updated_at)) WHERE id=%d AND version=%d",[$next,$poId,(int)$po['version']]);
            $this->audit->record($tx,$this->uuid(),$actor->id,'purchase.receipt.posted','goods_receipt',$receiptId,'success',[],['status'=>$next,'amount_vnd'=>(string)$po['total_vnd']]);return $receiptId;
        });
    }

    private function normalizeItems(array $items): array
    {
        $byProduct=[];foreach($items as $item){if(!is_array($item)||!isset($item['product_id'],$item['quantity'],$item['unit_cost_vnd'])||!is_int($item['product_id'])||$item['product_id']<1||!is_string($item['quantity'])||!is_string($item['unit_cost_vnd'])||isset($byProduct[$item['product_id']]))throw new DomainException('Dòng sản phẩm mua không hợp lệ hoặc bị trùng.');ExactAmounts::money($item['unit_cost_vnd']);$byProduct[$item['product_id']]=$item;}ksort($byProduct,SORT_NUMERIC);return array_values($byProduct);
    }
    private function table(string $suffix): string { if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$this->db->prefix)||!in_array($suffix,['erp_purchase_orders','erp_purchase_order_items','erp_goods_receipts','erp_goods_receipt_items','erp_stock_balances','erp_stock_movements','erp_suppliers','erp_warehouses','erp_products'],true))throw new DomainException('Cấu hình bảng Mini ERP không hợp lệ.');return '`'.$this->db->prefix.$suffix.'`'; }
    private function now(): string { return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'); }
    private function uuid(): string { if(function_exists('wp_generate_uuid4'))return wp_generate_uuid4();$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4)); }
    private function validUuid(string $value): bool { return (bool)preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',$value); }
}
