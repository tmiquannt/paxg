<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\InventoryCountInput;
use MiniERP\Application\Services\InventoryAdjustmentService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use Throwable;
use wpdb;

/** Role and warehouse scoped inventory and adjustment screens. */
final class InventoryController
{
    private static int $preflightAdjustmentId=0;
    private static int $preflightActorId=0;

    public static function startDraft():void
    {
        CapabilityPolicy::requireCapability('erp_stock_adjust_request');check_admin_referer('mini_erp_inventory_start');global $wpdb;if(!$wpdb instanceof wpdb)self::redirect('unavailable',null,'inventory-start','start');
        $warehouseId=self::postId('warehouse_id');$reason=sanitize_textarea_field(self::postText('reason'));if($warehouseId===null)self::redirect('invalid',null,'inventory-start','start');
        try{$id=self::service($wpdb)->startDraft(self::actor(),$warehouseId,$reason);self::redirect('created',$id,'inventory-start','start');}catch(Throwable $e){self::redirect($e instanceof DomainException?'invalid':'unavailable',null,'inventory-start','start');}
    }

    public static function saveDraft():void
    {
        CapabilityPolicy::requireCapability('erp_stock_adjust_request');check_admin_referer('mini_erp_inventory_count');global $wpdb;if(!$wpdb instanceof wpdb)self::redirect('unavailable');$id=self::postId('adjustment_id');$raw=$_POST['counts']??null;if($id===null||!is_array($raw))self::redirect('invalid');$counts=InventoryCountInput::fromForm(wp_unslash($raw));if($counts===null)self::redirect('invalid',$id,'inventory-count','adjustment-'.$id);
        $submit=self::postText('decision')==='request';try{$status=self::service($wpdb)->saveDraft(self::actor(),$id,$counts,$submit);self::redirect($status==='REQUESTED'?'requested':'saved',$id,'inventory-count','adjustment-'.$id);}catch(Throwable $e){self::redirect($e instanceof DomainException?'invalid':'unavailable',$id,'inventory-count','adjustment-'.$id);}
    }

    public static function request():void
    {
        CapabilityPolicy::requireCapability('erp_stock_adjust_request');check_admin_referer('mini_erp_inventory_request');global $wpdb;if(!$wpdb instanceof wpdb)self::redirect('unavailable');$id=self::postId('adjustment_id');if($id===null)self::redirect('invalid');try{self::service($wpdb)->request(self::actor(),$id);self::redirect('requested',$id);}catch(Throwable $e){self::redirect($e instanceof DomainException?'invalid':'unavailable',$id);}
    }

    public static function decide():void
    {
        CapabilityPolicy::requireCapability('erp_stock_adjust_approve');check_admin_referer('mini_erp_inventory_decide');global $wpdb;if(!$wpdb instanceof wpdb)self::redirect('unavailable');$id=self::postId('adjustment_id');$decision=self::postText('decision');if($id===null)self::redirect('invalid');try{$next=self::service($wpdb)->decide(self::actor(),$id,$decision);self::redirect($next==='APPROVED'?'approved':'rejected',$id);}catch(Throwable $e){self::redirect($e instanceof DomainException?'invalid':'unavailable',$id);}
    }

    public static function apply():void
    {
        CapabilityPolicy::requireCapability('erp_stock_adjust_apply');check_admin_referer('mini_erp_inventory_apply');global $wpdb;if(!$wpdb instanceof wpdb)self::redirect('unavailable');$id=self::postId('adjustment_id');$key=sanitize_text_field(self::postText('request_key'));if($id===null)self::redirect('invalid');try{$result=self::service($wpdb)->apply(self::actor(),$id,$key);self::redirect($result['status']==='STALE'?'stale':'applied',$id,'inventory-apply','adjustment-'.$id);}catch(Throwable $e){self::redirect($e instanceof DomainException?'invalid':'unavailable',$id,'inventory-apply','adjustment-'.$id);}
    }

    public static function render():void
    {
        CapabilityPolicy::requireCapability('erp_stock_view');$actor=self::actor();$preflightStatus=self::preflightDetail($actor);if($preflightStatus!==null)wp_die($preflightStatus===404?'Không tìm thấy phiếu kiểm kê hoặc bạn không có quyền truy cập.':'ERP tạm thời không thể xác minh phiếu kiểm kê.','',['response'=>$preflightStatus]);global $wpdb;if(!$wpdb instanceof wpdb)wp_die(esc_html__('Cơ sở dữ liệu ERP chưa sẵn sàng.','mini-erp'),'', ['response'=>503]);
        $balances=self::table($wpdb,'erp_stock_balances');$products=self::table($wpdb,'erp_products');$warehouses=self::table($wpdb,'erp_warehouses');$adjustments=self::table($wpdb,'erp_stock_adjustments');$items=self::table($wpdb,'erp_stock_adjustment_items');
        $manager=$actor->can('erp_stock_adjust_approve');$scopedIds=$manager?[]:$actor->warehouseIds;$warehouseFilter='';$args=[];if(!$manager){$warehouseFilter=$scopedIds===[]?' AND 1=0':' AND w.id IN ('.implode(',',array_fill(0,count($scopedIds),'%d')).')';array_push($args,...$scopedIds);}
        $warehouseSql="SELECT w.id,w.code,w.name FROM {$warehouses} w WHERE w.is_active=1".($manager?'':($scopedIds===[]?' AND 1=0':' AND w.id IN ('.implode(',',array_fill(0,count($scopedIds),'%d')).')'))." ORDER BY w.name,w.id";$warehouseRows=self::results($wpdb,$warehouseSql,$manager?[]:$scopedIds);
        $selectedWarehouse=absint($_GET['warehouse_id']??0);$availableIds=array_map(static fn($r)=>(int)$r['id'],$warehouseRows);if($selectedWarehouse>0&&!in_array($selectedWarehouse,$availableIds,true))$selectedWarehouse=0;
        $stockArgs=$args;$stockWhere=$warehouseFilter;if($selectedWarehouse>0){$stockWhere.=' AND w.id=%d';$stockArgs[]=$selectedWarehouse;}
        $stockRows=self::results($wpdb,"SELECT w.code AS warehouse_code,w.name AS warehouse_name,p.sku,p.name AS product_name,p.unit,b.quantity,b.updated_at FROM {$balances} b JOIN {$products} p ON p.id=b.product_id JOIN {$warehouses} w ON w.id=b.warehouse_id WHERE 1=1{$stockWhere} ORDER BY w.name,p.sku LIMIT 250",$stockArgs);
        $adjustmentId=absint($_GET['adjustment']??0);$adjustArgs=$manager?[]:$scopedIds;if($adjustmentId>0)$adjustArgs[]=$adjustmentId;
        $adjustRows=self::results($wpdb,"SELECT a.id,a.adjustment_no,a.warehouse_id,a.status,a.requested_by,a.approved_by,a.applied_by,a.reason,a.created_at,a.requested_at,a.approved_at,a.applied_at,w.name AS warehouse_name FROM {$adjustments} a JOIN {$warehouses} w ON w.id=a.warehouse_id WHERE 1=1".($manager?'':($scopedIds===[]?' AND 1=0':' AND a.warehouse_id IN ('.implode(',',array_fill(0,count($scopedIds),'%d')).')')).($adjustmentId>0?' AND a.id=%d':'')." ORDER BY a.id DESC LIMIT 100",$adjustArgs);
        $notice=sanitize_key(self::getText('notice'));$messages=['created'=>'Đã bắt đầu kiểm kê và chụp số tồn tại thời điểm bắt đầu.','saved'=>'Đã lưu số đếm.','requested'=>'Đã gửi kiểm kê chờ Manager duyệt.','approved'=>'Manager đã duyệt kiểm kê.','rejected'=>'Manager đã từ chối kiểm kê.','applied'=>'Đã áp dụng chênh lệch vào tồn kho.','stale'=>'Tồn kho đã thay đổi; bản kiểm kê chuyển sang STALE và không phát sinh giao dịch.','invalid'=>'Không thể hoàn thành thao tác. Kiểm tra trạng thái, quyền và dữ liệu rồi thử lại.','unavailable'=>'Dịch vụ kiểm kê tạm thời không khả dụng.'];
        echo '<div class="wrap erp-app"><h1>Kho hàng và kiểm kê</h1><p>Số tồn được cập nhật bởi chứng từ đã ghi sổ. Kiểm kê chỉ điều chỉnh chênh lệch; không dùng để nhập số dư đầu kỳ.</p>';
        if(isset($messages[$notice]))echo '<div class="notice '.(in_array($notice,['created','saved','requested','approved','rejected','applied'],true)?'notice-success':'notice-error').' is-dismissible"><p>'.esc_html($messages[$notice]).'</p></div>';
        echo '<h2>Tồn kho</h2><form method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="page" value="mini-erp-inventory"><label for="erp-stock-warehouse">Kho</label> <select id="erp-stock-warehouse" name="warehouse_id"><option value="0">Tất cả kho được cấp</option>';foreach($warehouseRows as $w)echo '<option value="'.(int)$w['id'].'" '.selected($selectedWarehouse,(int)$w['id'],false).'>'.esc_html((string)$w['code'].' — '.(string)$w['name']).'</option>';echo '</select> <button class="button">Lọc</button></form>';
        echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Kho</th><th>SKU</th><th>Sản phẩm</th><th>Đơn vị</th><th>Tồn</th><th>Cập nhật UTC</th></tr></thead><tbody>';foreach($stockRows as $row)echo '<tr><td>'.esc_html((string)$row['warehouse_code'].' — '.(string)$row['warehouse_name']).'</td><td>'.esc_html((string)$row['sku']).'</td><td>'.esc_html((string)$row['product_name']).'</td><td>'.esc_html((string)$row['unit']).'</td><td>'.esc_html((string)$row['quantity']).'</td><td>'.esc_html((string)$row['updated_at']).'</td></tr>';if($stockRows===[])echo '<tr><td colspan="6">Chưa có số dư tồn kho phù hợp.</td></tr>';echo '</tbody></table></div>';
        if($actor->can('erp_stock_adjust_request')&&$warehouseRows!==[]){echo '<h2>Bắt đầu kiểm kê</h2><p>Hệ thống chụp số tồn hiện tại làm mốc. Sau khi kiểm đếm, mở bản nháp để nhập số thực tế.</p><form method="post" data-inventory-form="inventory-start" data-inventory-actor="'.esc_attr((string)$actor->id).'" data-inventory-scope="start" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mini_erp_inventory_start');echo '<input type="hidden" name="action" value="mini_erp_inventory_start"><label for="erp-adjust-warehouse">Kho</label> <select id="erp-adjust-warehouse" name="warehouse_id" required>';foreach($warehouseRows as $w)echo '<option value="'.(int)$w['id'].'">'.esc_html((string)$w['code'].' — '.(string)$w['name']).'</option>';echo '</select> <label for="erp-adjust-reason">Lý do</label> <input id="erp-adjust-reason" name="reason" type="text" maxlength="1000" required> <button class="button button-primary">Tạo bản kiểm kê</button></form>';}
        echo '<h2>Kiểm kê và điều chỉnh</h2><div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Mã</th><th>Kho</th><th>Trạng thái</th><th>Lý do</th><th>Người tạo</th><th>Thao tác</th></tr></thead><tbody>';foreach($adjustRows as $row){$url=add_query_arg(['page'=>'mini-erp-inventory','adjustment'=>(int)$row['id']],home_url('/'));echo '<tr><td><a href="'.esc_url($url).'">'.esc_html((string)$row['adjustment_no']).'</a></td><td>'.esc_html((string)$row['warehouse_name']).'</td><td>'.esc_html((string)$row['status']).'</td><td>'.esc_html((string)$row['reason']).'</td><td>'.(int)$row['requested_by'].'</td><td><a class="button" href="'.esc_url($url).'">Mở</a></td></tr>';}if($adjustRows===[])echo '<tr><td colspan="6">Chưa có bản kiểm kê.</td></tr>';echo '</tbody></table></div>';
        if($adjustmentId>0&&$adjustRows!==[])self::renderDetail($wpdb,$actor,$adjustRows[0],$items);
        echo '</div>';
    }

    /** Check warehouse scope before the frontend shell is emitted for a count detail. */
    public static function preflightDetail(Actor $actor):?int
    {
        $id=absint($_GET['adjustment']??0);
        if($id<1){self::$preflightAdjustmentId=0;self::$preflightActorId=0;return null;}
        if(self::$preflightAdjustmentId===$id&&self::$preflightActorId===$actor->id)return null;
        global $wpdb;
        if(!$wpdb instanceof wpdb)return 503;
        $adjustments=self::table($wpdb,'erp_stock_adjustments');$where=['id=%d'];$args=[$id];
        if(!$actor->can('erp_stock_adjust_approve')){
            if($actor->warehouseIds===[])return 404;
            $where[]='warehouse_id IN ('.implode(',',array_fill(0,count($actor->warehouseIds),'%d')).')';
            array_push($args,...$actor->warehouseIds);
        }
        try{$found=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$adjustments} WHERE ".implode(' AND ',$where),$args));}catch(Throwable){return 503;}
        if($wpdb->last_error!=='')return 503;
        if((int)$found!==$id)return 404;
        self::$preflightAdjustmentId=$id;self::$preflightActorId=$actor->id;
        return null;
    }

    private static function renderDetail(wpdb $db,Actor $actor,array $adjustment,string $itemsTable):void
    {
        $id=(int)$adjustment['id'];$items=$db->get_results($db->prepare("SELECT product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_step_snapshot,expected_qty,counted_qty,delta_qty FROM {$itemsTable} WHERE adjustment_id=%d ORDER BY sku_snapshot,product_id",$id),ARRAY_A);if(!is_array($items))$items=[];
        echo '<section class="erp-card erp-section"><h2>'.esc_html((string)$adjustment['adjustment_no']).' · '.esc_html((string)$adjustment['status']).'</h2><p>'.esc_html((string)$adjustment['warehouse_name'].' · '.(string)$adjustment['reason']).'</p>';
        $canEdit=$actor->can('erp_stock_adjust_request')&&(int)$adjustment['requested_by']===$actor->id&&(string)$adjustment['status']==='DRAFT';if($canEdit){echo '<form method="post" data-inventory-form="inventory-count" data-inventory-actor="'.esc_attr((string)$actor->id).'" data-inventory-scope="adjustment-'.$id.'" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mini_erp_inventory_count');echo '<input type="hidden" name="action" value="mini_erp_inventory_count"><input type="hidden" name="adjustment_id" value="'.$id.'">';}
        echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>SKU</th><th>Sản phẩm</th><th>ĐVT</th><th>Tồn lúc bắt đầu</th><th>Số đếm</th><th>Chênh lệch</th></tr></thead><tbody>';foreach($items as $line){$pid=(int)$line['product_id'];echo '<tr><td>'.esc_html((string)$line['sku_snapshot']).'</td><td>'.esc_html((string)$line['name_snapshot']).'</td><td>'.esc_html((string)$line['unit_snapshot']).'</td><td>'.esc_html((string)$line['expected_qty']).'</td><td>';if($canEdit)echo '<input type="number" min="0" step="'.esc_attr((string)$line['unit_step_snapshot']).'" name="counts['.$pid.']" value="'.esc_attr((string)$line['counted_qty']).'" required>';else echo esc_html((string)$line['counted_qty']);echo '</td><td>'.esc_html((string)$line['delta_qty']).'</td></tr>';}if($items===[])echo '<tr><td colspan="6">Không có dòng kiểm kê.</td></tr>';echo '</tbody></table></div>';
        if($canEdit){echo '<p><button class="button" name="decision" value="save">Lưu số đếm</button> <button class="button button-primary" name="decision" value="request">Lưu và gửi duyệt</button></p></form>';}
        elseif((string)$adjustment['status']==='DRAFT'&&$actor->can('erp_stock_adjust_request')&&(int)$adjustment['requested_by']===$actor->id){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mini_erp_inventory_request');echo '<input type="hidden" name="action" value="mini_erp_inventory_request"><input type="hidden" name="adjustment_id" value="'.$id.'"><button class="button button-primary">Gửi duyệt số đếm đã lưu</button></form>';}
        if((string)$adjustment['status']==='REQUESTED'&&$actor->can('erp_stock_adjust_approve')&&(int)$adjustment['requested_by']!==$actor->id){echo '<form class="erp-inline-actions" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mini_erp_inventory_decide');echo '<input type="hidden" name="action" value="mini_erp_inventory_decide"><input type="hidden" name="adjustment_id" value="'.$id.'"><button class="button button-primary" name="decision" value="approve">Duyệt</button> <button class="button" name="decision" value="reject">Từ chối</button></form>';}
        if((string)$adjustment['status']==='APPROVED'&&$actor->can('erp_stock_adjust_apply')&&(int)$adjustment['approved_by']!==$actor->id&&in_array((int)$adjustment['warehouse_id'],$actor->warehouseIds,true)){echo '<form method="post" data-inventory-form="inventory-apply" data-inventory-actor="'.esc_attr((string)$actor->id).'" data-inventory-scope="adjustment-'.$id.'" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('mini_erp_inventory_apply');echo '<input type="hidden" name="action" value="mini_erp_inventory_apply"><input type="hidden" name="adjustment_id" value="'.$id.'"><input type="hidden" name="request_key" value="'.esc_attr(function_exists('wp_generate_uuid4')?wp_generate_uuid4():self::uuid()).'"><button class="button button-primary">Áp dụng chênh lệch</button></form>';}
        echo '</section>';
    }

    private static function service(wpdb $db):InventoryAdjustmentService{return new InventoryAdjustmentService($db,new TransactionManager($db),new AuditLogger($db->prefix));}
    /** @param list<mixed> $args @return list<array<string,mixed>> */
    private static function results(wpdb $db,string $sql,array $args):array{$rows=$args===[]?$db->get_results($sql,ARRAY_A):$db->get_results($db->prepare($sql,$args),ARRAY_A);return is_array($rows)?$rows:[];}
    private static function actor():Actor{$actor=CapabilityPolicy::currentActor();if(!$actor)wp_die(esc_html__('Tài khoản ERP chưa được kích hoạt.','mini-erp'),'', ['response'=>403]);return $actor;}
    private static function table(wpdb $db,string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$db->prefix)||!in_array($suffix,['erp_stock_balances','erp_products','erp_warehouses','erp_stock_adjustments','erp_stock_adjustment_items'],true))throw new DomainException('Cấu hình bảng Mini ERP không hợp lệ.');return '`'.$db->prefix.$suffix.'`';}
    private static function postId(string $key):?int{$value=$_POST[$key]??null;if(!is_scalar($value)||!preg_match('/\A[1-9][0-9]*\z/D',(string)wp_unslash((string)$value)))return null;$id=(int)$value;return $id>0?$id:null;}
    private static function postText(string $key):string{$value=$_POST[$key]??'';return is_scalar($value)?(string)wp_unslash((string)$value):'';}
    private static function getText(string $key):string{$value=$_GET[$key]??'';return is_scalar($value)?(string)wp_unslash((string)$value):'';}
    private static function redirect(string $notice,?int $id=null,?string $recoverForm=null,?string $recoverScope=null):never{$args=['page'=>'mini-erp-inventory','notice'=>$notice];if($id!==null)$args['adjustment']=$id;if($recoverForm!==null)$args['recover_form']=$recoverForm;if($recoverScope!==null)$args['recover_scope']=$recoverScope;wp_safe_redirect(add_query_arg($args,home_url('/')),303);exit;}
    private static function uuid():string{$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
