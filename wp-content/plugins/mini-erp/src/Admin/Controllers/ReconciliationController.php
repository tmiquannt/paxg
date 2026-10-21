<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\ReconciliationService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use MiniERP\Security\ReadPolicy;
use Throwable;
use wpdb;

/** Scoped manual cash reconciliation workspace. */
final class ReconciliationController
{
    private const PAGE='mini-erp-reconciliation';
    private const PAGE_SIZE=50;
    private static int $preflightBatchId=0;
    private static int $preflightActorId=0;

    public static function create():void
    {
        CapabilityPolicy::requireCapability('erp_reconciliations_create');check_admin_referer('mini_erp_reconciliation_create');global $wpdb;$account=self::id('cash_account_id');if(!$wpdb instanceof wpdb||$account===null)self::redirect('invalid',null,'reconciliation-create');try{$id=self::service($wpdb)->create(self::actor(),$account,self::text('period_from'),self::text('period_to'),self::text('statement_opening_vnd'),self::text('statement_closing_vnd'),self::text('note'));self::redirect('created',$id,'reconciliation-create');}catch(Throwable){self::redirect('invalid',null,'reconciliation-create');}
    }

    public static function match():void
    {
        CapabilityPolicy::requireCapability('erp_reconciliations_create');check_admin_referer('mini_erp_reconciliation_match');global $wpdb;$batch=self::id('reconciliation_id');$movement=self::id('cash_movement_id');if(!$wpdb instanceof wpdb||$batch===null||$movement===null){if($batch!==null&&$movement!==null)self::redirect('invalid',$batch,'reconciliation-match','match-'.$batch.'-'.$movement);self::redirect('invalid');}try{self::service($wpdb)->match(self::actor(),$batch,$movement,self::text('external_reference'),self::text('external_direction'),self::text('external_amount_vnd'));self::redirect('matched',$batch,'reconciliation-match','match-'.$batch.'-'.$movement);}catch(Throwable){self::redirect('invalid',$batch,'reconciliation-match','match-'.$batch.'-'.$movement);}
    }

    public static function close():void
    {
        CapabilityPolicy::requireCapability('erp_reconciliations_close');check_admin_referer('mini_erp_reconciliation_close');global $wpdb;$id=self::id('reconciliation_id');if(!$wpdb instanceof wpdb||$id===null)self::redirect('invalid');try{self::service($wpdb)->close(self::actor(),$id);self::redirect('closed',$id);}catch(Throwable){self::redirect('invalid',$id);}
    }

    public static function render():void
    {
        CapabilityPolicy::requireCapability('erp_reconciliations_view');$actor=self::actor();$preflightStatus=self::preflightDetail($actor);if($preflightStatus!==null)wp_die($preflightStatus===404?'Không tìm thấy batch hoặc bạn không có quyền truy cập.':'ERP tạm thời không thể xác minh batch đối soát.','',['response'=>$preflightStatus]);global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');$recs=self::table($wpdb,'erp_reconciliations');$accounts=self::table($wpdb,'erp_cash_accounts');$items=self::table($wpdb,'erp_reconciliation_items');$movements=self::table($wpdb,'erp_cash_movements');$scope=$actor->cashAccountIds;
        echo '<div class="wrap erp-app"><h1>Đối soát tài khoản</h1><p>Batch chỉ chốt khi mọi dòng tiền trong kỳ đã được ghép và số dư mở/cuối khớp chính xác với sổ quỹ.</p>';self::notice();
        $page=self::pageNumber($_GET['paged']??1);
        $total=0;
        $pages=1;
        $rows=[];
        if($scope!==[]){
            $slots=implode(',',array_fill(0,count($scope),'%d'));
            $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$recs} WHERE cash_account_id IN ({$slots})",$scope));
            if($wpdb->last_error!==''||!is_numeric($count)){
                wp_die('Không thể tải danh sách đối soát. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
            }
            $total=(int)$count;
            $pages=max(1,(int)ceil($total/self::PAGE_SIZE));
            $page=min($page,$pages);
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT r.*,a.account_code,a.name AS account_name,(SELECT COUNT(*) FROM {$items} ri JOIN {$movements} rm ON rm.id=ri.cash_movement_id WHERE ri.reconciliation_id=r.id AND rm.cash_account_id=r.cash_account_id) AS matched_count FROM {$recs} r JOIN {$accounts} a ON a.id=r.cash_account_id WHERE r.cash_account_id IN ({$slots}) ORDER BY r.period_from DESC,r.id DESC LIMIT %d OFFSET %d",
                [...$scope,self::PAGE_SIZE,($page-1)*self::PAGE_SIZE]
            ),ARRAY_A);
            if($wpdb->last_error!==''||!is_array($rows)){
                wp_die('Không thể tải danh sách đối soát. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
            }
        }
        echo '<div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Mã batch</th><th>Tài khoản</th><th>Kỳ</th><th>Sao kê mở / cuối</th><th>Đã ghép</th><th>Trạng thái</th><th></th></tr></thead><tbody>';
        foreach($rows as $row){$url=add_query_arg(['page'=>self::PAGE,'batch'=>(int)$row['id'],'paged'=>$page],home_url('/'));echo '<tr><td>'.esc_html((string)$row['rec_no']).'</td><td>'.esc_html((string)$row['account_code'].' · '.(string)$row['account_name']).'</td><td>'.esc_html((string)$row['period_from'].' — '.(string)$row['period_to']).'</td><td>'.esc_html(number_format((int)$row['statement_opening_vnd'],0,',','.')).' / '.esc_html(number_format((int)$row['statement_closing_vnd'],0,',','.')).' ₫</td><td>'.esc_html((string)$row['matched_count']).'</td><td>'.esc_html((string)$row['status']).'</td><td><a class="button" href="'.esc_url($url).'">Mở</a></td></tr>';}
        if(!is_array($rows)||$rows===[])echo '<tr><td colspan="7">Chưa có batch đối soát.</td></tr>';echo '</tbody></table></div>';
        $batchId=isset($_GET['batch'])&&is_scalar($_GET['batch'])?absint($_GET['batch']):0;
        self::pagination($page,$pages,$total,$batchId);
        if($batchId>0)self::detail($wpdb,$actor,$batchId,$recs,$accounts,$items,$movements,$page);
        if($actor->can('erp_reconciliations_create'))self::createForm($wpdb,$actor,$accounts);
        echo '</div>';
    }

    /** Check account scope before the frontend shell is emitted for a batch detail. */
    public static function preflightDetail(Actor $actor):?int
    {
        $id=isset($_GET['batch'])&&is_scalar($_GET['batch'])?absint($_GET['batch']):0;
        if($id<1){self::$preflightBatchId=0;self::$preflightActorId=0;return null;}
        if(self::$preflightBatchId===$id&&self::$preflightActorId===$actor->id)return null;
        if($actor->cashAccountIds===[])return 404;
        global $wpdb;
        if(!$wpdb instanceof wpdb)return 503;
        $recs=self::table($wpdb,'erp_reconciliations');$slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));
        try{$row=$wpdb->get_row($wpdb->prepare("SELECT id,cash_account_id FROM {$recs} WHERE id=%d AND cash_account_id IN ({$slots})",[$id,...$actor->cashAccountIds]),ARRAY_A);}catch(Throwable){return 503;}
        if($wpdb->last_error!=='')return 503;
        if(!is_array($row)||(new ReadPolicy())->canReadCashDetail($actor,$row,'erp_reconciliations_view')!==true)return 404;
        self::$preflightBatchId=$id;self::$preflightActorId=$actor->id;
        return null;
    }

    private static function detail(wpdb $db,Actor $actor,int $id,string $recs,string $accounts,string $items,string $movements,int $listPage):void
    {
        if($actor->cashAccountIds===[]){echo '<div class="notice notice-error"><p>Không tìm thấy batch hoặc bạn không có quyền truy cập.</p></div>';return;}$slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));$row=$db->get_row($db->prepare("SELECT r.*,a.account_code,a.name AS account_name FROM {$recs} r JOIN {$accounts} a ON a.id=r.cash_account_id WHERE r.id=%d AND r.cash_account_id IN ({$slots})",[$id,...$actor->cashAccountIds]),ARRAY_A);if(!is_array($row)){echo '<div class="notice notice-error"><p>Không tìm thấy batch hoặc bạn không có quyền truy cập.</p></div>';return;}
        $from=(new \DateTimeImmutable((string)$row['period_from'].' 00:00:00',new \DateTimeZone('Asia/Ho_Chi_Minh')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');$to=(new \DateTimeImmutable((string)$row['period_to'],new \DateTimeZone('Asia/Ho_Chi_Minh')))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $preview=$db->get_row($db->prepare(
            "SELECT (SELECT opening_balance_vnd FROM {$accounts} WHERE id=%d) AS account_opening,COALESCE((SELECT SUM(CASE WHEN m.direction='IN' THEN m.amount_vnd ELSE -m.amount_vnd END) FROM {$movements} m WHERE m.cash_account_id=%d AND m.occurred_at<%s),0) AS prior_flow,COALESCE((SELECT SUM(CASE WHEN m.direction='IN' THEN m.amount_vnd ELSE -m.amount_vnd END) FROM {$movements} m WHERE m.cash_account_id=%d AND m.occurred_at>=%s AND m.occurred_at<%s),0) AS period_flow,(SELECT COUNT(*) FROM {$movements} m LEFT JOIN {$items} ri ON ri.cash_movement_id=m.id AND ri.reconciliation_id=%d WHERE m.cash_account_id=%d AND m.occurred_at>=%s AND m.occurred_at<%s AND ri.id IS NULL) AS unmatched_count",
            [(int)$row['cash_account_id'],(int)$row['cash_account_id'],$from,(int)$row['cash_account_id'],$from,$to,$id,(int)$row['cash_account_id'],$from,$to]
        ),ARRAY_A);
        if($db->last_error!==''||!is_array($preview)){
            wp_die('Không thể tính số liệu xem trước đối soát. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
        }
        $erpOpening=bcadd((string)$preview['account_opening'],(string)$preview['prior_flow'],0);
        $erpClosing=bcadd($erpOpening,(string)$preview['period_flow'],0);
        $openingDelta=bcsub((string)$row['statement_opening_vnd'],$erpOpening,0);
        $closingDelta=bcsub((string)$row['statement_closing_vnd'],$erpClosing,0);
        $unmatchedCount=(int)$preview['unmatched_count'];
        $balancesMatch=bccomp($openingDelta,'0',0)===0&&bccomp($closingDelta,'0',0)===0;
        $canClose=$row['status']==='DRAFT'&&$actor->can('erp_reconciliations_close')&&(int)$row['created_by']!==$actor->id&&$unmatchedCount===0&&$balancesMatch;
        echo '<section class="erp-invoice-card"><h2>'.esc_html((string)$row['rec_no']).' · '.esc_html((string)$row['account_code'].' '.$row['account_name']).'</h2><p>Kỳ '.esc_html((string)$row['period_from'].' — '.(string)$row['period_to']).' · Trạng thái '.esc_html((string)$row['status']).'</p><p>Số dư sao kê đầu/cuối: '.esc_html(number_format((int)$row['statement_opening_vnd'],0,',','.')).' / '.esc_html(number_format((int)$row['statement_closing_vnd'],0,',','.')).' ₫</p><p>Số dư sổ đầu/cuối hiện tại: '.esc_html(number_format((int)$erpOpening,0,',','.')).' / '.esc_html(number_format((int)$erpClosing,0,',','.')).' ₫ · Chênh lệch sao kê − sổ: '.esc_html(number_format((int)$openingDelta,0,',','.')).' / '.esc_html(number_format((int)$closingDelta,0,',','.')).' ₫ · Còn '.esc_html((string)$unmatchedCount).' dòng chưa ghép.</p><p><em>Số liệu này là bản xem trước; khi chốt, hệ thống kiểm tra lại trong transaction.</em></p><h3>Dòng tiền đã ghép</h3>';
        $matchedPage=self::pageNumber($_GET['matched_page']??1);
        $matchedWhere='ri.reconciliation_id=%d AND m.cash_account_id=%d AND m.occurred_at>=%s AND m.occurred_at<%s';
        $matchedArgs=[$id,(int)$row['cash_account_id'],$from,$to];
        $matchedCount=$db->get_var($db->prepare(
            "SELECT COUNT(*) FROM {$items} ri JOIN {$movements} m ON m.id=ri.cash_movement_id WHERE {$matchedWhere}",
            $matchedArgs
        ));
        if($db->last_error!==''||!is_numeric($matchedCount)){
            wp_die('Không thể tải lịch sử dòng tiền đã ghép. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
        }
        $matchedTotal=(int)$matchedCount;
        $matchedPages=max(1,(int)ceil($matchedTotal/self::PAGE_SIZE));
        $matchedPage=min($matchedPage,$matchedPages);
        $matchedRows=$db->get_results($db->prepare(
            "SELECT ri.id,ri.external_reference,ri.external_amount_vnd,ri.matched_by,ri.matched_at,m.occurred_at,m.source_type,m.source_id,m.direction,m.amount_vnd FROM {$items} ri JOIN {$movements} m ON m.id=ri.cash_movement_id WHERE {$matchedWhere} ORDER BY ri.matched_at DESC,ri.id DESC LIMIT %d OFFSET %d",
            [...$matchedArgs,self::PAGE_SIZE,($matchedPage-1)*self::PAGE_SIZE]
        ),ARRAY_A);
        if($db->last_error!==''||!is_array($matchedRows)){
            wp_die('Không thể tải lịch sử dòng tiền đã ghép. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
        }
        echo '<div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Ngày ghi sổ (giờ Việt Nam)</th><th>Nguồn</th><th>Chiều</th><th>Số tiền sổ</th><th>Mã sao kê</th><th>Số tiền sao kê</th><th>Ghép lúc (giờ Việt Nam)</th><th>Người ghép</th></tr></thead><tbody>';
        foreach($matchedRows as $matched){
            echo '<tr><td>'.esc_html(self::localDateTime((string)$matched['occurred_at'])).'</td><td>'.esc_html((string)$matched['source_type'].' #'.(string)$matched['source_id']).'</td><td>'.esc_html((string)$matched['direction']).'</td><td>'.esc_html(number_format((int)$matched['amount_vnd'],0,',','.')).' ₫</td><td>'.esc_html((string)($matched['external_reference']??'')).'</td><td>'.esc_html(number_format((int)$matched['external_amount_vnd'],0,',','.')).' ₫</td><td>'.esc_html(self::localDateTime((string)$matched['matched_at'])).'</td><td>#'.esc_html((string)$matched['matched_by']).'</td></tr>';
        }
        if($matchedRows===[])echo '<tr><td colspan="8">Chưa có dòng tiền được ghép.</td></tr>';
        echo '</tbody></table></div>';
        self::matchedPagination($id,$listPage,$matchedPage,$matchedPages,$matchedTotal,self::pageNumber($_GET['movement_page']??1));
        echo '<h3>Dòng tiền chưa ghép</h3>';
        $movementPage=self::pageNumber($_GET['movement_page']??1);
        $unmatchedJoin="LEFT JOIN {$items} ri ON ri.cash_movement_id=m.id AND ri.reconciliation_id=%d";
        $unmatchedWhere="m.cash_account_id=%d AND m.occurred_at>=%s AND m.occurred_at<%s AND ri.id IS NULL";
        $unmatchedArgs=[$id,(int)$row['cash_account_id'],$from,$to];
        $count=$db->get_var($db->prepare(
            "SELECT COUNT(*) FROM {$movements} m {$unmatchedJoin} WHERE {$unmatchedWhere}",
            $unmatchedArgs
        ));
        if($db->last_error!==''||!is_numeric($count)){
            wp_die('Không thể tải các dòng tiền chưa ghép. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
        }
        $unmatchedTotal=(int)$count;
        $movementPages=max(1,(int)ceil($unmatchedTotal/self::PAGE_SIZE));
        $movementPage=min($movementPage,$movementPages);
        $unmatched=$db->get_results($db->prepare(
            "SELECT m.id,m.direction,m.amount_vnd,m.occurred_at,m.source_type,m.source_id FROM {$movements} m {$unmatchedJoin} WHERE {$unmatchedWhere} ORDER BY m.occurred_at,m.id LIMIT %d OFFSET %d",
            [...$unmatchedArgs,self::PAGE_SIZE,($movementPage-1)*self::PAGE_SIZE]
        ),ARRAY_A);
        if($db->last_error!==''||!is_array($unmatched)){
            wp_die('Không thể tải các dòng tiền chưa ghép. Vui lòng thử lại sau.','Mini ERP',['response'=>503]);
        }
        echo '<div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Ngày ghi sổ (giờ Việt Nam)</th><th>Nguồn</th><th>Chiều</th><th>Số tiền</th><th></th></tr></thead><tbody>';
        foreach(is_array($unmatched)?$unmatched:[] as $movement){echo '<tr><td>'.esc_html(self::localDateTime((string)$movement['occurred_at'])).'</td><td>'.esc_html((string)$movement['source_type'].' #'.(string)$movement['source_id']).'</td><td>'.esc_html((string)$movement['direction']).'</td><td>'.esc_html(number_format((int)$movement['amount_vnd'],0,',','.')).' ₫</td><td>';
            if($row['status']==='DRAFT'&&$actor->can('erp_reconciliations_create')){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-finance-form="reconciliation-match" data-finance-actor="'.esc_attr((string)$actor->id).'" data-finance-scope="match-'.esc_attr((string)$id).'-'.esc_attr((string)$movement['id']).'"><input type="hidden" name="action" value="mini_erp_reconciliation_match">';wp_nonce_field('mini_erp_reconciliation_match');echo '<input type="hidden" name="reconciliation_id" value="'.esc_attr((string)$id).'"><input type="hidden" name="cash_movement_id" value="'.esc_attr((string)$movement['id']).'"><input type="hidden" name="external_amount_vnd" value="'.esc_attr((string)$movement['amount_vnd']).'"><label>Mã sao kê <input name="external_reference" maxlength="128"></label> <label>Chiều sao kê <select name="external_direction"><option value="IN"'.selected((string)$movement['direction'],'IN',false).'>Tiền vào</option><option value="OUT"'.selected((string)$movement['direction'],'OUT',false).'>Tiền ra</option></select></label> <button class="button">Ghép</button></form>';}
            echo '</td></tr>';}
        if($unmatched===[])echo '<tr><td colspan="5">Không còn dòng tiền chưa ghép trong kỳ.</td></tr>';
        echo '</tbody></table></div>';
        self::movementPagination($id,$listPage,$movementPage,$movementPages,$unmatchedTotal,$matchedPage);
        if($row['status']==='DRAFT'&&$actor->can('erp_reconciliations_close')){
            if((int)$row['created_by']===$actor->id){
                echo '<p class="notice notice-warning inline">Người tạo batch không được tự chốt.</p>';
            }elseif($canClose){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mini_erp_reconciliation_close">';wp_nonce_field('mini_erp_reconciliation_close');echo '<input type="hidden" name="reconciliation_id" value="'.esc_attr((string)$id).'"><button class="button button-primary">Kiểm tra và chốt kỳ</button></form>';
            }else{
                echo '<p class="notice notice-warning inline">Chưa đủ điều kiện chốt: cần ghép hết giao dịch và số dư sao kê phải khớp chính xác với sổ.</p>';
            }
        }
        echo '</section>';
    }

    private static function createForm(wpdb $db,Actor $actor,string $accounts):void
    {
        if($actor->cashAccountIds===[])return;$slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));$rows=$db->get_results($db->prepare("SELECT id,account_code,name FROM {$accounts} WHERE id IN ({$slots}) AND status='ACTIVE' ORDER BY account_code",$actor->cashAccountIds),ARRAY_A);if(!is_array($rows)||$rows===[])return;
        echo '<h2>Mở kỳ đối soát</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-finance-form="reconciliation-create" data-finance-actor="'.esc_attr((string)$actor->id).'" data-finance-scope="create"><input type="hidden" name="action" value="mini_erp_reconciliation_create">';wp_nonce_field('mini_erp_reconciliation_create');echo '<p><label>Tài khoản <select name="cash_account_id">';foreach($rows as $account)echo '<option value="'.esc_attr((string)$account['id']).'">'.esc_html($account['account_code'].' · '.$account['name']).'</option>';echo '</select></label> <label>Từ <input required type="date" name="period_from"></label> <label>Đến <input required type="date" name="period_to"></label></p><p><label>Số dư sao kê đầu kỳ <input required name="statement_opening_vnd" inputmode="numeric" pattern="[0-9]+"></label> <label>Số dư sao kê cuối kỳ <input required name="statement_closing_vnd" inputmode="numeric" pattern="[0-9]+"></label> <label>Ghi chú <input name="note" maxlength="1000"></label> <button class="button button-primary">Tạo batch nháp</button></p></form>';
    }

    private static function service(wpdb $db):ReconciliationService{return new ReconciliationService($db,new TransactionManager($db),new AuditLogger());}
    private static function actor():Actor{return CapabilityPolicy::currentActor()??throw new DomainException('Phiên ERP không hợp lệ.');}
    private static function localDateTime(string $utc):string{return (new \DateTimeImmutable($utc,new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d H:i:s');}
    private static function pageNumber(mixed $value):int
    {
        if(!is_scalar($value))return 1;
        $value=trim((string)wp_unslash($value));
        if($value===''||strlen($value)>18||!ctype_digit($value))return 1;
        return max(1,min(10000,(int)$value));
    }

    private static function pagination(int $page,int $pages,int $total,int $batch):void
    {
        echo '<nav class="erp-pagination" aria-label="Phân trang batch đối soát"><span>Trang '.esc_html((string)$page).' / '.esc_html((string)$pages).' · '.esc_html(number_format($total,0,',','.')).' batch</span>';
        if($page>1){
            $first=['page'=>self::PAGE,'paged'=>1];
            $previous=['page'=>self::PAGE,'paged'=>$page-1];
            if($batch>0){$first['batch']=$batch;$previous['batch']=$batch;}
            echo ' <a class="button" href="'.esc_url(add_query_arg($first,home_url('/'))).'">Đầu</a> <a class="button" rel="prev" href="'.esc_url(add_query_arg($previous,home_url('/'))).'">Trước</a>';
        }
        if($page<$pages){
            $next=['page'=>self::PAGE,'paged'=>$page+1];
            $last=['page'=>self::PAGE,'paged'=>$pages];
            if($batch>0){$next['batch']=$batch;$last['batch']=$batch;}
            echo ' <a class="button" rel="next" href="'.esc_url(add_query_arg($next,home_url('/'))).'">Sau</a> <a class="button" href="'.esc_url(add_query_arg($last,home_url('/'))).'">Cuối</a>';
        }
        echo '</nav>';
    }

    private static function movementPagination(int $batch,int $listPage,int $page,int $pages,int $total,int $matchedPage):void
    {
        echo '<nav class="erp-pagination" aria-label="Phân trang dòng tiền chưa ghép"><span>Trang '.esc_html((string)$page).' / '.esc_html((string)$pages).' · '.esc_html(number_format($total,0,',','.')).' dòng</span>';
        if($page>1){
            $first=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'movement_page'=>1,'matched_page'=>$matchedPage];
            $previous=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'movement_page'=>$page-1,'matched_page'=>$matchedPage];
            echo ' <a class="button" href="'.esc_url(add_query_arg($first,home_url('/'))).'">Đầu</a> <a class="button" rel="prev" href="'.esc_url(add_query_arg($previous,home_url('/'))).'">Trước</a>';
        }
        if($page<$pages){
            $next=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'movement_page'=>$page+1,'matched_page'=>$matchedPage];
            $last=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'movement_page'=>$pages,'matched_page'=>$matchedPage];
            echo ' <a class="button" rel="next" href="'.esc_url(add_query_arg($next,home_url('/'))).'">Sau</a> <a class="button" href="'.esc_url(add_query_arg($last,home_url('/'))).'">Cuối</a>';
        }
        echo '</nav>';
    }

    private static function matchedPagination(int $batch,int $listPage,int $page,int $pages,int $total,int $movementPage):void
    {
        echo '<nav class="erp-pagination" aria-label="Phân trang dòng tiền đã ghép"><span>Trang '.esc_html((string)$page).' / '.esc_html((string)$pages).' · '.esc_html(number_format($total,0,',','.')).' dòng</span>';
        if($page>1){
            $first=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'matched_page'=>1,'movement_page'=>$movementPage];
            $previous=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'matched_page'=>$page-1,'movement_page'=>$movementPage];
            echo ' <a class="button" href="'.esc_url(add_query_arg($first,home_url('/'))).'">Đầu</a> <a class="button" rel="prev" href="'.esc_url(add_query_arg($previous,home_url('/'))).'">Trước</a>';
        }
        if($page<$pages){
            $next=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'matched_page'=>$page+1,'movement_page'=>$movementPage];
            $last=['page'=>self::PAGE,'batch'=>$batch,'paged'=>$listPage,'matched_page'=>$pages,'movement_page'=>$movementPage];
            echo ' <a class="button" rel="next" href="'.esc_url(add_query_arg($next,home_url('/'))).'">Sau</a> <a class="button" href="'.esc_url(add_query_arg($last,home_url('/'))).'">Cuối</a>';
        }
        echo '</nav>';
    }
    private static function id(string $key):?int{$value=$_POST[$key]??null;if(!is_scalar($value)||!preg_match('/\A[1-9][0-9]{0,17}\z/D',trim((string)$value)))return null;$id=(int)$value;return $id>0?$id:null;}
    private static function text(string $key):string{$value=$_POST[$key]??'';return is_scalar($value)?sanitize_text_field((string)wp_unslash($value)):'';}
    private static function table(wpdb $db,string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$db->prefix)||!in_array($suffix,['erp_reconciliations','erp_reconciliation_items','erp_cash_accounts','erp_cash_movements'],true))throw new DomainException('Cấu hình bảng không hợp lệ.');return '`'.$db->prefix.$suffix.'`';}
    private static function notice():void{if(!isset($_GET['notice']))return;$notice=sanitize_key((string)$_GET['notice']);$map=['created'=>'Đã mở batch đối soát.','matched'=>'Đã ghép dòng tiền.','closed'=>'Đã chốt đối soát.','invalid'=>'Không thể xử lý yêu cầu; kiểm tra quyền, số tiền, kỳ và trạng thái.'];if(isset($map[$notice]))echo '<div class="notice '.($notice==='invalid'?'notice-error':'notice-success').'"><p>'.esc_html($map[$notice]).'</p></div>';}
    private static function redirect(string $notice,?int $id=null,?string $recoverForm=null,?string $recoverScope=null):never{$args=['page'=>self::PAGE,'notice'=>$notice];if($id!==null)$args['batch']=$id;if($recoverForm!==null)$args['recover_form']=$recoverForm;if($recoverScope!==null)$args['recover_scope']=$recoverScope;wp_safe_redirect(add_query_arg($args,home_url('/')),303);exit;}
}
