<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use MiniERP\Security\Actor;
use MiniERP\Security\ReadPolicy;
use RuntimeException;
use wpdb;

/** One immutable invoice projection shared by the detail, HTML print, and protected PDF routes. */
final class InvoiceDocumentService
{
    public function __construct(private readonly wpdb $db) {}

    /** @return array{invoice:array<string,mixed>,lines:list<array<string,mixed>>} */
    public function viewModel(Actor $actor,int $invoiceId):array
    {
        $scope=(new ReadPolicy())->invoiceScope($actor);
        if($invoiceId<1||$scope['kind']==='none')throw new DomainException('Bạn không có quyền xem chứng từ.');
        $invoices=$this->table('erp_invoices');$items=$this->table('erp_invoice_items');$where='i.id=%d';$args=[$invoiceId];
        if($scope['kind']==='all'){
            $where.=" AND i.source_type IN ('SHIPMENT','GOODS_RECEIPT')";
        }else{
            $shipments=$this->table('erp_shipments');$orders=$this->table('erp_sales_orders');
            $where.=" AND i.source_type='SHIPMENT' AND EXISTS(SELECT 1 FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id WHERE sh.id=i.source_id AND so.created_by=%d)";$args[]=$scope['user_id'];
        }
        $rows=$this->rows($this->db->prepare("SELECT i.id,i.invoice_no,i.source_type,i.direction,i.subtotal_exact_vnd,i.rounding_adjustment_vnd,i.subtotal_vnd,i.discount_vnd,i.tax_vnd,i.total_vnd,i.pricing_algorithm_version,i.discount_bps,i.tax_bps,i.status,i.issued_at,i.due_at,i.void_reason,i.template_version,i.seller_name,i.seller_address,i.seller_tax_id,i.buyer_name,i.buyer_address,i.buyer_tax_id FROM {$invoices} i WHERE {$where}",$args));
        $invoice=$rows[0]??null;if(!is_array($invoice))throw new DomainException('Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.');
        $lines=$this->rows($this->db->prepare("SELECT sku_snapshot,name_snapshot,unit_snapshot,quantity,unit_price_vnd,line_subtotal_exact_vnd,rounding_allocation_vnd,line_subtotal_vnd,discount_vnd,tax_vnd,line_total_vnd FROM {$items} WHERE invoice_id=%d ORDER BY id",$invoiceId));
        if($lines===[])throw new DomainException('Chứng từ không có dòng hàng hợp lệ.');
        $this->assertSnapshot($invoice,$lines);
        return ['invoice'=>$invoice,'lines'=>$lines];
    }

    /** Render the source projection into a self-contained UTF-8 HTML document. */
    public function html(array $model,bool $withPrintButton=true):string
    {
        $invoice=$model['invoice']??null;$lines=$model['lines']??null;if(!is_array($invoice)||!is_array($lines))throw new DomainException('Dữ liệu chứng từ không hợp lệ.');
        $h=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $money=static fn(mixed $value):string=>number_format((int)$value,0,',','.');
        $decimal=static fn(mixed $value):string=>self::formatDecimal($value);
        $title=$h($invoice['invoice_no']??'');$html='<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$title.'</title><style>
@page{size:A4;margin:16mm 14mm}*{box-sizing:border-box}body{font:11pt "DejaVu Sans",sans-serif;color:#172033;margin:0}header{border-bottom:2px solid #243958;padding-bottom:12px;margin-bottom:16px}h1{font-size:17pt;line-height:1.35;margin:0 0 8px}h2{font-size:13pt;margin:20px 0 8px}.tag{font-size:9pt;font-weight:bold;color:#8a4b12}.meta,.party{line-height:1.55;margin:4px 0}.parties{width:100%;border-collapse:collapse;margin:14px 0}.parties td{width:50%;vertical-align:top;padding:8px 12px 8px 0}.parties strong{display:block;margin-bottom:4px}table.items{width:100%;border-collapse:collapse;font-size:9pt;margin-top:12px}table.items thead{display:table-header-group}table.items tr{page-break-inside:avoid}table.items th,table.items td{border-bottom:1px solid #d6dce5;padding:7px 5px;text-align:left;vertical-align:top}table.items th{background:#f0f3f7;font-size:8pt}td.num,th.num{text-align:right;white-space:nowrap}.totals{width:58%;margin:16px 0 0 auto;border-collapse:collapse}.totals td{padding:5px 4px;border-bottom:1px solid #edf0f4}.totals td:last-child{text-align:right;white-space:nowrap}.grand{font-size:14pt;font-weight:bold}.rounding{font-size:8pt;color:#596579}.void{color:#a32424;font-size:17pt;font-weight:bold}.foot{margin-top:20px;padding-top:8px;border-top:1px solid #d6dce5;font-size:8pt;color:#596579}.print{margin:16px 0}@media print{.print{display:none}}
</style></head><body><header><div class="tag">CHỨNG TỪ NỘI BỘ — KHÔNG PHẢI HÓA ĐƠN ĐIỆN TỬ</div><h1>'.$title.'</h1>'.(($invoice['status']??'')==='VOID'?'<div class="void">ĐÃ HỦY</div>':'').'<div class="meta">Ngày lập: '.$h($invoice['issued_at']??'').' · Hạn thanh toán: '.$h($invoice['due_at']??'').'</div><div class="meta">Phiên bản mẫu: '.$h($invoice['template_version']??'1').' · Thuật toán giá: '.$h($invoice['pricing_algorithm_version']??'LEGACY').'</div><div class="meta">Tỷ lệ chiết khấu: '.($invoice['discount_bps']===null?'Không lưu trong bản gốc':$h($invoice['discount_bps']).' bps').' · Tỷ lệ thuế: '.($invoice['tax_bps']===null?'Không lưu trong bản gốc':$h($invoice['tax_bps']).' bps').'</div></header><table class="parties"><tr><td><strong>Bên bán</strong>'.$h($invoice['seller_name']??'').'<br>'.$h($invoice['seller_address']??'').'<br>MST: '.$h($invoice['seller_tax_id']??'').'</td><td><strong>Bên mua</strong>'.$h($invoice['buyer_name']??'').'<br>'.$h($invoice['buyer_address']??'').'<br>MST: '.$h($invoice['buyer_tax_id']??'').'</td></tr></table><table class="items"><thead><tr><th>Mã / mặt hàng</th><th class="num">Số lượng</th><th class="num">Đơn giá</th><th class="num">Giá trị chính xác</th><th class="num">Phân bổ làm tròn</th><th class="num">Tạm tính được phân bổ</th><th class="num">Chiết khấu</th><th class="num">Thuế</th><th class="num">Thành tiền</th></tr></thead><tbody>';
        foreach($lines as $line)$html.='<tr><td>'.$h(($line['sku_snapshot']??'').' · '.($line['name_snapshot']??'')).'</td><td class="num">'.$h(($line['quantity']??'').' '.($line['unit_snapshot']??'')).'</td><td class="num">'.$money($line['unit_price_vnd']??0).'</td><td class="num">'.$decimal($line['line_subtotal_exact_vnd']??'0.000').'</td><td class="num">'.$decimal($line['rounding_allocation_vnd']??'0.000').'</td><td class="num">'.$money($line['line_subtotal_vnd']??0).'</td><td class="num">'.$money($line['discount_vnd']??0).'</td><td class="num">'.$money($line['tax_vnd']??0).'</td><td class="num">'.$money($line['line_total_vnd']??0).'</td></tr>';
        $html.='</tbody></table><table class="totals"><tr><td>Tạm tính chính xác (VND)</td><td>'.$decimal($invoice['subtotal_exact_vnd']??'0.000').'</td></tr><tr><td>Điều chỉnh làm tròn chứng từ</td><td>'.$decimal($invoice['rounding_adjustment_vnd']??'0.000').'</td></tr><tr><td>Tạm tính được phân bổ</td><td>'.$money($invoice['subtotal_vnd']??0).'</td></tr><tr><td>Chiết khấu</td><td>'.$money($invoice['discount_vnd']??0).'</td></tr><tr><td>Thuế</td><td>'.$money($invoice['tax_vnd']??0).'</td></tr><tr><td>Tổng cộng (VND)</td><td class="grand">'.$money($invoice['total_vnd']??0).'</td></tr></table>';
        if(($invoice['status']??'')==='VOID'&&trim((string)($invoice['void_reason']??''))!=='')$html.='<p><strong>Lý do hủy:</strong> '.$h($invoice['void_reason']).'</p>';
        $html.='<div class="foot">Chứng từ theo dõi nội bộ; không thay thế hóa đơn điện tử hoặc chứng từ kế toán hợp pháp.</div>';if($withPrintButton)$html.='<div class="print"><button type="button" onclick="window.print()">In / lưu</button></div>';return $html.'</body></html>';
    }

    public function pdf(array $model):string
    {
        if(!class_exists(Dompdf::class)||!class_exists(Options::class))throw new DomainException('Thiếu thư viện PDF. Cài dependency của plugin mini-erp.');
        $options=new Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$options->set('defaultFont','DejaVu Sans');$options->set('chroot',[__DIR__]);
        $renderer=new Dompdf($options);$renderer->setPaper('A4','portrait');$renderer->loadHtml($this->html($model,false),'UTF-8');$renderer->render();return $renderer->output();
    }

    private function table(string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$this->db->prefix)||!in_array($suffix,['erp_invoices','erp_invoice_items','erp_shipments','erp_sales_orders'],true))throw new DomainException('Cấu hình bảng dữ liệu không hợp lệ.');return '`'.$this->db->prefix.$suffix.'`';}

    private function assertSnapshot(array $invoice,array $lines):void
    {
        foreach($lines as $line){
            if(bccomp(bcsub((string)$line['line_subtotal_vnd'],(string)$line['line_subtotal_exact_vnd'],3),(string)$line['rounding_allocation_vnd'],3)!==0||bccomp((string)$line['line_total_vnd'],bcadd(bcsub((string)$line['line_subtotal_vnd'],(string)$line['discount_vnd'],0),(string)$line['tax_vnd'],0),0)!==0)throw new DomainException('Dòng hóa đơn không khớp với phân bổ đã lưu.');
        }
        $items=$this->table('erp_invoice_items');$invoiceId=(int)$invoice['id'];
        $summaryRows=$this->rows($this->db->prepare("SELECT COUNT(*) AS line_count,SUM(line_subtotal_vnd) AS subtotal_vnd,SUM(discount_vnd) AS discount_vnd,SUM(tax_vnd) AS tax_vnd,SUM(line_total_vnd) AS total_vnd,SUM(line_subtotal_exact_vnd) AS subtotal_exact_vnd,SUM(rounding_allocation_vnd) AS rounding_adjustment_vnd FROM {$items} WHERE invoice_id=%d",$invoiceId));
        $summary=$summaryRows[0]??null;
        if(!is_array($summary)||(int)$summary['line_count']!==count($lines)||bccomp((string)$summary['subtotal_vnd'],(string)$invoice['subtotal_vnd'],0)!==0||bccomp((string)$summary['discount_vnd'],(string)$invoice['discount_vnd'],0)!==0||bccomp((string)$summary['tax_vnd'],(string)$invoice['tax_vnd'],0)!==0||bccomp((string)$summary['total_vnd'],(string)$invoice['total_vnd'],0)!==0||bccomp((string)$summary['subtotal_exact_vnd'],(string)$invoice['subtotal_exact_vnd'],3)!==0||bccomp((string)$summary['rounding_adjustment_vnd'],(string)$invoice['rounding_adjustment_vnd'],3)!==0||bccomp((string)$invoice['total_vnd'],bcadd(bcsub((string)$invoice['subtotal_vnd'],(string)$invoice['discount_vnd'],0),(string)$invoice['tax_vnd'],0),0)!==0)throw new DomainException('Tổng hóa đơn không khớp với các dòng hàng đã lưu.');
        if(($invoice['pricing_algorithm_version']??'')==='PRICE-1'&&($invoice['discount_bps']===null||$invoice['tax_bps']===null))throw new DomainException('Snapshot PRICE-1 thiếu tỷ lệ đã lưu.');
    }

    private static function formatDecimal(mixed $value):string
    {
        $text=(string)$value;if(!preg_match('/\A(-?)([0-9]+)(?:\.([0-9]{1,3}))?\z/D',$text,$match))return '0,000';
        $whole=preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/','.',$match[2])??$match[2];
        return $match[1].$whole.','.str_pad($match[3]??'',3,'0');
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql):array{$rows=$this->db->get_results($sql,ARRAY_A);if($this->db->last_error!=='')throw new RuntimeException('Invoice document query failed.');return is_array($rows)?$rows:[];}
}
