<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\PurchaseOrderService;
use MiniERP\Domain\BusinessConflictException;
use MiniERP\Domain\RecordNotFoundException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use MiniERP\Security\ReadPolicy;
use Throwable;
use wpdb;

/** Purchase-order and goods-receipt admin screens with warehouse-safe projections. */
final class PurchaseController
{
    private const PAGE='mini-erp-purchases';
    private static int $authorizedDetailId=0;
    private static int $authorizedDetailActorId=0;
    private static int $authorizedEditId=0;
    private static ?array $authorizedDetail=null;

    public static function save(): void
    {
        CapabilityPolicy::requireCapability('erp_purchases_create');
        check_admin_referer('mini_erp_purchase_save');
        $actor = self::actor();
        if (self::text($_POST, 'submit_action') === 'submit') CapabilityPolicy::requireCapability('erp_purchases_view');
        $poId = self::postId('po_id');
        $version = self::postId('version');
        if (array_key_exists('po_id', $_POST) && ($poId === null || $version === null)) self::failure(422, 'Mã đơn hoặc phiên bản cập nhật không hợp lệ.', 'invalid');
        global $wpdb;
        if (!$wpdb instanceof wpdb) self::failure(503, 'ERP tạm thời không khả dụng.', 'unavailable', null, false, 'purchase');
        if ($poId !== null) self::requireMutationTarget($actor, $poId, 'edit', $version);
        $supplier = self::postId('supplier_id');
        $warehouse = self::postId('warehouse_id');

        $ids = isset($_POST['product_id']) && is_array($_POST['product_id']) ? array_values(wp_unslash($_POST['product_id'])) : [];
        $costs = isset($_POST['unit_cost_vnd']) && is_array($_POST['unit_cost_vnd']) ? array_values(wp_unslash($_POST['unit_cost_vnd'])) : [];
        $quantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? array_values(wp_unslash($_POST['quantity'])) : [];
        if ($supplier === null || $warehouse === null || count($ids) !== count($costs) || count($ids) !== count($quantities) || count($ids) > 20) {
            self::failure(422, 'Thông tin đơn mua chưa hợp lệ.', 'invalid', $poId, $poId !== null, 'purchase');
        }
        $items = [];
        foreach ($ids as $index => $rawId) {
            $productId = self::positiveInt($rawId);
            $cost = is_scalar($costs[$index] ?? null) ? trim((string) $costs[$index]) : '';
            $quantity = is_scalar($quantities[$index] ?? null) ? trim((string) $quantities[$index]) : '';
            if ($productId === null && $cost === '' && $quantity === '') continue;
            if ($productId === null || $cost === '' || $quantity === '') {
                self::failure(422, 'Mỗi dòng sản phẩm cần mặt hàng, đơn giá và số lượng.', 'invalid', $poId, $poId !== null, 'purchase');
            }
            $items[] = ['product_id' => $productId, 'unit_cost_vnd' => $cost, 'quantity' => $quantity];
        }

        $savedPoId = null;
        try {
            $service = self::service($wpdb);
            $savedPoId = $poId === null
                ? $service->createDraft($actor, $supplier, $warehouse, $items, sanitize_textarea_field(self::text($_POST, 'note')))
                : $service->updateDraft($actor, $poId, $version ?? 0, $supplier, $warehouse, $items, sanitize_textarea_field(self::text($_POST, 'note')));
            if (self::text($_POST, 'submit_action') === 'submit') $service->transition($actor, $savedPoId, 'submit');
            self::redirect('saved', $savedPoId);
        } catch (RecordNotFoundException) {
            self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        } catch (BusinessConflictException $failure) {
            self::failure(409, $failure->getMessage(), 'conflict', $savedPoId ?? $poId);
        } catch (DatabaseException $failure) {
            $id = $savedPoId ?? $poId;
            if ($failure->getCode() === 1062) self::failure(409, 'Mã đơn đã tồn tại. Hãy tải lại danh sách rồi thử lại.', 'duplicate', $id, false, $poId === null ? 'purchase' : '');
            self::failure(503, 'Không thể lưu đơn lúc này. Vui lòng thử lại sau.', 'unavailable', $id, $poId !== null, $savedPoId === null ? 'purchase' : '');
        } catch (DomainException|\InvalidArgumentException $failure) {
            $id = $savedPoId ?? $poId;
            self::failure(422, $failure->getMessage(), 'invalid', $id, $id !== null, 'purchase');
        } catch (Throwable) {
            self::failure(503, 'Không thể lưu đơn lúc này. Vui lòng thử lại sau.', 'unavailable', $savedPoId ?? $poId);
        }
    }

    public static function transition(): void
    {
        check_admin_referer('mini_erp_purchase_transition');
        $action = sanitize_key(self::text($_POST, 'transition'));
        $required = match ($action) {
            'approve', 'reject' => 'erp_purchases_approve',
            'cancel' => 'erp_purchases_cancel',
            'submit', 'revise' => 'erp_purchases_create',
            default => null,
        };
        if ($required === null) self::failure(422, 'Thao tác đơn mua không hợp lệ.', 'invalid');
        CapabilityPolicy::requireCapability($required);
        CapabilityPolicy::requireCapability('erp_purchases_view');
        global $wpdb;
        if (!$wpdb instanceof wpdb) self::failure(503, 'ERP tạm thời không khả dụng.', 'unavailable');
        $id = self::postId('po_id');
        if ($id === null) self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        self::requireMutationTarget(self::actor(), $id, 'read');
        try {
            self::service($wpdb)->transition(self::actor(), $id, $action, sanitize_textarea_field(self::text($_POST, 'reason')));
            self::redirect('saved', $id);
        } catch (RecordNotFoundException) {
            self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        } catch (BusinessConflictException $failure) {
            self::failure(409, $failure->getMessage(), 'conflict', $id);
        } catch (DomainException|\InvalidArgumentException $failure) {
            self::failure(422, $failure->getMessage(), 'invalid', $id);
        } catch (Throwable) {
            self::failure(503, 'Không thể cập nhật đơn lúc này. Vui lòng thử lại sau.', 'unavailable', $id);
        }
    }

    public static function receive(): void
    {
        CapabilityPolicy::requireCapability('erp_stock_receive');
        check_admin_referer('mini_erp_goods_receipt');
        global $wpdb;
        if (!$wpdb instanceof wpdb) self::failure(503, 'ERP tạm thời không khả dụng.', 'unavailable');
        $id = self::postId('po_id');
        if ($id === null) self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        self::requireMutationTarget(self::actor(), $id, 'read');
        $ids = isset($_POST['purchase_item_id']) && is_array($_POST['purchase_item_id']) ? array_values(wp_unslash($_POST['purchase_item_id'])) : [];
        $quantities = isset($_POST['receive_quantity']) && is_array($_POST['receive_quantity']) ? array_values(wp_unslash($_POST['receive_quantity'])) : [];
        if (count($ids) !== count($quantities) || count($ids) > 100) self::failure(422, 'Danh sách dòng nhận hàng không hợp lệ.', 'invalid', $id);
        $lines = [];
        foreach ($ids as $index => $rawId) {
            $lineId = self::positiveInt($rawId);
            $quantity = is_scalar($quantities[$index] ?? null) ? trim((string) $quantities[$index]) : '';
            if ($quantity === '') continue;
            if ($lineId === null) self::failure(422, 'Dòng nhận hàng không hợp lệ.', 'invalid', $id);
            $lines[] = ['purchase_item_id' => $lineId, 'quantity' => $quantity];
        }
        try {
            self::service($wpdb)->receive(self::actor(), $id, sanitize_text_field(self::text($_POST, 'request_key')), $lines);
            self::redirect('received', $id);
        } catch (RecordNotFoundException) {
            self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        } catch (BusinessConflictException $failure) {
            self::failure(409, $failure->getMessage(), 'conflict', $id);
        } catch (DatabaseException $failure) {
            if ($failure->getCode() === 1062) self::failure(409, 'Yêu cầu nhận hàng này đã được ghi nhận.', 'conflict', $id);
            self::failure(503, 'Không thể ghi nhận nhập hàng lúc này. Vui lòng thử lại sau.', 'unavailable', $id);
        } catch (DomainException|\InvalidArgumentException $failure) {
            self::failure(422, $failure->getMessage(), 'invalid', $id);
        } catch (Throwable) {
            self::failure(503, 'Không thể ghi nhận nhập hàng lúc này. Vui lòng thử lại sau.', 'unavailable', $id);
        }
    }

    public static function render(): void
    {
        CapabilityPolicy::requireCapability('erp_purchases_view');$actor=self::actor();global $wpdb;if(!$wpdb instanceof wpdb)wp_die(esc_html__('Cơ sở dữ liệu ERP chưa sẵn sàng.','mini-erp'),'',['response'=>503]);
        $orders=self::table($wpdb,'erp_purchase_orders');$suppliers=self::table($wpdb,'erp_suppliers');$warehouses=self::table($wpdb,'erp_warehouses');$canManage=$actor->can('erp_purchases_create');$canReceive=$actor->can('erp_stock_receive');$id=isset($_GET['po'])?absint($_GET['po']):0;$notice=sanitize_key(self::text($_GET,'notice'));
        $preflightStatus=self::preflightDetail($actor);if($preflightStatus!==null)wp_die(esc_html($preflightStatus===404?'Không tìm thấy đơn hoặc bạn không có quyền truy cập.':'ERP tạm thời không thể xác minh đơn mua.'),'',['response'=>$preflightStatus]);
        echo '<div class="wrap erp-app"><h1>Đơn mua và nhập hàng</h1>';if($notice!=='')echo '<div class="notice '.(in_array($notice,['saved','received'],true)?'notice-success':'notice-error').'"><p>'.esc_html(self::notice($notice)).'</p></div>';
        $where=[];$args=[];if($canReceive&&!$canManage){if($actor->warehouseIds===[])$where[]='1=0';else{$where[]='po.warehouse_id IN ('.implode(',',array_fill(0,count($actor->warehouseIds),'%d')).')';array_push($args,...$actor->warehouseIds);$where[]="po.status IN ('APPROVED','PARTIALLY_RECEIVED')";}}
        $status=isset($_GET['status'])?strtoupper(trim(self::text($_GET,'status'))):'';if(in_array($status,['DRAFT','PENDING_APPROVAL','APPROVED','PARTIALLY_RECEIVED','RECEIVED','REJECTED','CANCELLED','CLOSED'],true)){$where[]='po.status=%s';$args[]=$status;}$predicate=$where===[]?'':' WHERE '.implode(' AND ',$where);
        $page=max(1,min(10000,absint($_GET['paged']??1)));$total=(int)($args===[]?$wpdb->get_var("SELECT COUNT(*) FROM {$orders} po{$predicate}"):$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders} po{$predicate}",$args)));$pages=max(1,(int)ceil($total/50));$page=min($page,$pages);
        $project=$canReceive&&!$canManage?'po.id,po.po_no,po.warehouse_id,po.status,po.created_at,w.name AS warehouse_name':'po.id,po.po_no,po.status,po.total_vnd,po.created_at,s.name AS supplier_name,w.name AS warehouse_name';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT {$project} FROM {$orders} po JOIN {$warehouses} w ON w.id=po.warehouse_id LEFT JOIN {$suppliers} s ON s.id=po.supplier_id{$predicate} ORDER BY po.created_at DESC,po.id DESC LIMIT %d OFFSET %d",[...$args,50,($page-1)*50]),ARRAY_A);
        if($canManage){$editId=isset($_GET['edit'])?absint($_GET['edit']):0;self::createForm($wpdb,$suppliers,$warehouses,$editId>0?$editId:null,$actor);}
        echo '<form method="get" action="'.esc_url(home_url('/')).'"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'"><label for="erp-po-status">Trạng thái</label> <select name="status" id="erp-po-status"><option value="">Tất cả</option>';foreach(['DRAFT','PENDING_APPROVAL','APPROVED','PARTIALLY_RECEIVED','RECEIVED','REJECTED','CANCELLED','CLOSED'] as $s)echo '<option value="'.esc_attr($s).'"'.selected($status,$s,false).'>'.esc_html(self::statusLabel($s)).'</option>';echo '</select> <button class="button">Lọc</button></form>';
        echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Mã PO</th>'.($canReceive&&!$canManage?'':'<th>Nhà cung cấp</th>').'<th>Kho</th><th>Ngày</th>'.($canReceive&&!$canManage?'':'<th>Tổng VND</th>').'<th>Trạng thái</th><th></th></tr></thead><tbody>';
        foreach(is_array($rows)?$rows:[] as $row){$url=add_query_arg(['page'=>self::PAGE,'po'=>(int)$row['id']],home_url('/'));echo '<tr><td><a href="'.esc_url($url).'">'.esc_html((string)$row['po_no']).'</a></td>';if(!($canReceive&&!$canManage))echo '<td>'.esc_html((string)$row['supplier_name']).'</td>';echo '<td>'.esc_html((string)$row['warehouse_name']).'</td><td>'.esc_html((string)$row['created_at']).'</td>';if(!($canReceive&&!$canManage))echo '<td>'.esc_html(number_format((int)$row['total_vnd'],0,',','.')).'</td>';echo '<td>'.esc_html(self::statusLabel((string)$row['status'])).'</td><td><a class="button" href="'.esc_url($url).'">Mở</a></td></tr>';}
        if(!is_array($rows)||$rows===[])echo '<tr><td colspan="7">Chưa có đơn mua phù hợp.</td></tr>';echo '</tbody></table></div>';self::pagination($page,$pages,$status);if($id>0)self::detail($wpdb,$actor,$id,$canManage,$canReceive);echo '</div>';
    }

    /** Return an HTTP status before the frontend shell is emitted for an inaccessible purchase order. */
    public static function preflightDetail(Actor $actor): ?int
    {
        $id=isset($_GET['po'])?absint($_GET['po']):0;
        $editId=isset($_GET['edit'])?absint($_GET['edit']):0;
        if($id<1&&$editId<1){self::clearAuthorizedDetail();return null;}
        if(self::$authorizedDetailId===$id&&self::$authorizedEditId===$editId&&self::$authorizedDetailActorId===$actor->id&&($id<1||self::$authorizedDetail!==null))return null;
        self::clearAuthorizedDetail();
        if($id>0&&$editId>0&&$id!==$editId)return 404;

        global $wpdb;
        if(!$wpdb instanceof wpdb)return 503;
        $policy=new ReadPolicy();$scope=$policy->orderScope($actor,'purchase_orders');
        if($scope['kind']==='none')return 404;
        $orders=self::table($wpdb,'erp_purchase_orders');$suppliers=self::table($wpdb,'erp_suppliers');$warehouses=self::table($wpdb,'erp_warehouses');$where=['po.id=%d'];$args=[$id];
        if($editId>0){
            if(!$actor->can('erp_purchases_create'))return 404;
            try{$editableId=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$orders} WHERE id=%d AND created_by=%d AND status='DRAFT'",$editId,$actor->id));}catch(\Throwable){return 503;}
            if($wpdb->last_error!=='')return 503;
            if((int)$editableId!==$editId)return 404;
        }
        if($id<1){self::$authorizedEditId=$editId;self::$authorizedDetailActorId=$actor->id;return null;}
        if($scope['kind']==='warehouse_ids'){
            $warehouseIds=$scope['warehouse_ids']??[];$statuses=$scope['statuses']??[];
            if($warehouseIds===[]||$statuses===[])return 404;
            $where[]='po.warehouse_id IN ('.implode(',',array_fill(0,count($warehouseIds),'%d')).')';array_push($args,...$warehouseIds);
            $where[]='po.status IN ('.implode(',',array_fill(0,count($statuses),'%s')).')';array_push($args,...$statuses);
        }
        $warehouseProjection=$scope['kind']==='warehouse_ids';
        $projection=$warehouseProjection
            ?'po.id,po.po_no,po.warehouse_id,po.status,po.created_at,w.name AS warehouse_name'
            :'po.id,po.po_no,po.supplier_id,po.warehouse_id,po.created_by,po.status,po.total_vnd,po.created_at,s.name AS supplier_name,w.name AS warehouse_name';
        try{$order=$wpdb->get_row($wpdb->prepare("SELECT {$projection} FROM {$orders} po LEFT JOIN {$suppliers} s ON s.id=po.supplier_id INNER JOIN {$warehouses} w ON w.id=po.warehouse_id WHERE ".implode(' AND ',$where),$args),ARRAY_A);}catch(\Throwable){return 503;}
        if($wpdb->last_error!=='')return 503;
        if(!is_array($order)||!$policy->canReadPurchaseOrder($actor,$order))return 404;
        self::$authorizedDetailId=$id;self::$authorizedDetailActorId=$actor->id;self::$authorizedEditId=$editId;self::$authorizedDetail=$order;
        return null;
    }

    /** Return 404 for an absent or out-of-scope mutation target, before processing its fields. */
    public static function preflightMutationTarget(Actor $actor, int $id, string $operation, ?int $expectedVersion = null): ?int
    {
        if ($id < 1 || !in_array($operation, ['read', 'edit'], true)) return 404;
        if ($operation === 'edit' && !$actor->can('erp_purchases_create')) return 404;
        global $wpdb;
        if (!$wpdb instanceof wpdb) return 503;
        try {
            $orders = self::table($wpdb, 'erp_purchase_orders');
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,created_by,warehouse_id,status,version FROM {$orders} WHERE id=%d", $id), ARRAY_A);
        } catch (Throwable) {
            return 503;
        }
        if ($wpdb->last_error !== '') return 503;
        $policy = new ReadPolicy();
        if (!is_array($row) || !$policy->canReadPurchaseOrder($actor, $row)) return 404;
        if ($operation === 'edit' && (int) $row['created_by'] !== $actor->id) return 404;
        if ($operation === 'edit' && (string) $row['status'] !== 'DRAFT') return 404;
        if ($operation === 'edit' && $expectedVersion !== null && (int) $row['version'] !== $expectedVersion) return 409;
        return null;
    }

    private static function clearAuthorizedDetail(): void
    {
        self::$authorizedDetailId=0;self::$authorizedDetailActorId=0;self::$authorizedEditId=0;self::$authorizedDetail=null;
    }

    private static function createForm(wpdb $db,string $supplierTable,string $warehouseTable,?int $editingId,Actor $actor): void
    {
        $suppliers=$db->get_results("SELECT id,code,name FROM {$supplierTable} WHERE is_active=1 ORDER BY name LIMIT 500",ARRAY_A);$warehouses=$db->get_results("SELECT id,code,name FROM {$warehouseTable} WHERE is_active=1 ORDER BY name LIMIT 500",ARRAY_A);
        $editing=null;$existing=[];if($editingId!==null){$editing=$db->get_row($db->prepare("SELECT id,supplier_id,warehouse_id,created_by,status,note,version FROM ".self::table($db,'erp_purchase_orders')." WHERE id=%d",$editingId),ARRAY_A);if(!is_array($editing)||(int)$editing['created_by']!==$actor->id||$editing['status']!=='DRAFT')$editing=null;if($editing!==null)$existing=$db->get_results($db->prepare("SELECT product_id,unit_cost_vnd,quantity FROM ".self::table($db,'erp_purchase_order_items')." WHERE po_id=%d ORDER BY id LIMIT 21",$editingId),ARRAY_A)?:[];}
        if(count($existing)>20){echo '<div class="notice notice-error"><p>Đơn mua này vượt giới hạn 20 dòng nên chưa thể sửa an toàn. Hãy liên hệ quản trị viên.</p></div>';return;}
        $productsTable=self::table($db,'erp_products');$products=$db->get_results("SELECT id,sku,name,unit,unit_step,is_active FROM {$productsTable} WHERE is_active=1 ORDER BY name LIMIT 500",ARRAY_A);
        if(!$suppliers||!$warehouses||!$products){echo '<div class="notice notice-warning"><p>Tạo nhà cung cấp, kho và ít nhất một sản phẩm đang kinh doanh trước khi lập đơn mua.</p></div>';return;}
        $activeProductIds=array_fill_keys(array_map(static fn(array $product):int=>(int)$product['id'],$products),true);$preservedIds=array_values(array_unique(array_filter(array_map(static fn(array $item):int=>(int)$item['product_id'],$existing),static fn(int $id):bool=>!isset($activeProductIds[$id]))));if($preservedIds!==[]){$slots=implode(',',array_fill(0,count($preservedIds),'%d'));$preservedProducts=$db->get_results($db->prepare("SELECT id,sku,name,unit,unit_step,is_active FROM {$productsTable} WHERE id IN ({$slots}) ORDER BY name",$preservedIds),ARRAY_A)?:[];$products=array_merge($products,$preservedProducts);}
        echo '<details class="erp-order-create"'.($editing?' open':'').'><summary class="button button-primary">'.($editing?'Sửa đơn mua nháp':'Tạo đơn mua').'</summary><form method="post" data-order-form-state="purchase" data-order-actor="'.esc_attr((string)$actor->id).'" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mini_erp_purchase_save">';wp_nonce_field('mini_erp_purchase_save');if($editing)echo '<input type="hidden" name="po_id" value="'.esc_attr((string)$editing['id']).'"><input type="hidden" name="version" value="'.esc_attr((string)$editing['version']).'">';
        echo '<div class="erp-customer-lookup"><label>Tìm nhà cung cấp <input type="search" data-supplier-search maxlength="100" placeholder="Mã hoặc tên nhà cung cấp" autocomplete="off"></label><label>Nhà cung cấp <select name="supplier_id" data-supplier-select required><option value="">Chọn nhà cung cấp</option>';foreach($suppliers as $r)echo '<option value="'.esc_attr((string)$r['id']).'"'.selected((int)($editing['supplier_id']??0),(int)$r['id'],false).'>'.esc_html($r['code'].' · '.$r['name']).'</option>';echo '</select></label><span class="description" data-supplier-search-status aria-live="polite">Tìm theo mã hoặc tên để tra cứu thêm nhà cung cấp.</span></div><p><label>Kho nhận <select name="warehouse_id" required><option value="">Chọn kho</option>';foreach($warehouses as $r)echo '<option value="'.esc_attr((string)$r['id']).'"'.selected((int)($editing['warehouse_id']??0),(int)$r['id'],false).'>'.esc_html($r['code'].' · '.$r['name']).'</option>';echo '</select></label></p><h3>Sản phẩm</h3>';
        $lineCount=max(5,count($existing));echo '<div class="erp-order-lines" data-order-lines data-max-lines="20" data-line-label="Sản phẩm">';
        for($i=0;$i<$lineCount;$i++){$existingProductId=(int)($existing[$i]['product_id']??0);echo '<div class="erp-order-line" data-order-line><div class="erp-order-product-field"><label><span data-line-title>Tìm sản phẩm '.($i+1).'</span> <input type="search" data-product-search maxlength="100" placeholder="Tìm SKU hoặc tên" autocomplete="off"></label><label>Sản phẩm <select name="product_id[]" data-product-select><option value="">— Chọn sản phẩm —</option>';foreach($products as $p){$inactiveLabel=(int)$p['is_active']===1?'':' · Ngừng kinh doanh — cần đổi hoặc bỏ dòng này';echo '<option value="'.esc_attr((string)$p['id']).'"'.selected($existingProductId,(int)$p['id'],false).'>'.esc_html($p['sku'].' · '.$p['name'].' · '.$p['unit'].$inactiveLabel).'</option>';}echo '</select></label><span class="description" data-product-search-status aria-live="polite">Tra cứu theo SKU hoặc tên sản phẩm.</span></div> <label>Đơn giá nhập VND <input name="unit_cost_vnd[]" type="number" min="0" step="1" inputmode="numeric" value="'.esc_attr((string)($existing[$i]['unit_cost_vnd']??'')).'" placeholder="0"></label> <label>Số lượng <input name="quantity[]" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,3})?" value="'.esc_attr((string)($existing[$i]['quantity']??'')).'" placeholder="0"></label><button type="button" class="button" data-remove-order-line hidden aria-label="Xóa dòng sản phẩm '.($i+1).'">Xóa dòng</button></div>';}
        echo '</div><div class="erp-order-lines-actions"><button type="button" class="button" data-add-order-line hidden>Thêm sản phẩm</button> <span class="description" data-order-lines-status aria-live="polite">Tối đa 20 dòng sản phẩm.</span></div>';
        echo '<p><label>Ghi chú<br><textarea class="large-text" name="note" maxlength="1000" rows="2">'.esc_textarea((string)($editing['note']??'')).'</textarea></label></p><p><button class="button" name="submit_action" value="draft">'.($editing?'Cập nhật nháp':'Lưu nháp').'</button> <button class="button button-primary" name="submit_action" value="submit">'.($editing?'Cập nhật và gửi duyệt':'Lưu và gửi duyệt').'</button></p></form></details>';
    }

    private static function detail(wpdb $db,Actor $actor,int $id,bool $canManage,bool $canReceive): void
    {
        $itemsTable=self::table($db,'erp_purchase_order_items');$receipts=self::table($db,'erp_goods_receipts');$receiptItems=self::table($db,'erp_goods_receipt_items');$balances=self::table($db,'erp_stock_balances');
        $order=self::$authorizedDetailId===$id&&self::$authorizedDetailActorId===$actor->id?self::$authorizedDetail:null;self::clearAuthorizedDetail();if(!is_array($order)){echo '<div class="notice notice-error"><p>Không tìm thấy đơn hoặc bạn không có quyền truy cập.</p></div>';return;}
        $canViewCommercial=$canManage||($actor->can('erp_purchases_view')&&!$canReceive);$lineProjection=$canViewCommercial?'id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_cost_vnd,quantity,line_total_vnd':'id,product_id,sku_snapshot,name_snapshot,unit_snapshot,quantity';
        $lines=$db->get_results($db->prepare("SELECT {$lineProjection} FROM {$itemsTable} WHERE po_id=%d ORDER BY id",$id),ARRAY_A);$received=$db->get_results($db->prepare("SELECT ri.purchase_item_id,SUM(ri.quantity) AS quantity FROM {$receiptItems} ri JOIN {$receipts} gr ON gr.id=ri.goods_receipt_id WHERE gr.purchase_order_id=%d GROUP BY ri.purchase_item_id",$id),ARRAY_A);$receivedBy=[];foreach(is_array($received)?$received:[] as $r)$receivedBy[(int)$r['purchase_item_id']]=(string)$r['quantity'];
        $receiptPage=max(1,min(10000,absint($_GET['receipt_page']??1)));$receiptCount=(int)$db->get_var($db->prepare("SELECT COUNT(*) FROM {$receipts} WHERE purchase_order_id=%d",$id));$receiptPages=max(1,(int)ceil($receiptCount/50));$receiptPage=min($receiptPage,$receiptPages);$receiptRows=$db->get_results($db->prepare("SELECT gr.id,gr.receipt_no,gr.received_at,gr.received_by,ri.purchase_item_id,ri.quantity,pi.sku_snapshot,pi.unit_snapshot FROM (SELECT id,receipt_no,received_at,received_by FROM {$receipts} WHERE purchase_order_id=%d ORDER BY received_at DESC,id DESC LIMIT %d OFFSET %d) gr LEFT JOIN {$receiptItems} ri ON ri.goods_receipt_id=gr.id LEFT JOIN {$itemsTable} pi ON pi.id=ri.purchase_item_id ORDER BY gr.received_at DESC,gr.id DESC,ri.purchase_item_id",$id,50,($receiptPage-1)*50),ARRAY_A);$receiptHistory=[];foreach(is_array($receiptRows)?$receiptRows:[] as $r){$receiptId=(int)$r['id'];if(!isset($receiptHistory[$receiptId]))$receiptHistory[$receiptId]=['receipt_no'=>(string)$r['receipt_no'],'received_at'=>(string)$r['received_at'],'received_by'=>(int)$r['received_by'],'lines'=>[]];if($r['purchase_item_id']!==null)$receiptHistory[$receiptId]['lines'][]=(string)$r['sku_snapshot'].' · '.(string)$r['quantity'].' '.(string)$r['unit_snapshot'];}
        echo '<section class="erp-order-detail"><h2>'.esc_html((string)$order['po_no']).' · '.esc_html(self::statusLabel((string)$order['status'])).'</h2>';if($canViewCommercial)echo '<p>Nhà cung cấp: '.esc_html((string)$order['supplier_name']).' · Tổng: '.esc_html(number_format((int)$order['total_vnd'],0,',','.')).' VND</p>';else echo '<p>Kho nhận: '.esc_html((string)$order['warehouse_name']).'</p>';
        echo '<div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Sản phẩm</th><th>Đặt</th><th>Đã nhận</th><th>Còn</th>'.($canViewCommercial?'<th>Đơn giá</th><th>Thành tiền</th>':'').($canReceive?'<th>Tồn trên tay*</th><th>Nhận đợt này</th>':'').'</tr></thead><tbody>';
        foreach(is_array($lines)?$lines:[] as $line){$old=$receivedBy[(int)$line['id']]??'0.000';$remaining=bcsub((string)$line['quantity'],$old,3);$onHand=$db->get_var($db->prepare("SELECT quantity FROM {$balances} WHERE warehouse_id=%d AND product_id=%d",(int)$order['warehouse_id'],(int)$line['product_id']));echo '<tr><td>'.esc_html($line['sku_snapshot'].' · '.$line['name_snapshot']).'</td><td>'.esc_html($line['quantity'].' '.$line['unit_snapshot']).'</td><td>'.esc_html($old).'</td><td>'.esc_html($remaining).'</td>';if($canViewCommercial)echo '<td>'.esc_html(number_format((int)$line['unit_cost_vnd'],0,',','.')).'</td><td>'.esc_html(number_format((int)$line['line_total_vnd'],0,',','.')).'</td>';if($canReceive)echo '<td>'.esc_html($onHand===null?'0.000':(string)$onHand).'</td><td><input form="erp-receipt-form" type="hidden" name="purchase_item_id[]" value="'.esc_attr((string)$line['id']).'"><input form="erp-receipt-form" name="receive_quantity[]" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,3})?" placeholder="0" aria-label="Nhận '.esc_attr((string)$line['sku_snapshot']).'"></td>';echo '</tr>';}
        echo '</tbody></table></div><h3>Lịch sử phiếu nhận hàng</h3><p>Trang '.esc_html((string)$receiptPage).' / '.esc_html((string)$receiptPages).' · '.esc_html(number_format($receiptCount,0,',','.')).' phiếu</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Phiếu nhận</th><th>Ngày ghi nhận (Asia/Ho_Chi_Minh)</th><th>Người ghi nhận</th><th>Mặt hàng và số lượng</th></tr></thead><tbody>';foreach($receiptHistory as $receipt){echo '<tr><td>'.esc_html($receipt['receipt_no']).'</td><td>'.esc_html(self::localTime($receipt['received_at'])).'</td><td>#'.esc_html((string)$receipt['received_by']).'</td><td>';foreach($receipt['lines'] as $index=>$line)echo ($index>0?'<br>':'').esc_html($line);echo '</td></tr>';}if($receiptHistory===[])echo '<tr><td colspan="4">Chưa có phiếu nhận hàng.</td></tr>';echo '</tbody></table></div>';if($receiptPages>1){echo '<nav class="erp-pagination" aria-label="Phân trang phiếu nhận hàng">';if($receiptPage>1)echo '<a class="button" rel="prev" href="'.esc_url(add_query_arg(['page'=>self::PAGE,'po'=>$id,'receipt_page'=>$receiptPage-1],home_url('/'))).'">Trang trước</a> ';if($receiptPage<$receiptPages)echo '<a class="button" rel="next" href="'.esc_url(add_query_arg(['page'=>self::PAGE,'po'=>$id,'receipt_page'=>$receiptPage+1],home_url('/'))).'">Trang sau</a>';echo '</nav>';}
        self::renderLinkedInvoices($db,$actor,$id,(string)$order['status'],$receiptPage);
        if($canManage)self::actions($actor,$order);if($canManage&&(int)$order['created_by']===$actor->id&&(string)$order['status']==='DRAFT')echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>self::PAGE,'po'=>(int)$order['id'],'edit'=>(int)$order['id']],home_url('/'))).'">Sửa nội dung đơn</a> ';
        if($canReceive&&in_array((string)$order['status'],['APPROVED','PARTIALLY_RECEIVED'],true)&&in_array((int)$order['warehouse_id'],$actor->warehouseIds,true)){echo '<form id="erp-receipt-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mini_erp_goods_receipt">';wp_nonce_field('mini_erp_goods_receipt');echo '<input type="hidden" name="po_id" value="'.esc_attr((string)$id).'"><input type="hidden" name="request_key" value="'.esc_attr(wp_generate_uuid4()).'"><button class="button button-primary">Ghi phiếu nhận hàng</button></form>';}
        echo '</section>';
    }

    private static function renderLinkedInvoices(wpdb $db,Actor $actor,int $orderId,string $orderStatus,int $page):void
    {
        if((new ReadPolicy())->invoiceScope($actor)['kind']!=='all')return;
        $invoices=self::table($db,'erp_invoices');$receipts=self::table($db,'erp_goods_receipts');
        try{
            $rows=$db->get_results($db->prepare("SELECT source.id AS receipt_id,source.receipt_no,source.received_at,i.id AS invoice_id,i.invoice_no,i.total_vnd,i.status,i.issued_at,i.due_at FROM (SELECT gr.id,gr.receipt_no,gr.received_at FROM {$receipts} gr WHERE gr.purchase_order_id=%d ORDER BY gr.received_at DESC,gr.id DESC LIMIT %d OFFSET %d) source LEFT JOIN {$invoices} i ON i.source_type='GOODS_RECEIPT' AND i.source_id=source.id ORDER BY source.received_at DESC,source.id DESC",$orderId,50,($page-1)*50),ARRAY_A);
            if(!is_array($rows))throw new \RuntimeException('Linked invoice query failed.');
        }catch(Throwable){echo '<section class="erp-document-chain"><h3>Hóa đơn liên quan</h3><p>Không thể tải liên kết hóa đơn lúc này.</p></section>';return;}
        $canViewPayments=$actor->can('erp_payments_view')&&$actor->cashAccountIds!==[];
        $canIssue=(new ReadPolicy())->invoiceScope($actor)['kind']==='all'&&$actor->can('erp_invoices_issue')&&$actor->can('erp_invoice_sources_view_all');
        $canCreateFromOrder=$canIssue&&in_array($orderStatus,['PARTIALLY_RECEIVED','RECEIVED','CLOSED'],true);
        echo '<section class="erp-document-chain"><h3>Hóa đơn từ các phiếu nhận</h3><p>Mỗi đợt nhận có trạng thái lập hóa đơn riêng. Giao dịch chỉ mở trong phạm vi quỹ được gán.</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Phiếu nhận</th><th>Hóa đơn</th><th>Phát hành / hạn</th><th>Tổng</th><th>Trạng thái</th><th>Liên kết</th></tr></thead><tbody>';
        foreach($rows as $row){
            echo '<tr><td>'.esc_html((string)$row['receipt_no']).'</td>';
            if($row['invoice_id']!==null){
                $invoiceUrl=add_query_arg(['page'=>'mini-erp-invoices','invoice'=>(int)$row['invoice_id']],home_url('/'));
                $status=match((string)$row['status']){'ISSUED'=>'Đã phát hành','VOID'=>'Đã hủy',default=>(string)$row['status']};
                echo '<td><a href="'.esc_url($invoiceUrl).'">'.esc_html((string)$row['invoice_no']).'</a></td><td>'.esc_html(self::localTime((string)$row['issued_at'])).' / '.esc_html(substr((string)$row['due_at'],0,10)).'</td><td>'.esc_html(number_format((int)$row['total_vnd'],0,',','.')).' ₫</td><td>'.esc_html($status).'</td><td>';
            }else{
                echo '<td>Chưa lập</td><td>—</td><td>—</td><td>Chưa phát hành</td><td>';
                if($canCreateFromOrder){$issueUrl=add_query_arg(['page'=>'mini-erp-invoices','source_type'=>'GOODS_RECEIPT','source_id'=>(int)$row['receipt_id']],home_url('/')).'#erp-invoice-sources';echo '<a class="button" href="'.esc_url($issueUrl).'">Lập hóa đơn</a>';}
            }
            if($row['invoice_id']!==null&&$canViewPayments){$paymentsUrl=add_query_arg(['page'=>'mini-erp-payments','q'=>(string)$row['invoice_no']],home_url('/'));echo '<a class="button" href="'.esc_url($paymentsUrl).'">Giao dịch trong quỹ được gán</a>';}elseif($row['invoice_id']!==null)echo '—';
            echo '</td></tr>';
        }
        if($rows===[])echo '<tr><td colspan="6">Chưa có phiếu nhận để lập hóa đơn.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function actions(Actor $actor,array $po): void
    {
        $status=(string)$po['status'];$creator=(int)$po['created_by'];$manager=$actor->can('erp_purchases_approve');$actions=[];if($status==='DRAFT'&&$creator===$actor->id)$actions[]=['submit','Gửi duyệt'];if($status==='PENDING_APPROVAL'&&$manager&&$creator!==$actor->id){$actions[]=['approve','Duyệt'];$actions[]=['reject','Trả về'];}if($status==='REJECTED'&&$creator===$actor->id)$actions[]=['revise','Sửa lại'];if(in_array($status,['DRAFT','PENDING_APPROVAL','APPROVED'],true)&&$manager)$actions[]=['cancel','Đóng đơn'];if($status==='PARTIALLY_RECEIVED'&&$manager)$actions[]=['cancel','Đóng phần còn lại'];
        foreach($actions as [$action,$label]){echo '<form class="erp-inline-action" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mini_erp_purchase_transition">';wp_nonce_field('mini_erp_purchase_transition');echo '<input type="hidden" name="po_id" value="'.esc_attr((string)$po['id']).'"><input type="hidden" name="transition" value="'.esc_attr($action).'">';if($action==='cancel')echo '<label>Lý do <input name="reason" required maxlength="1000"></label> ';echo '<button class="button">'.esc_html($label).'</button></form>';}
    }

    private static function service(wpdb $db): PurchaseOrderService{return new PurchaseOrderService($db,new TransactionManager($db),new AuditLogger());}
    private static function actor():Actor{return CapabilityPolicy::currentActor()??throw new DomainException('Phiên ERP không hợp lệ.');}
    private static function requireMutationTarget(Actor $actor, int $id, string $operation, ?int $expectedVersion = null): void
    {
        $status = self::preflightMutationTarget($actor, $id, $operation, $expectedVersion);
        if ($status === 404) self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        if ($status === 409) self::failure(409, 'Đơn mua đã được cập nhật. Hãy mở lại bản nháp mới nhất trước khi lưu.', 'conflict', $id, true, 'purchase');
        if ($status === 503) self::failure(503, 'ERP tạm thời không thể xác minh đơn mua.', 'unavailable');
    }
    private static function table(wpdb $db,string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$db->prefix)||!in_array($suffix,['erp_purchase_orders','erp_purchase_order_items','erp_goods_receipts','erp_goods_receipt_items','erp_invoices','erp_stock_balances','erp_stock_movements','erp_suppliers','erp_warehouses','erp_products'],true))throw new DomainException('Cấu hình bảng không hợp lệ.');return '`'.$db->prefix.$suffix.'`';}
    private static function text(array $source,string $key):string{return isset($source[$key])&&is_scalar($source[$key])?(string)wp_unslash((string)$source[$key]):'';}
    private static function positiveInt(mixed $v):?int{$v=is_scalar($v)?(string)$v:'';return preg_match('/\A[1-9][0-9]*\z/D',$v)&&(string)(int)$v===$v?(int)$v:null;}
    private static function postId(string $key):?int{return self::positiveInt(self::text($_POST,$key));}
    private static function redirect(string $notice,?int $id=null,bool $edit=false):never{$args=['page'=>self::PAGE,'notice'=>$notice];if($id!==null){$args['po']=$id;if($edit)$args['edit']=$id;}wp_safe_redirect(add_query_arg($args,home_url('/')),303);exit;}
    private static function failure(int $status, string $message, string $notice = '', ?int $id = null, bool $edit = false, string $recoverForm = ''): never
    {
        $body = '<p>' . esc_html($message) . '</p>';
        if ($notice !== '') {
            $args = ['page' => self::PAGE, 'notice' => $notice];
            if ($recoverForm !== '') $args['recover_form'] = $recoverForm;
            if ($id !== null) { $args['po'] = $id; if ($edit) $args['edit'] = $id; }
            $url = add_query_arg($args, home_url('/'));
            $body .= '<p><a href="' . esc_url($url) . '">Quay lại đơn mua</a></p>';
        }
        wp_die($body, 'Mini ERP', ['response' => $status]);
        exit;
    }
    private static function pagination(int $page,int $pages,string $status):void{if($pages<2)return;echo '<p class="tablenav-pages">';for($i=max(1,$page-3);$i<=min($pages,$page+3);$i++)echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>self::PAGE,'paged'=>$i,'status'=>$status],home_url('/'))).'">'.esc_html((string)$i).'</a> ';echo '</p>';}
    private static function statusLabel(string $s):string{return ['DRAFT'=>'Nháp','PENDING_APPROVAL'=>'Chờ duyệt','APPROVED'=>'Đã duyệt','PARTIALLY_RECEIVED'=>'Nhận một phần','RECEIVED'=>'Đã nhận đủ','REJECTED'=>'Trả về','CANCELLED'=>'Đã hủy','CLOSED'=>'Đã đóng'][$s]??$s;}
    private static function localTime(string $utc):string{$timestamp=strtotime($utc.' UTC');return $timestamp===false?$utc:wp_date('Y-m-d H:i',$timestamp,new \DateTimeZone('Asia/Ho_Chi_Minh'));}
    private static function notice(string $n):string{return ['saved'=>'Đã lưu đơn mua.','received'=>'Đã ghi phiếu nhận và cập nhật kho.','invalid'=>'Thông tin không hợp lệ.','duplicate'=>'Mã chứng từ đã tồn tại.','conflict'=>'Trạng thái đơn đã thay đổi.','receive_error'=>'Không thể ghi phiếu nhận; kiểm tra quyền, trạng thái và số lượng.','unavailable'=>'ERP hiện chưa sẵn sàng.'][$n]??'';}
}
