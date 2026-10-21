<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\InvoiceDocumentService;
use MiniERP\Application\Services\InvoiceService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use MiniERP\Security\ReadPolicy;
use RuntimeException;
use Throwable;
use wpdb;

/** Internal invoice issue, snapshot detail, and protected HTML print. */
final class InvoiceController
{
    private const PAGE='mini-erp-invoices';
    private static int $preflightInvoiceId=0;
    private static int $preflightActorId=0;

    public static function issue(): void
    {
        CapabilityPolicy::requireCapability('erp_invoices_issue');
        check_admin_referer('mini_erp_invoice_issue');
        global $wpdb;
        $actor=self::actor();$source=self::text($_POST,'source_type');$id=self::positiveInt($_POST['source_id']??null);$due=self::text($_POST,'due_date');
        if(!$wpdb instanceof wpdb||$id===null||!in_array($source,['SHIPMENT','GOODS_RECEIPT'],true))self::redirect('invalid');
        try{$service=new InvoiceService($wpdb,new TransactionManager($wpdb),new AuditLogger());$invoiceId=$source==='SHIPMENT'?$service->issueFromShipment($actor,$id,$due):$service->issueFromGoodsReceipt($actor,$id,$due);self::redirect('issued',$invoiceId);}
        catch(Throwable){self::redirect('invalid');}
    }

    public static function print(): void
    {
        CapabilityPolicy::requireCapability('erp_invoices_view');
        self::printInvoice(self::actor());
    }

    public static function void():void
    {
        CapabilityPolicy::requireCapability('erp_invoices_void');check_admin_referer('mini_erp_invoice_void');global $wpdb;$id=self::positiveInt($_POST['invoice_id']??null);$reason=self::text($_POST,'reason');if(!$wpdb instanceof wpdb||$id===null)self::redirect('invalid');try{(new InvoiceService($wpdb,new TransactionManager($wpdb),new AuditLogger()))->void(self::actor(),$id,$reason);self::redirect('voided',$id,'invoice-void');}catch(Throwable){self::redirect('invalid',$id,'invoice-void');}
    }

    public static function render(): void
    {
        CapabilityPolicy::requireCapability('erp_invoices_view');
        $actor=self::actor();
        $preflightStatus=self::preflightDetail($actor);
        if($preflightStatus!==null)wp_die($preflightStatus===404?'Không tìm thấy hóa đơn hoặc bạn không có quyền truy cập.':'ERP tạm thời không thể xác minh hóa đơn.','',['response'=>$preflightStatus]);
        global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');
        $invoices=self::table($wpdb,'erp_invoices');$orders=self::table($wpdb,'erp_sales_orders');$salesItems=self::table($wpdb,'erp_sales_order_items');$shipments=self::table($wpdb,'erp_shipments');$shipmentItems=self::table($wpdb,'erp_shipment_items');$pos=self::table($wpdb,'erp_purchase_orders');$poItems=self::table($wpdb,'erp_purchase_order_items');$receipts=self::table($wpdb,'erp_goods_receipts');$receiptItems=self::table($wpdb,'erp_goods_receipt_items');
        echo '<div class="wrap erp-app"><h1>Hóa đơn nội bộ</h1><p class="description">Chứng từ theo dõi công nợ nội bộ, không thay thế hóa đơn điện tử hoặc hóa đơn nhà cung cấp.</p>';
        if(isset($_GET['notice'])){$notice=sanitize_key((string)$_GET['notice']);if(in_array($notice,['issued','voided','posted'],true))echo '<div class="notice notice-success"><p>'.esc_html(match($notice){'issued'=>'Đã phát hành hóa đơn nội bộ.','voided'=>'Đã hủy hóa đơn chưa thanh toán.',default=>'Đã ghi nhận thanh toán.'}).'</p></div>';elseif(in_array($notice,['invalid','conflict','unavailable'],true))echo '<div class="notice notice-error"><p>'.esc_html(match($notice){'invalid'=>'Không thể xử lý yêu cầu. Kiểm tra lại dữ liệu và quyền truy cập.','conflict'=>'Dữ liệu nghiệp vụ đã thay đổi. Tải lại trang rồi kiểm tra trước khi gửi lại.',default=>'Dịch vụ tài chính tạm thời không khả dụng. Vui lòng thử lại sau.'}).'</p></div>';}
        $detailId=isset($_GET['invoice'])?absint($_GET['invoice']):0;
        if($detailId>0){self::detail($wpdb,$actor,$detailId);}
        $invoiceScope=(new ReadPolicy())->invoiceScope($actor);$args=[];
        $where=match($invoiceScope['kind']){
            'all'=>" WHERE i.source_type IN ('SHIPMENT','GOODS_RECEIPT')",
            'own_sales'=>" WHERE i.source_type='SHIPMENT' AND EXISTS (SELECT 1 FROM {$shipments} sh INNER JOIN {$orders} so ON so.id=sh.sales_order_id WHERE sh.id=i.source_id AND so.created_by=%d)",
            default=>' WHERE 1=0',
        };
        if($invoiceScope['kind']==='own_sales')$args[]=$invoiceScope['user_id'];
        $search=mb_substr(self::text($_GET,'q'),0,100);
        $status= strtoupper(trim(self::text($_GET,'status')));
        if(!in_array($status,['ISSUED','VOID'],true))$status='';
        $sort=sanitize_key(self::text($_GET,'sort'));
        $sortColumns=['invoice_no'=>'i.invoice_no','issued_at'=>'i.issued_at','due_at'=>'i.due_at','total_vnd'=>'i.total_vnd'];
        if(!isset($sortColumns[$sort]))$sort='issued_at';
        $direction=strtoupper(trim(self::text($_GET,'direction')));
        if(!in_array($direction,['ASC','DESC'],true))$direction='DESC';
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where.=' AND (i.invoice_no LIKE %s OR i.buyer_name LIKE %s OR i.seller_name LIKE %s)';array_push($args,$like,$like,$like);}
        if($status!==''){$where.=' AND i.status=%s';$args[]=$status;}
        $filters=array_filter(['q'=>$search,'status'=>$status,'sort'=>$sort,'direction'=>$direction],static fn(string $value):bool=>$value!=='');
        $page=self::pageNumber($_GET['paged']??1);$perPage=50;$total=0;$pages=1;
        try{$total=(int)self::value($wpdb,"SELECT COUNT(*) FROM {$invoices} i{$where}",$args);$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$sql="SELECT i.id,i.invoice_no,i.source_type,i.source_id,i.direction,i.buyer_name,i.seller_name,i.total_vnd,i.status,i.issued_at,i.due_at FROM {$invoices} i{$where} ORDER BY {$sortColumns[$sort]} {$direction},i.id {$direction} LIMIT %d OFFSET %d";$rows=self::results($wpdb,$sql,[...$args,$perPage,($page-1)*$perPage]);}catch(Throwable){echo '<div class="notice notice-error"><p>Không thể tải danh sách hóa đơn. Vui lòng thử lại sau.</p></div></div>';return;}
        echo '<h2>Danh sách hóa đơn</h2><form class="erp-table-filters" method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><label>Tìm hóa đơn <input type="search" name="q" value="'.esc_attr($search).'" maxlength="100" placeholder="Mã hóa đơn, bên mua hoặc bên bán"></label><label>Trạng thái <select name="status"><option value="">Tất cả</option><option value="ISSUED"'.selected($status,'ISSUED',false).'>Đã phát hành</option><option value="VOID"'.selected($status,'VOID',false).'>Đã hủy</option></select></label><label>Sắp xếp <select name="sort"><option value="issued_at"'.selected($sort,'issued_at',false).'>Ngày phát hành</option><option value="due_at"'.selected($sort,'due_at',false).'>Ngày đến hạn</option><option value="invoice_no"'.selected($sort,'invoice_no',false).'>Mã hóa đơn</option><option value="total_vnd"'.selected($sort,'total_vnd',false).'>Tổng tiền</option></select></label><label>Thứ tự <select name="direction"><option value="DESC"'.selected($direction,'DESC',false).'>Giảm dần</option><option value="ASC"'.selected($direction,'ASC',false).'>Tăng dần</option></select></label><button class="button button-primary">Áp dụng</button> <a class="button" href="'.esc_url(add_query_arg(['page'=>self::PAGE],home_url('/'))).'">Xóa lọc</a></form><div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Số chứng từ</th><th>Nguồn</th><th>Bên mua / bên bán</th><th class="erp-number">Tổng</th><th>Phát hành</th><th>Hạn thanh toán</th><th>Trạng thái</th><th></th></tr></thead><tbody>';
        foreach($rows as $row){$url=add_query_arg(['page'=>self::PAGE,'invoice'=>(int)$row['id']],home_url('/'));echo '<tr><td>'.esc_html((string)$row['invoice_no']).'</td><td>'.esc_html(self::sourceLabel((string)$row['source_type'])).' #'.esc_html((string)$row['source_id']).'</td><td>'.esc_html((string)$row['buyer_name']).' / '.esc_html((string)$row['seller_name']).'</td><td class="erp-money">'.esc_html(number_format((int)$row['total_vnd'],0,',','.')).' ₫</td><td>'.esc_html((string)$row['issued_at']).'</td><td>'.esc_html((string)$row['due_at']).'</td><td>'.esc_html(self::statusLabel((string)$row['status'])).'</td><td><a class="button" href="'.esc_url($url).'">Mở</a></td></tr>';}
        if($rows===[])echo '<tr><td colspan="8">Chưa có hóa đơn phù hợp.</td></tr>';echo '</tbody></table></div>';self::pagination($page,$pages,$total,$filters);
        if($actor->can('erp_invoices_issue')&&$actor->can('erp_invoice_sources_view_all'))self::sources($wpdb,$actor,$orders,$salesItems,$shipments,$shipmentItems,$pos,$poItems,$receipts,$receiptItems,$invoices);
        echo '</div>';
    }

    /** Check object scope before the frontend shell is emitted. */
    public static function preflightDetail(Actor $actor):?int
    {
        $id=isset($_GET['invoice'])?absint($_GET['invoice']):0;
        if($id<1){self::$preflightInvoiceId=0;self::$preflightActorId=0;return null;}
        if(self::$preflightInvoiceId===$id&&self::$preflightActorId===$actor->id)return null;
        global $wpdb;
        if(!$wpdb instanceof wpdb)return 503;
        $scope=(new ReadPolicy())->invoiceScope($actor);
        if($scope['kind']==='none')return 404;
        $invoices=self::table($wpdb,'erp_invoices');$where=['i.id=%d'];$args=[$id];
        if($scope['kind']==='all'){
            $where[]="i.source_type IN ('SHIPMENT','GOODS_RECEIPT')";
        }else{
            $shipments=self::table($wpdb,'erp_shipments');$orders=self::table($wpdb,'erp_sales_orders');
            $where[]="i.source_type='SHIPMENT' AND EXISTS(SELECT 1 FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id WHERE sh.id=i.source_id AND so.created_by=%d)";
            $args[]=$scope['user_id'];
        }
        try{$found=$wpdb->get_var($wpdb->prepare("SELECT i.id FROM {$invoices} i WHERE ".implode(' AND ',$where),$args));}catch(Throwable){return 503;}
        if($wpdb->last_error!=='')return 503;
        if((int)$found!==$id)return 404;
        self::$preflightInvoiceId=$id;self::$preflightActorId=$actor->id;
        return null;
    }

    private static function sources(wpdb $db,Actor $actor,string $orders,string $salesItems,string $shipments,string $shipmentItems,string $pos,string $poItems,string $receipts,string $receiptItems,string $invoices):void
    {
        echo '<h2 id="erp-invoice-sources">Nguồn chưa lập hóa đơn</h2>';
        $perPage=50;
        $salesPage=self::pageNumber($_GET['shipments_page']??1);
        $purchasePage=self::pageNumber($_GET['receipts_page']??1);
        $sourceType=self::text($_GET,'source_type');
        if(!in_array($sourceType,['SHIPMENT','GOODS_RECEIPT'],true))$sourceType='';
        $sourceId=self::positiveInt($_GET['source_id']??null);
        $customerTable=self::table($db,'erp_customers');
        $supplierTable=self::table($db,'erp_suppliers');
        $salesSourceFilter=$sourceType==='GOODS_RECEIPT'?' AND 1=0':'';
        $purchaseSourceFilter=$sourceType==='SHIPMENT'?' AND 1=0':'';
        $salesArgs=[];$purchaseArgs=[];
        if($sourceId!==null&&$sourceType==='SHIPMENT'){$salesSourceFilter.=' AND sh.id=%d';$salesArgs[]=$sourceId;}
        if($sourceId!==null&&$sourceType==='GOODS_RECEIPT'){$purchaseSourceFilter.=' AND gr.id=%d';$purchaseArgs[]=$sourceId;}
        $salesCountSql="SELECT COUNT(DISTINCT sh.id) FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id JOIN {$shipmentItems} si ON si.shipment_id=sh.id LEFT JOIN {$invoices} i ON i.source_type='SHIPMENT' AND i.source_id=sh.id WHERE i.id IS NULL AND so.status IN ('PARTIALLY_SHIPPED','SHIPPED','CLOSED'){$salesSourceFilter}";
        $purchaseCountSql="SELECT COUNT(DISTINCT gr.id) FROM {$receipts} gr JOIN {$pos} po ON po.id=gr.purchase_order_id JOIN {$receiptItems} ri ON ri.goods_receipt_id=gr.id LEFT JOIN {$invoices} i ON i.source_type='GOODS_RECEIPT' AND i.source_id=gr.id WHERE i.id IS NULL AND po.status IN ('PARTIALLY_RECEIVED','RECEIVED','CLOSED'){$purchaseSourceFilter}";
        try{
            $salesTotal=(int)self::value($db,$salesCountSql,$salesArgs);
            $purchaseTotal=(int)self::value($db,$purchaseCountSql,$purchaseArgs);
            $salesPages=max(1,(int)ceil($salesTotal/$perPage));
            $purchasePages=max(1,(int)ceil($purchaseTotal/$perPage));
            $salesPage=min($salesPage,$salesPages);
            $purchasePage=min($purchasePage,$purchasePages);
            $salesSql="SELECT sh.id,sh.shipment_no,sh.shipped_at,so.order_no,c.name AS party_name,ROUND(COALESCE(SUM(si.quantity*oi.unit_price_vnd),0),0) AS estimate FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id JOIN {$customerTable} c ON c.id=so.customer_id JOIN {$shipmentItems} si ON si.shipment_id=sh.id JOIN {$salesItems} oi ON oi.id=si.sales_item_id LEFT JOIN {$invoices} i ON i.source_type='SHIPMENT' AND i.source_id=sh.id WHERE i.id IS NULL AND so.status IN ('PARTIALLY_SHIPPED','SHIPPED','CLOSED'){$salesSourceFilter} GROUP BY sh.id,sh.shipment_no,sh.shipped_at,so.order_no,c.name ORDER BY sh.shipped_at DESC,sh.id DESC LIMIT %d OFFSET %d";
            $purchaseSql="SELECT gr.id,gr.receipt_no,gr.received_at,po.po_no,s.name AS party_name,ROUND(COALESCE(SUM(ri.quantity*pi.unit_cost_vnd),0),0) AS estimate FROM {$receipts} gr JOIN {$pos} po ON po.id=gr.purchase_order_id JOIN {$supplierTable} s ON s.id=po.supplier_id JOIN {$receiptItems} ri ON ri.goods_receipt_id=gr.id JOIN {$poItems} pi ON pi.id=ri.purchase_item_id LEFT JOIN {$invoices} i ON i.source_type='GOODS_RECEIPT' AND i.source_id=gr.id WHERE i.id IS NULL AND po.status IN ('PARTIALLY_RECEIVED','RECEIVED','CLOSED'){$purchaseSourceFilter} GROUP BY gr.id,gr.receipt_no,gr.received_at,po.po_no,s.name ORDER BY gr.received_at DESC,gr.id DESC LIMIT %d OFFSET %d";
            $salesRows=self::results($db,$salesSql,[...$salesArgs,$perPage,($salesPage-1)*$perPage]);
            $purchaseRows=self::results($db,$purchaseSql,[...$purchaseArgs,$perPage,($purchasePage-1)*$perPage]);
        }catch(Throwable){echo '<div class="notice notice-error"><p>Không thể tải danh sách nguồn lập hóa đơn.</p></div>';return;}
        foreach([['SHIPMENT',$salesRows,$salesPage,$salesPages,$salesTotal,'shipments_page','receipts_page',$purchasePage],['GOODS_RECEIPT',$purchaseRows,$purchasePage,$purchasePages,$purchaseTotal,'receipts_page','shipments_page',$salesPage]] as [$type,$rows,$page,$pages,$total,$pageKey,$otherPageKey,$otherPage]){
            echo '<h3>'.esc_html($type==='SHIPMENT'?'Giao hàng':'Nhận hàng từ nhà cung cấp').'</h3><div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Số nguồn</th><th>Đối tác</th><th>Ngày</th><th>Tạm tính</th><th></th></tr></thead><tbody>';
            foreach(is_array($rows)?$rows:[] as $row){$action=admin_url('admin-post.php');echo '<tr><td>'.esc_html((string)($type==='SHIPMENT'?$row['shipment_no']:$row['receipt_no'])).' · '.esc_html((string)($type==='SHIPMENT'?$row['order_no']:$row['po_no'])).'</td><td>'.esc_html((string)$row['party_name']).'</td><td>'.esc_html((string)($type==='SHIPMENT'?$row['shipped_at']:$row['received_at'])).'</td><td>'.esc_html(number_format((int)$row['estimate'],0,',','.')).' ₫</td><td><form method="post" action="'.esc_url($action).'" class="erp-invoice-issue"><input type="hidden" name="action" value="mini_erp_invoice_issue">';wp_nonce_field('mini_erp_invoice_issue');echo '<input type="hidden" name="source_type" value="'.esc_attr($type).'"><input type="hidden" name="source_id" value="'.esc_attr((string)$row['id']).'"><label>Hạn <input required type="date" name="due_date" value="'.esc_attr(wp_date('Y-m-d')).'"></label> <button class="button button-primary">Phát hành</button></form></td></tr>';}
            if(!is_array($rows)||$rows===[])echo '<tr><td colspan="5">Không có nguồn đủ điều kiện.</td></tr>';echo '</tbody></table></div>';self::sourcePagination($pageKey,$page,$pages,$total,$otherPageKey,$otherPage);
        }
    }

    private static function sourcePagination(string $pageKey,int $page,int $pages,int $total,string $otherPageKey,int $otherPage):void
    {
        $sourceType=self::text($_GET,'source_type');
        $sourceId=self::positiveInt($_GET['source_id']??null);
        $sourceArgs=in_array($sourceType,['SHIPMENT','GOODS_RECEIPT'],true)?['source_type'=>$sourceType]:[];
        if($sourceArgs!==[]&&$sourceId!==null)$sourceArgs['source_id']=$sourceId;
        echo '<nav class="erp-pagination" aria-label="Phân trang nguồn hóa đơn"><span>Trang '.esc_html((string)$page).' / '.esc_html((string)$pages).' · '.esc_html(number_format($total,0,',','.')).' nguồn</span>';
        if($page>1)echo ' <a class="button" href="'.esc_url(add_query_arg(['page'=>self::PAGE,...$sourceArgs,$pageKey=>1,$otherPageKey=>$otherPage],home_url('/'))).'">Đầu</a> <a class="button" rel="prev" href="'.esc_url(add_query_arg(['page'=>self::PAGE,...$sourceArgs,$pageKey=>$page-1,$otherPageKey=>$otherPage],home_url('/'))).'">Trước</a>';
        if($page<$pages)echo ' <a class="button" rel="next" href="'.esc_url(add_query_arg(['page'=>self::PAGE,...$sourceArgs,$pageKey=>$page+1,$otherPageKey=>$otherPage],home_url('/'))).'">Sau</a> <a class="button" href="'.esc_url(add_query_arg(['page'=>self::PAGE,...$sourceArgs,$pageKey=>$pages,$otherPageKey=>$otherPage],home_url('/'))).'">Cuối</a>';
        echo '</nav>';
    }

    private static function detail(wpdb $db,Actor $actor,int $id):void
    {
        try{$model=(new InvoiceDocumentService($db))->viewModel($actor,$id);}catch(DomainException){echo '<div class="notice notice-error"><p>Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.</p></div>';return;}catch(Throwable){echo '<div class="notice notice-error"><p>Không thể tải chứng từ. Vui lòng thử lại sau.</p></div>';return;}
        $invoice=$model['invoice'];$lines=$model['lines'];
        $printUrl=wp_nonce_url(add_query_arg(['action'=>'mini_erp_invoice_print','invoice_id'=>$id],admin_url('admin-post.php')),'mini_erp_invoice_print_'.$id);
        $pdfUrl=wp_nonce_url(add_query_arg(['action'=>'mini_erp_invoice_pdf','invoice_id'=>$id],admin_url('admin-post.php')),'mini_erp_invoice_pdf_'.$id);
        echo '<section class="erp-invoice-card"><h2>'.esc_html((string)$invoice['invoice_no']).'</h2><p><strong>CHỨNG TỪ NỘI BỘ — KHÔNG PHẢI HÓA ĐƠN ĐIỆN TỬ</strong></p><p>'.esc_html((string)$invoice['seller_name']).' → '.esc_html((string)$invoice['buyer_name']).'</p><p>Phát hành '.esc_html((string)$invoice['issued_at']).' · Hạn '.esc_html((string)$invoice['due_at']).' · Thuật toán giá '.esc_html((string)$invoice['pricing_algorithm_version']).'</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Mặt hàng</th><th>SL</th><th>Đơn giá</th><th>Giá trị chính xác</th><th>Phân bổ làm tròn</th><th>Tạm tính phân bổ</th><th>CK</th><th>Thuế</th><th>Thành tiền</th></tr></thead><tbody>';
        echo '<p><strong>Trạng thái: '.esc_html(self::statusLabel((string)$invoice['status'])).'</strong></p>';
        foreach(is_array($lines)?$lines:[] as $line)echo '<tr><td>'.esc_html((string)$line['sku_snapshot'].' · '.(string)$line['name_snapshot']).'</td><td>'.esc_html((string)$line['quantity'].' '.(string)$line['unit_snapshot']).'</td><td>'.esc_html(number_format((int)$line['unit_price_vnd'],0,',','.')).'</td><td>'.esc_html(self::formatDecimal((string)$line['line_subtotal_exact_vnd'])).'</td><td>'.esc_html(self::formatDecimal((string)$line['rounding_allocation_vnd'])).'</td><td>'.esc_html(number_format((int)$line['line_subtotal_vnd'],0,',','.')).'</td><td>'.esc_html(number_format((int)$line['discount_vnd'],0,',','.')).'</td><td>'.esc_html(number_format((int)$line['tax_vnd'],0,',','.')).'</td><td>'.esc_html(number_format((int)$line['line_total_vnd'],0,',','.')).'</td></tr>';
        echo '</tbody></table></div><p>Tạm tính chính xác: '.esc_html(self::formatDecimal((string)$invoice['subtotal_exact_vnd'])).' ₫ · Điều chỉnh làm tròn: '.esc_html(self::formatDecimal((string)$invoice['rounding_adjustment_vnd'])).' ₫</p><p>Tỷ lệ chiết khấu/thuế: '.($invoice['discount_bps']===null?'Không lưu trong bản gốc':esc_html((string)$invoice['discount_bps'].' bps')).' / '.($invoice['tax_bps']===null?'Không lưu trong bản gốc':esc_html((string)$invoice['tax_bps'].' bps')).'</p><h3>Tạm tính được phân bổ: '.esc_html(number_format((int)$invoice['subtotal_vnd'],0,',','.')).' ₫ · Chiết khấu: '.esc_html(number_format((int)$invoice['discount_vnd'],0,',','.')).' ₫ · Thuế: '.esc_html(number_format((int)$invoice['tax_vnd'],0,',','.')).' ₫</h3><h3>Tổng cộng: '.esc_html(number_format((int)$invoice['total_vnd'],0,',','.')).' ₫</h3>';
        try{self::paymentSection($db,$actor,$invoice);}catch(Throwable){echo '<div class="notice notice-error"><p>Không thể tải số dư và lịch sử thanh toán.</p></div>';}
        if($actor->can('erp_invoices_print'))echo '<p><a class="button" href="'.esc_url($printUrl).'">In / lưu HTML</a> <a class="button button-primary" href="'.esc_url($pdfUrl).'">Tải PDF</a></p>';echo '</section>';
        if($invoice['status']==='ISSUED'&&$actor->can('erp_invoices_void')){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-finance-form="invoice-void" data-finance-actor="'.esc_attr((string)$actor->id).'" data-finance-scope="invoice-'.esc_attr((string)$id).'"><input type="hidden" name="action" value="mini_erp_invoice_void">';wp_nonce_field('mini_erp_invoice_void');echo '<input type="hidden" name="invoice_id" value="'.esc_attr((string)$id).'"><label>Lý do hủy <input required name="reason" maxlength="1000"></label> <button class="button">Hủy hóa đơn chưa thanh toán</button></form>';}
    }

    /** Payment detail is account-scoped; only the derived invoice balance spans all accounts. */
    private static function paymentSection(wpdb $db,Actor $actor,array $invoice):void
    {
        $paymentTable=self::table($db,'erp_payments');$accountTable=self::table($db,'erp_cash_accounts');$sum=self::value($db,"SELECT COALESCE(SUM(amount_vnd),0) FROM {$paymentTable} WHERE invoice_id=%d AND status IN ('POSTED','VOID_PENDING','VOID_APPROVED')",[(int)$invoice['id']]);$paid=(int)$sum;$outstanding=max(0,(int)$invoice['total_vnd']-$paid);
        echo '<p>Đã thanh toán: '.esc_html(number_format($paid,0,',','.')).' ₫ · Còn phải thu/trả: '.esc_html(number_format($outstanding,0,',','.')).' ₫</p>';
        if($actor->can('erp_payments_view')&&$actor->cashAccountIds!==[]){$slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));$paymentRows=self::results($db,"SELECT p.payment_no,p.direction,p.amount_vnd,p.method,p.external_reference,p.paid_at,p.status,a.account_code FROM {$paymentTable} p JOIN {$accountTable} a ON a.id=p.cash_account_id WHERE p.invoice_id=%d AND p.cash_account_id IN ({$slots}) ORDER BY p.posted_at,p.id",[(int)$invoice['id'],...$actor->cashAccountIds]);echo '<h3>Lịch sử giao dịch trong các tài khoản được phân quyền</h3><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Phiếu</th><th>Quỹ</th><th>Chiều</th><th>Số tiền</th><th>Phương thức / mã tham chiếu</th><th>Ngày</th><th>Trạng thái</th></tr></thead><tbody>';foreach($paymentRows as $payment)echo '<tr><td>'.esc_html((string)$payment['payment_no']).'</td><td>'.esc_html((string)$payment['account_code']).'</td><td>'.esc_html((string)$payment['direction']).'</td><td>'.esc_html(number_format((int)$payment['amount_vnd'],0,',','.')).' ₫</td><td>'.esc_html((string)$payment['method'].' / '.(string)$payment['external_reference']).'</td><td>'.esc_html((string)$payment['paid_at']).'</td><td>'.esc_html((string)$payment['status']).'</td></tr>';if($paymentRows===[])echo '<tr><td colspan="7">Không có giao dịch trong tài khoản được phân quyền.</td></tr>';echo '</tbody></table></div>';}
        if($invoice['status']==='ISSUED'&&$outstanding>0&&$actor->can('erp_payments_record')&&$actor->cashAccountIds!==[]){$slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));$accounts=self::results($db,"SELECT id,account_code,name FROM {$accountTable} WHERE id IN ({$slots}) AND status='ACTIVE' ORDER BY account_code",$actor->cashAccountIds);if($accounts!==[]){echo '<h3>Ghi nhận khoản thu/chi</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-finance-form="payment" data-finance-actor="'.esc_attr((string)$actor->id).'" data-finance-scope="invoice-'.esc_attr((string)$invoice['id']).'"><input type="hidden" name="action" value="mini_erp_payment_post">';wp_nonce_field('mini_erp_payment_post');echo '<input type="hidden" name="invoice_id" value="'.esc_attr((string)$invoice['id']).'"><input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'"><p><label>Số tiền VND <input required name="amount_vnd" inputmode="numeric" pattern="[0-9]+"></label> <label>Tài khoản <select required name="cash_account_id">';foreach($accounts as $account)echo '<option value="'.esc_attr((string)$account['id']).'">'.esc_html($account['account_code'].' · '.$account['name']).'</option>';echo '</select></label> <label>Phương thức <select name="method"><option value="BANK_TRANSFER">Chuyển khoản</option><option value="CASH">Tiền mặt</option><option value="CARD">Thẻ</option><option value="OTHER">Khác</option></select></label></p><p><label>Mã giao dịch ngoài <input name="external_reference" maxlength="128"></label> <label>Ngày giờ giao dịch <input required type="datetime-local" name="paid_at" value="'.esc_attr(wp_date('Y-m-d\TH:i')).'"></label> <button class="button button-primary">Ghi nhận</button></p></form>';}}
    }

    private static function printInvoice(Actor $actor):void
    {
        CapabilityPolicy::requireCapability('erp_invoices_print');$id=isset($_GET['invoice_id'])?absint($_GET['invoice_id']):0;if($id<1)wp_die('Chứng từ không hợp lệ.');check_admin_referer('mini_erp_invoice_print_'.$id);global $wpdb;
        if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');
        try{$model=(new InvoiceDocumentService($wpdb))->viewModel($actor,$id);}catch(DomainException){wp_die('Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.','Mini ERP',['response'=>404]);}catch(Throwable){wp_die('Không thể tải chứng từ. Vui lòng thử lại sau.','Mini ERP',['response'=>500]);}
        nocache_headers();header('Content-Type: text/html; charset=UTF-8');header('X-Content-Type-Options: nosniff');echo (new InvoiceDocumentService($wpdb))->html($model);exit;
    }

    public static function pdf():void
    {
        CapabilityPolicy::requireCapability('erp_invoices_print');$id=isset($_GET['invoice_id'])?absint($_GET['invoice_id']):0;if($id<1)wp_die('Chứng từ không hợp lệ.');check_admin_referer('mini_erp_invoice_pdf_'.$id);global $wpdb;
        if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');$service=new InvoiceDocumentService($wpdb);
        try{$model=$service->viewModel(self::actor(),$id);$bytes=$service->pdf($model);}catch(DomainException $error){$status=str_contains($error->getMessage(),'Thiếu thư viện PDF')?503:404;wp_die($status===503?'Chức năng PDF chưa được cài đặt đầy đủ.':'Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.','Mini ERP',['response'=>$status]);}catch(Throwable){wp_die('Không thể tạo tệp PDF cho chứng từ này.','Mini ERP',['response'=>500]);}
        if($bytes===''||!str_starts_with($bytes,'%PDF-'))wp_die('Không thể tạo tệp PDF hợp lệ.','Mini ERP',['response'=>500]);
        $invoiceNo=(string)$model['invoice']['invoice_no'];$safeNo=preg_match('/\AINV-[0-9]{4}-[0-9]{8}\z/D',$invoiceNo)?$invoiceNo:'invoice-'.$id;
        while(ob_get_level()>0)ob_end_clean();nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$safeNo.'.pdf"');header('X-Content-Type-Options: nosniff');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
    }

    private static function actor():Actor{return CapabilityPolicy::currentActor()??throw new DomainException('Phiên ERP không hợp lệ.');}
    private static function text(array $source,string $key):string{$value=$source[$key]??'';return is_scalar($value)?sanitize_text_field((string)wp_unslash($value)):'';}
    private static function positiveInt(mixed $value):?int{if(!is_scalar($value)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',trim((string)$value)))return null;$number=(int)$value;return $number>0?$number:null;}
    private static function sourceLabel(string $type):string{return $type==='SHIPMENT'?'Giao hàng':'Nhập hàng';}
    private static function statusLabel(string $status):string{return match($status){'ISSUED'=>'Đã phát hành','VOID'=>'Đã hủy',default=>$status};}
    private static function formatDecimal(string $value):string{if(!preg_match('/\A(-?)([0-9]+)(?:\.([0-9]{1,3}))?\z/D',$value,$match))return '0,000';$whole=preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/','.',$match[2])??$match[2];return $match[1].$whole.','.str_pad($match[3]??'',3,'0');}
    private static function results(wpdb $db,string $sql,array $args=[]):array{$rows=$db->get_results($args===[]?$sql:$db->prepare($sql,$args),ARRAY_A);if($db->last_error!==''||!is_array($rows))throw new RuntimeException('Invoice query failed.');return $rows;}
    private static function value(wpdb $db,string $sql,array $args=[]):mixed{$value=$db->get_var($args===[]?$sql:$db->prepare($sql,$args));if($db->last_error!=='')throw new RuntimeException('Invoice query failed.');return $value;}
    private static function pageNumber(mixed $value):int{if(!is_scalar($value))return 1;$value=trim((string)wp_unslash($value));if(!preg_match('/\A[0-9]{1,18}\z/D',$value))return 1;return max(1,(int)$value);}
    private static function pagination(int $page,int $pages,int $total,array $filters=[]):void{$base=['page'=>self::PAGE,...$filters];echo '<nav class="erp-pagination" aria-label="Phân trang hóa đơn"><span>Trang '.esc_html((string)$page).' / '.esc_html((string)$pages).' · '.esc_html(number_format($total,0,',','.')).' hóa đơn</span>';if($page>1){echo ' <a class="button" href="'.esc_url(add_query_arg([...$base,'paged'=>1],home_url('/'))).'">Đầu</a> <a class="button" rel="prev" href="'.esc_url(add_query_arg([...$base,'paged'=>$page-1],home_url('/'))).'">Trước</a>';}if($page<$pages){echo ' <a class="button" rel="next" href="'.esc_url(add_query_arg([...$base,'paged'=>$page+1],home_url('/'))).'">Sau</a> <a class="button" href="'.esc_url(add_query_arg([...$base,'paged'=>$pages],home_url('/'))).'">Cuối</a>'; }echo '</nav>';}
    private static function table(wpdb $db,string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$db->prefix)||!in_array($suffix,['erp_invoices','erp_invoice_items','erp_sales_orders','erp_sales_order_items','erp_shipments','erp_shipment_items','erp_purchase_orders','erp_purchase_order_items','erp_goods_receipts','erp_goods_receipt_items','erp_customers','erp_suppliers','erp_payments','erp_cash_accounts'],true))throw new DomainException('Cấu hình bảng không hợp lệ.');return '`'.$db->prefix.$suffix.'`';}
    private static function redirect(string $notice,?int $id=null,?string $recoverForm=null):never{$args=['page'=>self::PAGE,'notice'=>$notice];if($id!==null)$args['invoice']=$id;if($recoverForm!==null)$args['recover_form']=$recoverForm;wp_safe_redirect(add_query_arg($args,home_url('/')),303);exit;}
}
