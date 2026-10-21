<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\CashDocumentService;
use MiniERP\Application\Services\PaymentService;
use MiniERP\Domain\BusinessConflictException;
use MiniERP\Domain\RecordNotFoundException;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use MiniERP\Security\ReadPolicy;
use Throwable;
use wpdb;

/** Assigned-account cash book, maker-checker account activation, and payment posting. */
final class CashController
{
    private const ACCOUNTS='mini-erp-cash-accounts';private const PAYMENTS='mini-erp-payments';private const EXPENSES='mini-erp-expenses';private const INVOICES='mini-erp-invoices';

    public static function saveAccount():void
    {
        CapabilityPolicy::requireCapability('erp_cash_accounts_manage');
        check_admin_referer('mini_erp_cash_account_save');
        global $wpdb;
        if(!$wpdb instanceof wpdb)self::fail(503,'Sổ quỹ đang tạm thời không khả dụng.',self::ACCOUNTS);
        try{
            $id=self::service($wpdb)->createCashAccount(self::actor(),self::text('account_code'),self::text('name'),self::text('type'),self::text('opening_balance_vnd'));
            self::redirect(self::ACCOUNTS,'saved',$id);
        }catch(Throwable $failure){self::failFrom($failure,'Không thể tạo tài khoản tiền lúc này.',self::ACCOUNTS);}
    }

    public static function activateAccount():void
    {
        CapabilityPolicy::requireCapability('erp_cash_accounts_activate');
        check_admin_referer('mini_erp_cash_account_activate');
        global $wpdb;
        if(!$wpdb instanceof wpdb)self::fail(503,'Sổ quỹ đang tạm thời không khả dụng.',self::ACCOUNTS);
        $id=self::id('account_id');
        if($id===null)self::fail(404,'Không tìm thấy tài khoản hoặc bạn không có quyền truy cập.',self::ACCOUNTS);
        $actor=self::actor();
        self::requireAccountTarget($wpdb,$actor,$id,self::ACCOUNTS);
        try{self::service($wpdb)->activateCashAccount($actor,$id);self::redirect(self::ACCOUNTS,'activated');}
        catch(Throwable $failure){self::failFrom($failure,'Không thể kích hoạt tài khoản tiền lúc này.',self::ACCOUNTS);}
    }

    public static function postPayment():void
    {
        CapabilityPolicy::requireCapability('erp_payments_record');
        check_admin_referer('mini_erp_payment_post');
        global $wpdb;
        if(!$wpdb instanceof wpdb)self::fail(503,'Không thể ghi nhận thanh toán lúc này.',self::PAYMENTS);
        $invoice=self::id('invoice_id');
        $account=self::id('cash_account_id');
        if($invoice===null||$account===null)self::fail(404,'Không tìm thấy hóa đơn hoặc tài khoản tiền.',self::PAYMENTS);
        $actor=self::actor();
        self::requireInvoiceTarget($wpdb,$actor,$invoice);
        self::requireAccountTarget($wpdb,$actor,$account,self::INVOICES,$invoice,'payment');
        $amount=self::text('amount_vnd');
        $method=self::text('method');
        $reference=self::text('external_reference');
        $paid=self::text('paid_at');
        $request=self::text('request_key');
        try{self::service($wpdb)->post($actor,$invoice,$account,$amount,$method,$reference,$paid,$request);self::redirectPaymentForm($invoice,'posted');}
        catch(Throwable $failure){self::failFrom($failure,'Không thể ghi nhận thanh toán lúc này.',self::INVOICES,$invoice,'payment');}
    }

    public static function postExpense():void
    {
        CapabilityPolicy::requireCapability('erp_expenses_manage');
        check_admin_referer('mini_erp_expense_post');
        global $wpdb;
        if(!$wpdb instanceof wpdb)self::fail(503,'Không thể ghi phiếu chi lúc này.',self::EXPENSES,null,'expense');
        $account=self::id('cash_account_id');
        if($account===null)self::fail(404,'Không tìm thấy tài khoản tiền hoặc bạn không có quyền truy cập.',self::EXPENSES,null,'expense');
        $actor=self::actor();
        self::requireAccountTarget($wpdb,$actor,$account,self::EXPENSES,null,'expense');
        try{$expense=self::service($wpdb)->postExpense($actor,$account,self::text('category'),self::text('description'),self::text('amount_vnd'),self::text('occurred_at'),self::text('request_key'));self::redirect(self::EXPENSES,'expense_posted',$expense,'expense');}
        catch(Throwable $failure){self::failFrom($failure,'Không thể ghi phiếu chi lúc này.',self::EXPENSES,null,'expense');}
    }

    public static function paymentVoid():void
    {
        $operation=sanitize_key(self::text('operation'));
        $caps=['request'=>'erp_payments_void_request','approve'=>'erp_payments_void_approve','reject'=>'erp_payments_void_approve','apply'=>'erp_payments_void_apply'];
        if(!isset($caps[$operation]))self::fail(422,'Thao tác hủy thanh toán không hợp lệ.',self::PAYMENTS);
        CapabilityPolicy::requireCapability($caps[$operation]);
        check_admin_referer('mini_erp_payment_void_'.$operation);
        global $wpdb;
        if(!$wpdb instanceof wpdb)self::fail(503,'Không thể xử lý yêu cầu hủy lúc này.',self::PAYMENTS);
        $id=self::id('payment_id');
        if($id===null)self::fail(404,'Không tìm thấy phiếu thanh toán hoặc bạn không có quyền truy cập.',self::PAYMENTS);
        $actor=self::actor();
        self::requirePaymentTarget($wpdb,$actor,$id);
        $reason=self::text('reason');
        $service=self::service($wpdb);
        try{
            if($operation==='request')$service->requestVoid($actor,$id,$reason);
            elseif($operation==='approve'||$operation==='reject')$service->decideVoid($actor,$id,$operation==='approve',$reason);
            else $service->applyVoid($actor,$id);
            self::redirect(self::PAYMENTS,'voided');
        }catch(Throwable $failure){self::failFrom($failure,'Không thể xử lý yêu cầu hủy lúc này.',self::PAYMENTS);}
    }

    public static function paymentVoucherPrint():void{self::streamVoucher('payment',false);}
    public static function paymentVoucherPdf():void{self::streamVoucher('payment',true);}
    public static function expenseVoucherPrint():void{self::streamVoucher('expense',false);}
    public static function expenseVoucherPdf():void{self::streamVoucher('expense',true);}

    public static function renderAccounts():void
    {
        CapabilityPolicy::requireCapability('erp_cash_accounts_view');$actor=self::actor();global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');$accounts=self::table($wpdb,'erp_cash_accounts');$movements=self::table($wpdb,'erp_cash_movements');$scope=$actor->cashAccountIds;$where=[];$args=[];
        if($scope!==[]){$where[]='a.id IN ('.implode(',',array_fill(0,count($scope),'%d')).')';array_push($args,...$scope);}if($where===[])$where[]='1=0';
        $sql="SELECT a.id,a.account_code,a.name,a.type,a.currency,a.opening_balance_vnd,a.status,a.created_by,a.activated_by,a.activated_at,a.created_at,CASE WHEN a.status='ACTIVE' THEN a.opening_balance_vnd+COALESCE(SUM(CASE WHEN m.direction='IN' THEN m.amount_vnd ELSE -m.amount_vnd END),0) ELSE NULL END AS computed_balance_vnd FROM {$accounts} a LEFT JOIN {$movements} m ON m.cash_account_id=a.id WHERE ".implode(' OR ',$where).' GROUP BY a.id ORDER BY a.created_at DESC,a.id DESC LIMIT 100';try{$rows=self::results($wpdb,$sql,$args);}catch(Throwable){echo '<div class="wrap erp-app"><h1>Quỹ tiền mặt và tài khoản ngân hàng</h1><div class="notice notice-error"><p>Không thể tải danh sách quỹ. Vui lòng thử lại sau.</p></div></div>';return;}
        echo '<div class="wrap erp-app"><h1>Quỹ tiền mặt và tài khoản ngân hàng</h1><p>Mỗi quỹ tiền mặt hoặc tài khoản ngân hàng nội bộ là một nơi giữ tiền riêng. Thu, chi và thanh toán đều ghi vào sổ của một quỹ đã được phân quyền.</p><p>Số dư hiện tại được tính từ số dư mở kỳ và sổ dòng tiền đã ghi nhận.</p>';if($scope===[])echo '<div class="notice notice-info"><p>Bạn chưa được gán quỹ nào. Tài khoản mới tạo cần ERP System Admin gán cho người dùng trước khi xuất hiện tại đây.</p></div>';self::notice();echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Mã</th><th>Tên quỹ / tài khoản</th><th>Loại</th><th>Số dư mở</th><th>Số dư hiện tại</th><th>Trạng thái</th><th></th></tr></thead><tbody>';
        foreach(is_array($rows)?$rows:[] as $row){echo '<tr><td>'.esc_html((string)$row['account_code']).'</td><td>'.esc_html((string)$row['name']).'</td><td>'.esc_html((string)$row['type']).'</td><td>'.esc_html(number_format((int)$row['opening_balance_vnd'],0,',','.')).' ₫</td><td>'.($row['computed_balance_vnd']===null?'—':esc_html(number_format((int)$row['computed_balance_vnd'],0,',','.')).' ₫').'</td><td>'.esc_html((string)$row['status']).'</td><td>';
            if($actor->can('erp_cash_accounts_activate')&&(int)$row['created_by']!==$actor->id&&$row['status']==='DRAFT'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mini_erp_cash_account_activate">';wp_nonce_field('mini_erp_cash_account_activate');echo '<input type="hidden" name="account_id" value="'.esc_attr((string)$row['id']).'"><button class="button">Kích hoạt</button></form>';}
            echo '</td></tr>';}
        if(!is_array($rows)||$rows===[])echo '<tr><td colspan="7">Chưa có quỹ tiền mặt hoặc tài khoản ngân hàng được phân quyền.</td></tr>';echo '</tbody></table></div>';
        if($actor->can('erp_cash_accounts_manage')){echo '<h2>Tạo quỹ hoặc tài khoản ngân hàng nháp</h2><p>Bản nháp không tự cấp quyền xem. ERP System Admin cần gán tài khoản cho người dùng trước khi họ có thể xem số dư hoặc ghi giao dịch.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mini_erp_cash_account_save">';wp_nonce_field('mini_erp_cash_account_save');echo '<p><label>Mã <input required name="account_code" maxlength="64"></label> <label>Tên <input required name="name" maxlength="191"></label> <label>Loại <select name="type"><option value="CASH">Quỹ tiền mặt</option><option value="BANK">Tài khoản ngân hàng</option></select></label> <label>Số dư mở kỳ (VND) <input required name="opening_balance_vnd" inputmode="numeric" pattern="[0-9]+"></label> <button class="button button-primary">Lưu nháp</button></p></form>';}
        echo '</div>';
    }

    public static function renderPayments():void
    {
        CapabilityPolicy::requireCapability('erp_payments_view');$actor=self::actor();global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');$payments=self::table($wpdb,'erp_payments');$invoices=self::table($wpdb,'erp_invoices');$accounts=self::table($wpdb,'erp_cash_accounts');$shipments=self::table($wpdb,'erp_shipments');$orders=self::table($wpdb,'erp_sales_orders');$scope=$actor->cashAccountIds;$invoiceScope=(new ReadPolicy())->invoiceScope($actor);$invoiceWhere=match($invoiceScope['kind']){'all'=>"i.source_type IN ('SHIPMENT','GOODS_RECEIPT')",'own_sales'=>"i.source_type='SHIPMENT' AND EXISTS (SELECT 1 FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id WHERE sh.id=i.source_id AND so.created_by=%d)",default=>'1=0'};$invoiceArgs=$invoiceScope['kind']==='own_sales'?[$invoiceScope['user_id']]:[];
        $search=mb_substr(self::queryText('q'),0,100);$statusFilter=strtoupper(trim(self::queryText('status')));if(!in_array($statusFilter,['POSTED','VOID_PENDING','VOID_APPROVED','VOID'],true))$statusFilter='';$methodFilter=strtoupper(trim(self::queryText('method')));if(!in_array($methodFilter,['CASH','BANK_TRANSFER','CARD','OTHER'],true))$methodFilter='';
        $sort=sanitize_key(self::queryText('sort'));$sortColumns=['payment_no'=>'p.payment_no','posted_at'=>'p.posted_at','paid_at'=>'p.paid_at','amount_vnd'=>'p.amount_vnd'];if(!isset($sortColumns[$sort]))$sort='posted_at';$direction=strtoupper(trim(self::queryText('direction')));if(!in_array($direction,['ASC','DESC'],true))$direction='DESC';
        $filterWhere='';$filterArgs=[];if($statusFilter!==''){$filterWhere.=' AND p.status=%s';$filterArgs[]=$statusFilter;}if($methodFilter!==''){$filterWhere.=' AND p.method=%s';$filterArgs[]=$methodFilter;}if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$filterWhere.=' AND (p.payment_no LIKE %s OR i.invoice_no LIKE %s OR i.buyer_name LIKE %s OR i.seller_name LIKE %s OR p.external_reference LIKE %s)';array_push($filterArgs,$like,$like,$like,$like,$like);}
        $filters=array_filter(['q'=>$search,'status'=>$statusFilter,'method'=>$methodFilter,'sort'=>$sort,'direction'=>$direction],static fn(string $value):bool=>$value!=='');$rows=[];$total=0;$page=self::pageNumber($_GET['paged']??1);$perPage=50;$pages=1;
        try{if($scope!==[]){$slots=implode(',',array_fill(0,count($scope),'%d'));$where="p.cash_account_id IN ({$slots}) AND {$invoiceWhere}{$filterWhere}";$queryArgs=[...$scope,...$invoiceArgs,...$filterArgs];$counts=self::results($wpdb,"SELECT COUNT(*) AS total FROM {$payments} p JOIN {$invoices} i ON i.id=p.invoice_id WHERE {$where}",$queryArgs);$total=filter_var($counts[0]['total']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($total===false)throw new \RuntimeException('Payment count query failed.');$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$rows=self::results($wpdb,"SELECT p.id,p.payment_no,p.invoice_id,p.direction,p.amount_vnd,p.method,p.external_reference,p.paid_at,p.posted_at,p.status,p.created_by,p.void_requested_by,p.void_approved_by,a.account_code,i.invoice_no,i.buyer_name,i.seller_name FROM {$payments} p JOIN {$accounts} a ON a.id=p.cash_account_id JOIN {$invoices} i ON i.id=p.invoice_id WHERE {$where} ORDER BY {$sortColumns[$sort]} {$direction},p.id {$direction} LIMIT %d OFFSET %d",[...$queryArgs,$perPage,($page-1)*$perPage]);}}catch(Throwable){echo '<div class="wrap erp-app"><h1>Thu chi và thanh toán</h1><div class="notice notice-error"><p>Không thể tải danh sách giao dịch. Vui lòng thử lại sau.</p></div></div>';return;}
        echo '<div class="wrap erp-app"><h1>Thu chi và thanh toán</h1><p>Khoản tiền được ghi thẳng vào một tài khoản đã được phân quyền; khoản thu/chi từng phần tiếp tục cập nhật dư nợ hóa đơn.</p>';self::notice();echo '<form class="erp-table-filters" method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="page" value="mini-erp-payments"><label>Tìm giao dịch <input type="search" name="q" value="'.esc_attr($search).'" maxlength="100" placeholder="Mã phiếu, hóa đơn, đối tác, tham chiếu"></label><label>Trạng thái <select name="status"><option value="">Tất cả</option><option value="POSTED"'.selected($statusFilter,'POSTED',false).'>Đã ghi sổ</option><option value="VOID_PENDING"'.selected($statusFilter,'VOID_PENDING',false).'>Chờ duyệt hủy</option><option value="VOID_APPROVED"'.selected($statusFilter,'VOID_APPROVED',false).'>Đã duyệt hủy</option><option value="VOID"'.selected($statusFilter,'VOID',false).'>Đã hủy</option></select></label><label>Phương thức <select name="method"><option value="">Tất cả</option><option value="CASH"'.selected($methodFilter,'CASH',false).'>Tiền mặt</option><option value="BANK_TRANSFER"'.selected($methodFilter,'BANK_TRANSFER',false).'>Chuyển khoản</option><option value="CARD"'.selected($methodFilter,'CARD',false).'>Thẻ</option><option value="OTHER"'.selected($methodFilter,'OTHER',false).'>Khác</option></select></label><label>Sắp xếp <select name="sort"><option value="posted_at"'.selected($sort,'posted_at',false).'>Ngày ghi sổ</option><option value="paid_at"'.selected($sort,'paid_at',false).'>Ngày giao dịch</option><option value="payment_no"'.selected($sort,'payment_no',false).'>Mã phiếu</option><option value="amount_vnd"'.selected($sort,'amount_vnd',false).'>Số tiền</option></select></label><label>Thứ tự <select name="direction"><option value="DESC"'.selected($direction,'DESC',false).'>Giảm dần</option><option value="ASC"'.selected($direction,'ASC',false).'>Tăng dần</option></select></label><button class="button button-primary">Áp dụng</button> <a class="button" href="'.esc_url(add_query_arg(['page'=>'mini-erp-payments'],home_url('/'))).'">Xóa lọc</a></form><div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Mã phiếu</th><th>Hóa đơn</th><th>Đối tác</th><th>Quỹ</th><th>Loại</th><th class="erp-number">Số tiền</th><th>Ngày giao dịch</th><th>Trạng thái</th><th>Hành động</th></tr></thead><tbody>';
        foreach(is_array($rows)?$rows:[] as $row){$party=$row['direction']==='IN'?$row['buyer_name']:$row['seller_name'];echo '<tr><td>'.esc_html((string)$row['payment_no']).'</td><td>'.esc_html((string)$row['invoice_no']).'</td><td>'.esc_html((string)$party).'</td><td>'.esc_html((string)$row['account_code']).'</td><td>'.esc_html((string)$row['direction'].' · '.(string)$row['method']).'</td><td class="erp-money">'.esc_html(number_format((int)$row['amount_vnd'],0,',','.')).' ₫</td><td>'.esc_html((string)$row['paid_at']).'</td><td>'.esc_html((string)$row['status']).'</td><td>';
            if($row['status']==='POSTED'&&$actor->can('erp_payments_void_request'))self::voidForm((int)$row['id'],'request','Yêu cầu hủy',true);
            if($row['status']==='VOID_PENDING'&&$actor->can('erp_payments_void_approve')&&(int)$row['void_requested_by']!==$actor->id){self::voidForm((int)$row['id'],'approve','Duyệt',false);self::voidForm((int)$row['id'],'reject','Từ chối',true);}
            if($row['status']==='VOID_APPROVED'&&$actor->can('erp_payments_void_apply')&&(int)$row['void_approved_by']!==$actor->id)self::voidForm((int)$row['id'],'apply','Áp dụng hủy',false);
            echo self::voucherActions('payment',(int)$row['id']).'</td></tr>';}
        if(!is_array($rows)||$rows===[])echo '<tr><td colspan="9">Không có giao dịch phù hợp trong các tài khoản được phân quyền.</td></tr>';echo '</tbody></table></div>';self::pagination('mini-erp-payments',$page,$pages,$total,'giao dịch',$filters);echo '</div>';
    }

    public static function renderExpenses():void
    {
        $actor=self::expenseReadActor();global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');$expenses=self::table($wpdb,'erp_expenses');$accounts=self::table($wpdb,'erp_cash_accounts');$rows=[];$activeAccounts=[];$total=0;$page=self::pageNumber($_GET['paged']??1);$perPage=50;$pages=1;$search=mb_substr(self::queryText('q'),0,100);$sort=sanitize_key(self::queryText('sort'));$sortColumns=['expense_no'=>'e.expense_no','occurred_at'=>'e.occurred_at','amount_vnd'=>'e.amount_vnd'];if(!isset($sortColumns[$sort]))$sort='occurred_at';$direction=strtoupper(trim(self::queryText('direction')));if(!in_array($direction,['ASC','DESC'],true))$direction='DESC';$filterSql='';$filterArgs=[];if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$filterSql=' AND (e.expense_no LIKE %s OR e.category LIKE %s OR e.description LIKE %s)';$filterArgs=[$like,$like,$like];}$filters=array_filter(['q'=>$search,'sort'=>$sort,'direction'=>$direction],static fn(string $value):bool=>$value!=='');
        try{if($actor->cashAccountIds!==[]){$slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));$where="e.cash_account_id IN ({$slots}){$filterSql}";$queryArgs=[...$actor->cashAccountIds,...$filterArgs];$counts=self::results($wpdb,"SELECT COUNT(*) AS total FROM {$expenses} e WHERE {$where}",$queryArgs);$total=filter_var($counts[0]['total']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);if($total===false)throw new \RuntimeException('Expense count query failed.');$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$rows=self::results($wpdb,"SELECT e.id,e.expense_no,e.category,e.description,e.amount_vnd,e.occurred_at,e.status,a.account_code FROM {$expenses} e JOIN {$accounts} a ON a.id=e.cash_account_id WHERE {$where} ORDER BY {$sortColumns[$sort]} {$direction},e.id {$direction} LIMIT %d OFFSET %d",[...$queryArgs,$perPage,($page-1)*$perPage]);if($actor->can('erp_expenses_manage'))$activeAccounts=self::results($wpdb,"SELECT id,account_code,name FROM {$accounts} WHERE id IN ({$slots}) AND status='ACTIVE' ORDER BY account_code",$actor->cashAccountIds);}}catch(Throwable){echo '<div class="wrap erp-app"><h1>Phiếu chi</h1><div class="notice notice-error"><p>Không thể tải danh sách phiếu chi. Vui lòng thử lại sau.</p></div></div>';return;}
        echo '<div class="wrap erp-app"><h1>Phiếu chi</h1><p>Phiếu chi đã ghi sổ là bất biến và luôn có dòng OUT tương ứng trong sổ quỹ. Danh sách chỉ gồm các quỹ được phân quyền.</p>';if(!$actor->can('erp_expenses_manage'))echo '<p>Bạn có quyền xem báo cáo tài chính; màn hình này chỉ đọc và không cho ghi phiếu chi.</p>';self::notice();echo '<form class="erp-table-filters" method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="page" value="mini-erp-expenses"><label>Tìm phiếu chi <input type="search" name="q" value="'.esc_attr($search).'" maxlength="100" placeholder="Mã phiếu, danh mục hoặc nội dung"></label><label>Sắp xếp <select name="sort"><option value="occurred_at"'.selected($sort,'occurred_at',false).'>Ngày chi</option><option value="expense_no"'.selected($sort,'expense_no',false).'>Mã phiếu</option><option value="amount_vnd"'.selected($sort,'amount_vnd',false).'>Số tiền</option></select></label><label>Thứ tự <select name="direction"><option value="DESC"'.selected($direction,'DESC',false).'>Giảm dần</option><option value="ASC"'.selected($direction,'ASC',false).'>Tăng dần</option></select></label><button class="button button-primary">Áp dụng</button> <a class="button" href="'.esc_url(add_query_arg(['page'=>'mini-erp-expenses'],home_url('/'))).'">Xóa lọc</a></form><div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Mã phiếu</th><th>Ngày</th><th>Danh mục / nội dung</th><th>Quỹ</th><th class="erp-number">Số tiền</th><th>Trạng thái</th><th>Chứng từ</th></tr></thead><tbody>';
        foreach ((is_array($rows) ? $rows : []) as $row) {
            echo '<tr><td>' . esc_html((string) $row['expense_no']) . '</td><td>'
                . esc_html((string) $row['occurred_at']) . '</td><td>'
                . esc_html((string) $row['category'] . ' · ' . (string) $row['description']) . '</td><td>'
                . esc_html((string) $row['account_code']) . '</td><td class="erp-money">'
                . esc_html(number_format((int) $row['amount_vnd'], 0, ',', '.')) . ' ₫</td><td>'
                . esc_html((string) $row['status']) . '</td><td>'
                . self::voucherActions('expense', (int) $row['id']) . '</td></tr>';
        }
        if(!is_array($rows)||$rows===[])echo '<tr><td colspan="7">Không có phiếu chi phù hợp trong các tài khoản được phân quyền.</td></tr>';echo '</tbody></table></div>';self::pagination('mini-erp-expenses',$page,$pages,$total,'phiếu chi',$filters);
        if($actor->can('erp_expenses_manage')&&is_array($activeAccounts)&&$activeAccounts!==[]){echo '<h2>Ghi phiếu chi</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-finance-form="expense" data-finance-actor="'.esc_attr((string)$actor->id).'" data-finance-scope="expense"><input type="hidden" name="action" value="mini_erp_expense_post">';wp_nonce_field('mini_erp_expense_post');echo '<input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'"><p><label>Danh mục <input required name="category" maxlength="64"></label> <label>Tài khoản <select required name="cash_account_id">';foreach($activeAccounts as $account)echo '<option value="'.esc_attr((string)$account['id']).'">'.esc_html($account['account_code'].' · '.$account['name']).'</option>';echo '</select></label> <label>Số tiền VND <input required name="amount_vnd" inputmode="numeric" pattern="[0-9]+"></label></p><p><label>Nội dung <input required name="description" maxlength="1000" class="large-text"></label> <label>Ngày giờ chi <input required type="datetime-local" name="occurred_at" value="'.esc_attr(wp_date('Y-m-d\TH:i')).'"></label> <button class="button button-primary">Ghi phiếu chi</button></p></form>';}
        echo '</div>';
    }

    private static function service(wpdb $db):PaymentService{return new PaymentService($db,new TransactionManager($db),new AuditLogger());}
    private static function requireAccountTarget(wpdb $db,Actor $actor,int $accountId,string $page,?int $recordId=null,?string $recoverForm=null):void
    {
        $table=self::table($db,'erp_cash_accounts');
        $row=$db->get_row($db->prepare("SELECT id FROM {$table} WHERE id=%d",$accountId),ARRAY_A);
        if($db->last_error!=='')self::fail(503,'Không thể xác minh quyền truy cập tài khoản tiền.',$page,$recordId,$recoverForm,'unavailable');
        if(!is_array($row)||!(new ReadPolicy())->canReadCashAccount($actor,$row['id']??null))self::fail(404,'Không tìm thấy tài khoản tiền hoặc bạn không có quyền truy cập.',$page,$recordId,$recoverForm);
    }
    private static function requireInvoiceTarget(wpdb $db,Actor $actor,int $invoiceId):void
    {
        $invoices=self::table($db,'erp_invoices');$shipments=self::table($db,'erp_shipments');$orders=self::table($db,'erp_sales_orders');
        $row=$db->get_row($db->prepare("SELECT i.id,i.source_type,so.created_by AS source_creator_id FROM {$invoices} i LEFT JOIN {$shipments} sh ON i.source_type='SHIPMENT' AND sh.id=i.source_id LEFT JOIN {$orders} so ON so.id=sh.sales_order_id WHERE i.id=%d",$invoiceId),ARRAY_A);
        if($db->last_error!=='')self::fail(503,'Không thể xác minh quyền truy cập hóa đơn.',self::INVOICES,$invoiceId,'payment','unavailable');
        if(!is_array($row)||!(new ReadPolicy())->canReadInvoice($actor,$row))self::fail(404,'Không tìm thấy hóa đơn hoặc bạn không có quyền truy cập.',self::INVOICES,$invoiceId,'payment');
    }
    private static function requirePaymentTarget(wpdb $db,Actor $actor,int $paymentId):void
    {
        $table=self::table($db,'erp_payments');
        $row=$db->get_row($db->prepare("SELECT cash_account_id FROM {$table} WHERE id=%d",$paymentId),ARRAY_A);
        if($db->last_error!=='')self::fail(503,'Không thể xác minh quyền truy cập phiếu thanh toán.',self::PAYMENTS,null,null,'unavailable');
        if(!is_array($row)||!(new ReadPolicy())->canReadCashAccount($actor,$row['cash_account_id']??null))self::fail(404,'Không tìm thấy phiếu thanh toán hoặc bạn không có quyền truy cập.',self::PAYMENTS);
    }
    private static function failFrom(Throwable $failure,string $generic,string $page,?int $recordId=null,?string $recoverForm=null):never
    {
        if($failure instanceof RecordNotFoundException)self::fail(404,'Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.',$page,$recordId,$recoverForm,'invalid');
        if($failure instanceof BusinessConflictException||$failure instanceof DomainException)self::fail(409,$failure->getMessage(),$page,$recordId,$recoverForm,'conflict');
        if($failure instanceof \InvalidArgumentException)self::fail(422,$failure->getMessage(),$page,$recordId,$recoverForm,'invalid');
        if($failure instanceof DatabaseException&&$failure->getCode()===1062)self::fail(409,'Yêu cầu đã được ghi nhận hoặc mã tài khoản đã tồn tại.',$page,$recordId,$recoverForm,'conflict');
        self::fail(503,$generic,$page,$recordId,$recoverForm,'unavailable');
    }
    private static function fail(int $status,string $message,string $page,?int $recordId=null,?string $recoverForm=null,string $notice='invalid'):never
    {
        $args=['page'=>$page,'notice'=>$notice];
        if($recordId!==null){if($page==='mini-erp-invoices')$args['invoice']=$recordId;else $args['record']=$recordId;}
        if($recoverForm!==null){$args['recover_form']=$recoverForm;if($recoverForm==='payment'&&$recordId!==null)$args['recover_scope']='invoice-'.$recordId;}
        $url=add_query_arg($args,home_url('/'));
        $heading=match($status){404=>'Không tìm thấy chứng từ',409=>'Dữ liệu nghiệp vụ đã thay đổi',422=>'Kiểm tra lại thông tin',default=>'Dịch vụ đang tạm thời gián đoạn'};
        $stylesheet=plugins_url('assets/frontend.css',MINI_ERP_PLUGIN_FILE);
        status_header($status);
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        echo '<!doctype html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($heading).' — Mini ERP</title><link rel="stylesheet" href="'.esc_url($stylesheet).'"></head><body class="mini-erp-frontend"><main class="erp-message"><a class="erp-brand" href="'.esc_url(home_url('/')).'">Mini ERP</a><h1>'.esc_html($heading).'</h1><p role="alert">'.esc_html($message).'</p><p><a class="button button-primary" href="'.esc_url($url).'">Quay lại màn hình nghiệp vụ</a></p></main></body></html>';
        exit;
    }
    private static function streamVoucher(string $kind,bool $asPdf):void
    {
        if($kind==='payment')CapabilityPolicy::requireCapability('erp_payments_view');else self::requireExpenseReadCapability();
        $actor=self::actor();$idKey=$kind==='payment'?'payment_id':'expense_id';$id=self::queryId($idKey);if($id===null)wp_die('Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.','Mini ERP',['response'=>404]);
        $format=$asPdf?'pdf':'print';check_admin_referer('mini_erp_'.$kind.'_voucher_'.$format.'_'.$id);global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Dịch vụ chứng từ tạm thời không khả dụng.','Mini ERP',['response'=>500]);
        $service=new CashDocumentService($wpdb);try{$voucher=$kind==='payment'?$service->paymentVoucher($actor,$id):$service->expenseVoucher($actor,$id);}catch(DomainException){wp_die('Không tìm thấy chứng từ hoặc bạn không có quyền truy cập.','Mini ERP',['response'=>404]);}catch(Throwable){wp_die('Không thể tải chứng từ. Vui lòng thử lại sau.','Mini ERP',['response'=>500]);}
        $bytes=null;if($asPdf){try{$bytes=$service->pdf($voucher);}catch(Throwable){wp_die('Không thể tạo tệp PDF cho chứng từ này. Vui lòng thử lại sau.','Mini ERP',['response'=>500]);}if(!is_string($bytes)||$bytes===''||!str_starts_with($bytes,'%PDF-'))wp_die('Không thể tạo tệp PDF hợp lệ.','Mini ERP',['response'=>500]);}
        while(ob_get_level()>0)ob_end_clean();nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
        if($asPdf){$number=(string)$voucher['document_no'];$safeNo=preg_match('/\A(?:PAY|EXP)-[0-9]{4}-[0-9]{8}\z/D',$number)?$number:'voucher-'.$id;header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$safeNo.'.pdf"');header('Content-Length: '.strlen((string)$bytes));echo $bytes;exit;}
        header('Content-Type: text/html; charset=UTF-8');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");echo $service->html($voucher);exit;
    }
    private static function requireExpenseReadCapability():void{$actor=CapabilityPolicy::currentActor();if($actor?->can('erp_expenses_manage')){CapabilityPolicy::requireCapability('erp_expenses_manage');return;}if($actor?->can('erp_reports_finance')){CapabilityPolicy::requireCapability('erp_reports_finance');return;}CapabilityPolicy::requireCapability('erp_expenses_manage');}
    private static function expenseReadActor():Actor{$actor=CapabilityPolicy::currentActor();if($actor?->can('erp_expenses_manage')){CapabilityPolicy::requireCapability('erp_expenses_manage');return $actor;}if($actor?->can('erp_reports_finance')){CapabilityPolicy::requireCapability('erp_reports_finance');return $actor;}CapabilityPolicy::requireCapability('erp_expenses_manage');throw new DomainException('Phiên ERP không hợp lệ.');}
    private static function voucherActions(string $kind,int $id):string{$label=$kind==='payment'?'phiếu thu chi':'phiếu chi';$idKey=$kind==='payment'?'payment_id':'expense_id';$print='mini_erp_'.$kind.'_voucher_print';$pdf='mini_erp_'.$kind.'_voucher_pdf';$printUrl=wp_nonce_url(add_query_arg(['action'=>$print,$idKey=>$id],admin_url('admin-post.php')),$print.'_'.$id);$pdfUrl=wp_nonce_url(add_query_arg(['action'=>$pdf,$idKey=>$id],admin_url('admin-post.php')),$pdf.'_'.$id);return '<a class="button button-small" aria-label="In '.$label.'" href="'.esc_url($printUrl).'">In HTML</a> <a class="button button-small" aria-label="Tải PDF '.$label.'" href="'.esc_url($pdfUrl).'">PDF</a> ';}
    private static function pagination(string $route,int $page,int $pages,int $total,string $label,array $filters=[]):void{$base=['page'=>$route,...$filters];echo '<nav class="erp-pagination" aria-label="Phân trang"><span>Trang '.esc_html((string)$page).' / '.esc_html((string)$pages).' · '.esc_html(number_format($total,0,',','.')).' '.$label.'</span>';if($page>1){echo ' <a class="button" href="'.esc_url(add_query_arg([...$base,'paged'=>1],home_url('/'))).'">Đầu</a> <a class="button" rel="prev" href="'.esc_url(add_query_arg([...$base,'paged'=>$page-1],home_url('/'))).'">Trước</a>';}if($page<$pages){echo ' <a class="button" rel="next" href="'.esc_url(add_query_arg([...$base,'paged'=>$page+1],home_url('/'))).'">Sau</a> <a class="button" href="'.esc_url(add_query_arg([...$base,'paged'=>$pages],home_url('/'))).'">Cuối</a>'; }echo '</nav>';}
    private static function results(wpdb $db,string $sql,array $args=[]):array{$rows=$db->get_results($args===[]?$sql:$db->prepare($sql,$args),ARRAY_A);if($db->last_error!==''||!is_array($rows))throw new \RuntimeException('Cash list query failed.');return $rows;}
    private static function pageNumber(mixed $value):int{if(!is_scalar($value))return 1;$value=trim((string)wp_unslash($value));if(!preg_match('/\A[0-9]{1,18}\z/D',$value))return 1;return max(1,(int)$value);}
    private static function voidForm(int $paymentId,string $operation,string $label,bool $needsReason):void{echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="erp-inline-action"><input type="hidden" name="action" value="mini_erp_payment_void"><input type="hidden" name="operation" value="'.esc_attr($operation).'"><input type="hidden" name="payment_id" value="'.esc_attr((string)$paymentId).'">';wp_nonce_field('mini_erp_payment_void_'.$operation);if($needsReason)echo '<input required name="reason" maxlength="1000" aria-label="Lý do"> ';echo '<button class="button">'.esc_html($label).'</button></form> ';}
    private static function actor():Actor{return CapabilityPolicy::currentActor()??throw new DomainException('Phiên ERP không hợp lệ.');}
    private static function id(string $key):?int{$value=$_POST[$key]??null;if(!is_scalar($value)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',trim((string)$value)))return null;$id=(int)$value;return $id>0?$id:null;}
    private static function queryId(string $key):?int{$value=$_GET[$key]??null;if(!is_scalar($value)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',trim((string)wp_unslash($value))))return null;$id=(int)$value;return $id>0?$id:null;}
    private static function text(string $key):string{$value=$_POST[$key]??'';return is_scalar($value)?sanitize_text_field((string)wp_unslash($value)):'';}
    private static function queryText(string $key):string{$value=$_GET[$key]??'';return is_scalar($value)?sanitize_text_field((string)wp_unslash($value)):'';}
    private static function table(wpdb $db,string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$db->prefix)||!in_array($suffix,['erp_cash_accounts','erp_cash_movements','erp_payments','erp_invoices','erp_expenses','erp_shipments','erp_sales_orders'],true))throw new DomainException('Cấu hình bảng không hợp lệ.');return '`'.$db->prefix.$suffix.'`';}
    private static function notice():void{if(!isset($_GET['notice']))return;$notice=sanitize_key((string)$_GET['notice']);$map=['saved'=>'Đã tạo bản nháp. Tài khoản chỉ hiện với người dùng sau khi ERP System Admin gán quyền truy cập.','activated'=>'Tài khoản đã được kích hoạt.','posted'=>'Đã ghi nhận thanh toán.','expense_posted'=>'Đã ghi phiếu chi.','voided'=>'Đã xử lý yêu cầu hủy phiếu thanh toán.','invalid'=>'Không thể xử lý yêu cầu. Kiểm tra dữ liệu rồi thử lại.','conflict'=>'Dữ liệu nghiệp vụ đã thay đổi. Tải lại trang rồi kiểm tra trước khi gửi lại.','unavailable'=>'Dịch vụ tạm thời không khả dụng.'];if(isset($map[$notice]))echo '<div class="notice '.(in_array($notice,['invalid','conflict','unavailable'],true)?'notice-error':'notice-success').'"><p>'.esc_html($map[$notice]).'</p></div>';}
    private static function redirect(string $page,string $notice,?int $id=null,?string $recoverForm=null):never{$args=['page'=>$page,'notice'=>$notice];if($id!==null)$args['record']=$id;if($recoverForm!==null)$args['recover_form']=$recoverForm;wp_safe_redirect(add_query_arg($args,home_url('/')),303);exit;}
    private static function redirectPaymentForm(int $invoiceId,string $notice):never{wp_safe_redirect(add_query_arg(['page'=>'mini-erp-invoices','invoice'=>$invoiceId,'notice'=>$notice,'recover_form'=>'payment'],home_url('/')),303);exit;}
}
