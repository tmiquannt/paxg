<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Domain\PricingCalculator;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/** Issues one immutable internal invoice from one posted shipment or goods receipt. */
final class InvoiceService
{
    public function __construct(private readonly wpdb $db, private readonly TransactionManager $transactions, private readonly AuditLogger $audit) {}

    public function issueFromShipment(Actor $actor, int $shipmentId, string $dueDate): int
    {
        return $this->issue($actor, 'SHIPMENT', $shipmentId, $dueDate);
    }

    public function issueFromGoodsReceipt(Actor $actor, int $receiptId, string $dueDate): int
    {
        return $this->issue($actor, 'GOODS_RECEIPT', $receiptId, $dueDate);
    }

    public function void(Actor $actor,int $invoiceId,string $reason):void
    {
        if(!$actor->can('erp_invoices_void')||$invoiceId<1||trim($reason)===''||mb_strlen($reason)>1000)throw new DomainException('Yêu cầu hủy hóa đơn không hợp lệ.');$invoices=$this->table('erp_invoices');$payments=$this->table('erp_payments');
        $this->transactions->run(function(TransactionManager $tx)use($actor,$invoiceId,$reason,$invoices,$payments):void{$rows=$tx->fetchAll("SELECT status FROM {$invoices} WHERE id=%d FOR UPDATE",[$invoiceId]);if(($rows[0]['status']??null)!=='ISSUED')throw new DomainException('Hóa đơn không còn ở trạng thái được hủy.');$paymentRows=$tx->fetchAll("SELECT id FROM {$payments} WHERE invoice_id=%d AND status IN ('POSTED','VOID_PENDING','VOID_APPROVED') LIMIT 1",[$invoiceId]);if($paymentRows!==[])throw new DomainException('Hóa đơn có khoản thanh toán đang hiệu lực và không thể hủy.');$now=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');$tx->execute("UPDATE {$invoices} SET status='VOID',void_reason=%s,voided_by=%d,voided_at=%s WHERE id=%d AND status='ISSUED'",[trim($reason),$actor->id,$now,$invoiceId]);$this->audit->record($tx,$this->uuid(),$actor->id,'invoice.voided','invoice',$invoiceId,'success',['status'=>'ISSUED'],['status'=>'VOID']);});
    }

    private function issue(Actor $actor, string $sourceType, int $sourceId, string $dueDate): int
    {
        if (!$actor->can('erp_invoices_issue') || !$actor->can('erp_invoice_sources_view_all') || $sourceId < 1) throw new DomainException('Bạn không có quyền phát hành chứng từ này.');
        $dueAt = $this->dueAt($dueDate);
        $tables = array_map(fn(string $suffix): string => $this->table($suffix), [
            'erp_invoices','erp_invoice_items','erp_sales_orders','erp_sales_order_items','erp_shipments','erp_shipment_items',
            'erp_purchase_orders','erp_purchase_order_items','erp_goods_receipts','erp_goods_receipt_items','erp_customers','erp_suppliers',
        ]);
        return $this->transactions->run(function(TransactionManager $tx) use ($actor,$sourceType,$sourceId,$dueAt,$tables): int {
            [$invoices,$invoiceItems,$salesOrders,$salesItems,$shipments,$shipmentItems,$purchaseOrders,$purchaseItems,$receipts,$receiptItems,$customers,$suppliers] = $tables;
            if ($sourceType === 'SHIPMENT') {
                $refs=$tx->fetchAll("SELECT sales_order_id FROM {$shipments} WHERE id=%d",[$sourceId]);
                if ($refs===[]) throw new DomainException('Không tìm thấy chứng từ giao hàng.');
                $orderId=(int)$refs[0]['sales_order_id'];
                $orders=$tx->fetchAll("SELECT id,customer_id,status,discount_bps,tax_bps FROM {$salesOrders} WHERE id=%d FOR UPDATE",[$orderId]);
                $sources=$tx->fetchAll("SELECT id,shipped_at FROM {$shipments} WHERE id=%d AND sales_order_id=%d FOR UPDATE",[$sourceId,$orderId]);
                if ($orders===[] || $sources===[] || !in_array($orders[0]['status'],['PARTIALLY_SHIPPED','SHIPPED','CLOSED'],true)) throw new DomainException('Nguồn giao hàng chưa ở trạng thái được lập hóa đơn.');
                $order=$orders[0]; $source=$sources[0];
                if ($tx->fetchAll("SELECT id FROM {$invoices} WHERE source_type='SHIPMENT' AND source_id=%d",[$sourceId])!==[]) throw new DomainException('Chứng từ giao hàng này đã được lập hóa đơn.');
                $partyRows=$tx->fetchAll("SELECT name,address,tax_id FROM {$customers} WHERE id=%d",[(int)$order['customer_id']]);
                $sourcesLines=$tx->fetchAll("SELECT si.id AS source_item_id,si.quantity,oi.sku_snapshot,oi.name_snapshot,oi.unit_snapshot,oi.unit_step_snapshot,oi.unit_price_vnd FROM {$shipmentItems} si INNER JOIN {$salesItems} oi ON oi.id=si.sales_item_id WHERE si.shipment_id=%d ORDER BY si.id",[$sourceId]);
                $direction='IN'; $partyId=(int)$order['customer_id']; $discountBps=(string)$order['discount_bps']; $taxBps=(string)$order['tax_bps'];
                $seller=$this->company(); $buyer=$partyRows[0]??null;
            } else {
                $refs=$tx->fetchAll("SELECT purchase_order_id FROM {$receipts} WHERE id=%d",[$sourceId]);
                if ($refs===[]) throw new DomainException('Không tìm thấy phiếu nhập hàng.');
                $orderId=(int)$refs[0]['purchase_order_id'];
                $orders=$tx->fetchAll("SELECT id,supplier_id,status FROM {$purchaseOrders} WHERE id=%d FOR UPDATE",[$orderId]);
                $sources=$tx->fetchAll("SELECT id,received_at FROM {$receipts} WHERE id=%d AND purchase_order_id=%d FOR UPDATE",[$sourceId,$orderId]);
                if ($orders===[] || $sources===[] || !in_array($orders[0]['status'],['PARTIALLY_RECEIVED','RECEIVED','CLOSED'],true)) throw new DomainException('Nguồn nhập hàng chưa ở trạng thái được lập hóa đơn.');
                $order=$orders[0]; $source=$sources[0];
                if ($tx->fetchAll("SELECT id FROM {$invoices} WHERE source_type='GOODS_RECEIPT' AND source_id=%d",[$sourceId])!==[]) throw new DomainException('Phiếu nhập hàng này đã được lập hóa đơn.');
                $partyRows=$tx->fetchAll("SELECT name,address,tax_id FROM {$suppliers} WHERE id=%d",[(int)$order['supplier_id']]);
                $sourcesLines=$tx->fetchAll("SELECT ri.id AS source_item_id,ri.quantity,pi.sku_snapshot,pi.name_snapshot,pi.unit_snapshot,pi.unit_step_snapshot,pi.unit_cost_vnd AS unit_price_vnd FROM {$receiptItems} ri INNER JOIN {$purchaseItems} pi ON pi.id=ri.purchase_item_id WHERE ri.goods_receipt_id=%d ORDER BY ri.id",[$sourceId]);
                $direction='OUT'; $partyId=(int)$order['supplier_id']; $discountBps='0'; $taxBps='0';
                $seller=$partyRows[0]??null; $buyer=$this->company();
            }
            if (!is_array($seller) || !is_array($buyer) || $sourcesLines===[]) throw new DomainException('Thiếu thông tin bên mua, bên bán hoặc dòng hàng để lập chứng từ.');
            $pricingLines=[];
            foreach ($sourcesLines as $line) $pricingLines[]=['line_id'=>(string)$line['source_item_id'],'unit_price_vnd'=>(string)$line['unit_price_vnd'],'quantity'=>(string)$line['quantity'],'unit_step'=>(string)$line['unit_step_snapshot']];
            $totals=PricingCalculator::calculate($pricingLines,$discountBps,$taxBps);
            if ((int)$totals['total_vnd']<1) throw new DomainException('Không thể phát hành chứng từ có tổng tiền bằng 0.');
            $issuedAt=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            if ($dueAt<$issuedAt) throw new DomainException('Ngày đến hạn không được trước thời điểm phát hành.');
            $temporary='TMP-'.strtoupper(str_replace('-','',$this->uuid()));
            $tx->execute("INSERT INTO {$invoices} (invoice_no,source_type,source_id,direction,party_id,seller_name,seller_address,seller_tax_id,buyer_name,buyer_address,buyer_tax_id,subtotal_exact_vnd,rounding_adjustment_vnd,subtotal_vnd,discount_vnd,tax_vnd,total_vnd,pricing_algorithm_version,discount_bps,tax_bps,status,issued_at,due_at,issued_by,template_version) VALUES (%s,%s,%d,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'ISSUED',%s,%s,%d,1)",[$temporary,$sourceType,$sourceId,$direction,$partyId,(string)$seller['name'],(string)($seller['address']??''),(string)($seller['tax_id']??''),(string)$buyer['name'],(string)($buyer['address']??''),(string)($buyer['tax_id']??''),$totals['subtotal_exact_vnd'],$totals['rounding_adjustment_vnd'],$totals['subtotal_vnd'],$totals['discount_vnd'],$totals['tax_vnd'],$totals['total_vnd'],$totals['algorithm_version'],$discountBps,$taxBps,$issuedAt,$dueAt,$actor->id]);
            $invoiceId=$tx->insertId(); $invoiceNo='INV-'.gmdate('Y').'-'.str_pad((string)$invoiceId,8,'0',STR_PAD_LEFT);
            $tx->execute("UPDATE {$invoices} SET invoice_no=%s WHERE id=%d",[$invoiceNo,$invoiceId]);
            $linesById=[]; foreach ($sourcesLines as $line) $linesById[(int)$line['source_item_id']]=$line;
            foreach ($totals['lines'] as $line) {
                $snapshot=$linesById[(int)$line['line_id']];
                $tx->execute("INSERT INTO {$invoiceItems} (invoice_id,source_item_id,sku_snapshot,name_snapshot,unit_snapshot,quantity,unit_price_vnd,line_subtotal_exact_vnd,rounding_allocation_vnd,line_subtotal_vnd,discount_vnd,tax_vnd,line_total_vnd) VALUES (%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)",[$invoiceId,(int)$line['line_id'],(string)$snapshot['sku_snapshot'],(string)$snapshot['name_snapshot'],(string)$snapshot['unit_snapshot'],$line['quantity'],$line['unit_price_vnd'],$line['line_subtotal_exact_vnd'],$line['rounding_allocation_vnd'],$line['line_subtotal_vnd'],$line['discount_vnd'],$line['tax_vnd'],$line['line_total_vnd']]);
            }
            $entity=$sourceType==='SHIPMENT'?'shipment':'goods_receipt';
            $this->audit->record($tx,$this->uuid(),$actor->id,'invoice.issued','invoice',$invoiceId,'success',[],['status'=>'ISSUED','amount_vnd'=>$totals['total_vnd']]);
            return $invoiceId;
        });
    }

    private function company(): array
    {
        $name=function_exists('get_option')?(string)get_option('erp_company_name',get_option('blogname','')):'';
        $address=function_exists('get_option')?(string)get_option('erp_company_address',''):'';
        $taxId=function_exists('get_option')?(string)get_option('erp_company_tax_id',''):'';
        $name=trim($name);
        return $name===''?[]:['name'=>mb_substr($name,0,191),'address'=>mb_substr($address,0,1000),'tax_id'=>mb_substr($taxId,0,64)];
    }

    private function dueAt(string $date): string
    {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$date)) throw new DomainException('Ngày đến hạn không hợp lệ.');
        $local=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('Asia/Ho_Chi_Minh'));
        $errors=DateTimeImmutable::getLastErrors();
        if (!$local || ($errors!==false && ($errors['warning_count']>0||$errors['error_count']>0)) || $local->format('Y-m-d')!==$date) throw new DomainException('Ngày đến hạn không hợp lệ.');
        return $local->modify('+1 day')->modify('-1 microsecond')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function table(string $suffix): string
    {
        if (!preg_match('/\Aerp_[a-z_]+\z/D',$suffix)||!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$this->db->prefix)) throw new DomainException('Cấu hình bảng dữ liệu không hợp lệ.');
        return '`'.$this->db->prefix.$suffix.'`';
    }

    private function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) return wp_generate_uuid4();
        $bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));
    }
}
