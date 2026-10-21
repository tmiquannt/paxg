<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\SalesOrderService;
use MiniERP\Domain\BusinessConflictException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use MiniERP\Security\ReadPolicy;
use MiniERP\Domain\RecordNotFoundException;
use Throwable;
use wpdb;

/** Sales order list/detail, draft creation, approval actions and warehouse shipment UI. */
final class SalesController
{
    private const PAGE = 'mini-erp-sales';
    private static int $authorizedDetailId = 0;
    private static int $authorizedDetailActorId = 0;
    private static int $authorizedEditId = 0;
    private static ?array $authorizedDetail = null;

    public static function save(): void
    {
        CapabilityPolicy::requireCapability('erp_sales_create');
        check_admin_referer('mini_erp_sales_save');
        $actor = self::actor();
        if (self::text($_POST, 'submit_action') === 'submit') {
            CapabilityPolicy::requireCapability('erp_sales_confirm');
            if (!$actor->can('erp_sales_view_own') && !$actor->can('erp_sales_view_all')) {
                wp_die('Bạn không có quyền xem đơn bán để gửi đơn này.', 'Mini ERP', ['response' => 403]);
            }
        }
        $orderId = self::postId('order_id');
        $version = self::postId('version');
        if (array_key_exists('order_id', $_POST) && ($orderId === null || $version === null)) self::failure(422, 'Mã đơn hoặc phiên bản cập nhật không hợp lệ.', 'invalid');
        global $wpdb;
        if (!$wpdb instanceof wpdb) self::failure(503, 'ERP tạm thời không khả dụng.', 'unavailable', null, false, 'sales');
        if ($orderId !== null) self::requireMutationTarget($actor, $orderId, 'edit', $version);
        $customerId = self::postId('customer_id');
        $warehouseId = self::postId('warehouse_id');
        $productIds = isset($_POST['product_id']) && is_array($_POST['product_id']) ? array_values(wp_unslash($_POST['product_id'])) : [];
        $quantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? array_values(wp_unslash($_POST['quantity'])) : [];
        if ($customerId === null || $warehouseId === null || count($productIds) !== count($quantities) || count($productIds) > 20) self::failure(422, 'Thông tin đơn bán chưa hợp lệ.', 'invalid', $orderId, $orderId !== null, 'sales');
        $items = [];
        foreach ($productIds as $index => $productIdRaw) {
            $productId = self::positiveInt($productIdRaw);
            $quantity = is_scalar($quantities[$index] ?? null) ? trim((string) $quantities[$index]) : '';
            if ($productId === null && $quantity === '') continue;
            if ($productId === null || $quantity === '') self::failure(422, 'Dòng sản phẩm chưa hợp lệ.', 'invalid', $orderId, $orderId !== null, 'sales');
            $items[] = ['product_id' => $productId, 'quantity' => $quantity];
        }
        $discount = self::text($_POST, 'discount_bps');
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', $discount) || (int) $discount > 10000) self::failure(422, 'Tỷ lệ chiết khấu không hợp lệ.', 'invalid', $orderId, $orderId !== null, 'sales');
        $note = sanitize_textarea_field(self::text($_POST, 'note'));
        $id = null;
        try {
            $service = self::service($wpdb);
            $id = $orderId === null
                ? $service->createDraft($actor, $customerId, $warehouseId, $items, $note, $discount, '0')
                : $service->updateDraft($actor, $orderId, $version ?? 0, $customerId, $warehouseId, $items, $note, $discount, '0');
            if (self::text($_POST, 'submit_action') === 'submit') $service->transition($actor, $id, 'submit');
            self::redirect('saved', $id);
        } catch (RecordNotFoundException) {
            self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        } catch (BusinessConflictException $failure) {
            self::failure(409, $failure->getMessage(), 'conflict', $id ?? $orderId);
        } catch (DatabaseException $failure) {
            $savedId = $id ?? $orderId;
            if ($failure->getCode() === 1062) self::failure(409, 'Mã đơn đã tồn tại. Hãy tải lại danh sách rồi thử lại.', 'duplicate', $savedId, $orderId !== null, 'sales');
            self::failure(503, 'Không thể lưu đơn lúc này. Vui lòng thử lại sau.', 'unavailable', $savedId, $orderId !== null, 'sales');
        } catch (DomainException|\InvalidArgumentException $failure) {
            $savedId = $id ?? $orderId;
            self::failure(422, $failure->getMessage(), 'invalid', $savedId, $savedId !== null, 'sales');
        } catch (Throwable) {
            self::failure(503, 'Không thể lưu đơn lúc này. Vui lòng thử lại sau.', 'unavailable', $id ?? $orderId);
        }
    }

    public static function transition(): void
    {
        $action = sanitize_key(self::text($_POST, 'transition'));
        $required = match ($action) {
            'approve', 'reject' => 'erp_sales_approve',
            'cancel' => 'erp_sales_cancel',
            'submit' => 'erp_sales_create',
            default => null,
        };
        if ($required === null) self::failure(422, 'Thao tác đơn bán không hợp lệ.', 'invalid');
        CapabilityPolicy::requireCapability($required);
        check_admin_referer('mini_erp_sales_transition');
        $actor = self::actor();
        if (!$actor->can('erp_sales_view_own') && !$actor->can('erp_sales_view_all')) {
            wp_die('Bạn không có quyền xem đơn bán để thực hiện thao tác này.', 'Mini ERP', ['response' => 403]);
        }
        global $wpdb;
        $id = self::postId('order_id');
        if (!$wpdb instanceof wpdb) self::failure(503, 'ERP tạm thời không khả dụng.', 'unavailable');
        if ($id === null) self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        self::requireMutationTarget($actor, $id, 'read');
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

    public static function ship(): void
    {
        CapabilityPolicy::requireCapability('erp_stock_ship');
        check_admin_referer('mini_erp_shipment_post');
        global $wpdb;
        $id = self::postId('order_id');
        if (!$wpdb instanceof wpdb) self::failure(503, 'ERP tạm thời không khả dụng.', 'unavailable');
        if ($id === null) self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        self::requireMutationTarget(self::actor(), $id, 'read');
        $requestKey = sanitize_text_field(self::text($_POST, 'request_key'));
        $rawIds = isset($_POST['sales_item_id']) && is_array($_POST['sales_item_id']) ? array_values(wp_unslash($_POST['sales_item_id'])) : [];
        $rawQty = isset($_POST['ship_quantity']) && is_array($_POST['ship_quantity']) ? array_values(wp_unslash($_POST['ship_quantity'])) : [];
        if (count($rawIds) !== count($rawQty) || count($rawIds) > 100) self::failure(422, 'Danh sách dòng giao hàng không hợp lệ.', 'invalid', $id);
        $lines = [];
        foreach ($rawIds as $index => $rawId) {
            $lineId = self::positiveInt($rawId);
            $quantity = is_scalar($rawQty[$index] ?? null) ? trim((string) $rawQty[$index]) : '';
            if ($lineId === null) self::failure(422, 'Dòng giao hàng không hợp lệ.', 'invalid', $id);
            if ($quantity === '') continue;
            $lines[] = ['sales_item_id' => $lineId, 'quantity' => $quantity];
        }
        try {
            self::service($wpdb)->ship(self::actor(), $id, $requestKey, $lines);
            self::redirect('shipped', $id);
        } catch (RecordNotFoundException) {
            self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        } catch (BusinessConflictException $failure) {
            self::failure(409, $failure->getMessage(), 'conflict', $id);
        } catch (DatabaseException $failure) {
            if ($failure->getCode() === 1062) self::failure(409, 'Yêu cầu giao hàng này đã được ghi nhận.', 'conflict', $id);
            self::failure(503, 'Không thể ghi nhận giao hàng lúc này. Vui lòng thử lại sau.', 'unavailable', $id);
        } catch (DomainException|\InvalidArgumentException $failure) {
            self::failure(422, $failure->getMessage(), 'invalid', $id);
        } catch (Throwable) {
            self::failure(503, 'Không thể ghi nhận giao hàng lúc này. Vui lòng thử lại sau.', 'unavailable', $id);
        }
    }

    public static function render(): void
    {
        $actor = self::actor();
        $canOwn = CapabilityPolicy::allows('erp_sales_view_own');
        $canAll = CapabilityPolicy::allows('erp_sales_view_all');
        $canShip = CapabilityPolicy::allows('erp_stock_ship');
        if (!$canOwn && !$canAll && !$canShip) wp_die(esc_html__('Bạn không có quyền truy cập đơn bán.', 'mini-erp'), '', ['response' => 403]);
        global $wpdb;
        if (!$wpdb instanceof wpdb) wp_die(esc_html__('Cơ sở dữ liệu ERP chưa sẵn sàng.', 'mini-erp'), '', ['response' => 503]);
        $orders = self::table($wpdb, 'erp_sales_orders');
        $customers = self::table($wpdb, 'erp_customers');
        $warehouses = self::table($wpdb, 'erp_warehouses');
        $detailId = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $preflightStatus = self::preflightDetail($actor);
        if ($preflightStatus !== null) {
            wp_die(
                esc_html($preflightStatus === 404 ? 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.' : 'ERP tạm thời không thể xác minh đơn bán.'),
                '',
                ['response' => $preflightStatus]
            );
        }
        $notice = sanitize_key(self::text($_GET, 'notice'));
        echo '<div class="wrap erp-app erp-sales-page' . ($detailId > 0 && !isset($_GET['edit']) ? ' is-order-detail' : '') . '"><h1>Đơn bán hàng</h1>';
        if ($notice !== '') echo '<div class="notice ' . (in_array($notice, ['saved','shipped'], true) ? 'notice-success' : 'notice-error') . '"><p>' . esc_html(self::notice($notice)) . '</p></div>';

        $where = [];
        $args = [];
        if (!$canAll && $canOwn) { $where[] = 'o.created_by = %d'; $args[] = $actor->id; }
        if (!$canAll && !$canOwn && $canShip) {
            if ($actor->warehouseIds === []) $where[] = '1 = 0';
            else { $where[] = 'o.warehouse_id IN (' . implode(',', array_fill(0, count($actor->warehouseIds), '%d')) . ')'; array_push($args, ...$actor->warehouseIds); $where[] = "o.status IN ('CONFIRMED','PARTIALLY_SHIPPED')"; }
        }
        $filter = isset($_GET['status']) ? strtoupper(trim(self::text($_GET, 'status'))) : '';
        if (in_array($filter, ['DRAFT','PENDING_APPROVAL','CONFIRMED','PARTIALLY_SHIPPED','SHIPPED','CANCELLED','CLOSED'], true)) { $where[] = 'o.status = %s'; $args[] = $filter; }
        $predicate = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $page = max(1, min(10000, absint($_GET['paged'] ?? 1)));
        $total = (int) ($args === [] ? $wpdb->get_var("SELECT COUNT(*) FROM {$orders} o{$predicate}") : $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders} o{$predicate}", $args)));
        $pages = max(1, (int) ceil($total / 50)); $page = min($page, $pages);
        $projection = $canShip && !$canAll && !$canOwn
            ? 'o.id,o.order_no,o.warehouse_id,o.status,o.created_at,w.name AS warehouse_name'
            : 'o.id,o.order_no,o.status,o.total_vnd,o.discount_bps,o.created_at,o.created_by,c.name AS customer_name,w.name AS warehouse_name';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT {$projection} FROM {$orders} o INNER JOIN {$warehouses} w ON w.id=o.warehouse_id LEFT JOIN {$customers} c ON c.id=o.customer_id{$predicate} ORDER BY o.created_at DESC,o.id DESC LIMIT %d OFFSET %d", [...$args, 50, ($page - 1) * 50]), ARRAY_A);

        if ($canOwn || $canAll) {
            $editId = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
            self::renderCreateForm($wpdb, $customers, $warehouses, $editId > 0 ? $editId : null, $actor);
        }
        echo '<div class="erp-sales-workspace' . ($detailId > 0 ? ' has-order-detail' : '') . '"><section class="erp-sales-list" aria-labelledby="erp-sales-list-title"><h2 id="erp-sales-list-title">' . esc_html__('Danh sách đơn', 'mini-erp') . '</h2>';
        echo '<form class="erp-table-filters erp-sales-filters" method="get" action="' . esc_url(home_url('/')) . '"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><label for="erp-order-status">Trạng thái</label><select id="erp-order-status" name="status"><option value="">Tất cả</option>';
        foreach (['DRAFT','PENDING_APPROVAL','CONFIRMED','PARTIALLY_SHIPPED','SHIPPED','CANCELLED','CLOSED'] as $status) echo '<option value="' . esc_attr($status) . '"' . selected($filter, $status, false) . '>' . esc_html(self::statusLabel($status)) . '</option>';
        echo '</select><button class="button" type="submit">' . esc_html__('Lọc', 'mini-erp') . '</button></form>';
        $showCommercial = !($canShip && !$canOwn && !$canAll);
        $salesColumnCount = $showCommercial ? 7 : 5;
        echo '<div class="erp-table-scroll erp-orders-scroll"><table class="widefat erp-orders-table"><thead><tr><th scope="col">Mã đơn</th>' . ($showCommercial ? '<th scope="col">Khách hàng</th>' : '') . '<th scope="col">Kho</th><th scope="col">Ngày</th>' . ($showCommercial ? '<th scope="col" class="erp-number">Tổng (VND)</th>' : '') . '<th scope="col">Trạng thái</th><th scope="col"><span class="screen-reader-text">Thao tác</span></th></tr></thead><tbody>';
        foreach (is_array($rows) ? $rows : [] as $row) {
            $url = add_query_arg(['page' => self::PAGE, 'order' => (int) $row['id']], home_url('/'));
            $statusClass = match ((string) $row['status']) {
                'PENDING_APPROVAL' => 'is-warning',
                'CONFIRMED', 'PARTIALLY_SHIPPED' => 'is-info',
                'SHIPPED' => 'is-success',
                'CANCELLED' => 'is-danger',
                default => 'is-neutral',
            };
            echo '<tr><td data-label="Mã đơn"><a class="erp-order-number" href="' . esc_url($url) . '">' . esc_html((string) $row['order_no']) . '</a></td>';
            if ($showCommercial) echo '<td data-label="Khách hàng">' . esc_html((string) $row['customer_name']) . '</td>';
            echo '<td data-label="Kho">' . esc_html((string) $row['warehouse_name']) . '</td><td data-label="Ngày">' . esc_html((string) $row['created_at']) . '</td>';
            if ($showCommercial) echo '<td data-label="Tổng" class="erp-money">' . esc_html(number_format((int) $row['total_vnd'], 0, ',', '.')) . ' VND</td>';
            echo '<td data-label="Trạng thái"><span class="erp-status-pill ' . esc_attr($statusClass) . '">' . esc_html(self::statusLabel((string) $row['status'])) . '</span></td><td data-label="Thao tác"><a class="button" href="' . esc_url($url) . '">' . esc_html__('Mở đơn', 'mini-erp') . '</a></td></tr>';
        }
        if (!is_array($rows) || $rows === []) echo '<tr><td class="erp-table-empty" colspan="' . esc_attr((string) $salesColumnCount) . '">' . esc_html__('Chưa có đơn bán phù hợp.', 'mini-erp') . '</td></tr>';
        echo '</tbody></table></div>';
        self::pagination($page, $pages, $filter);
        echo '</section><aside class="erp-sales-detail-pane" aria-label="' . esc_attr__('Chi tiết đơn bán', 'mini-erp') . '">';
        if ($detailId > 0) {
            self::renderDetail($wpdb, $actor, $detailId, $canShip, $canAll, $canOwn);
        } else {
            echo '<div class="erp-order-detail-empty"><p class="erp-eyebrow">' . esc_html__('Đơn bán hàng', 'mini-erp') . '</p><h2>' . esc_html__('Chọn một đơn hàng', 'mini-erp') . '</h2><p>' . esc_html__('Chọn một đơn trong danh sách để xem mặt hàng, lịch sử giao và các thao tác phù hợp với vai trò của bạn.', 'mini-erp') . '</p></div>';
        }
        echo '</aside></div>';
        echo '</div>';
    }

    /** Return an HTTP status before the frontend shell is emitted for an inaccessible order. */
    public static function preflightDetail(Actor $actor): ?int
    {
        $id = isset($_GET['order']) ? absint($_GET['order']) : 0;
        $editId = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        if ($id < 1 && $editId < 1) {
            self::clearAuthorizedDetail();
            return null;
        }
        if (self::$authorizedDetailId === $id && self::$authorizedEditId === $editId && self::$authorizedDetailActorId === $actor->id && ($id < 1 || self::$authorizedDetail !== null)) {
            return null;
        }
        self::clearAuthorizedDetail();
        if ($id > 0 && $editId > 0 && $id !== $editId) {
            return 404;
        }

        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            return 503;
        }

        $policy = new ReadPolicy();
        $scope = $policy->orderScope($actor, 'sales_orders');
        if ($scope['kind'] === 'none') {
            return 404;
        }

        $orders = self::table($wpdb, 'erp_sales_orders');
        $customers = self::table($wpdb, 'erp_customers');
        $warehouses = self::table($wpdb, 'erp_warehouses');
        if ($editId > 0) {
            if (!$actor->can('erp_sales_create')) {
                return 404;
            }
            $editWhere = ['id = %d', "status = 'DRAFT'"];
            $editArgs = [$editId];
            if (!$actor->can('erp_sales_view_all')) {
                $editWhere[] = 'created_by = %d';
                $editArgs[] = $actor->id;
            }
            try {
                $editableId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$orders} WHERE " . implode(' AND ', $editWhere), $editArgs));
            } catch (Throwable) {
                return 503;
            }
            if ($wpdb->last_error !== '') {
                return 503;
            }
            if ((int) $editableId !== $editId) {
                return 404;
            }
        }
        if ($id < 1) {
            self::$authorizedEditId = $editId;
            self::$authorizedDetailActorId = $actor->id;
            return null;
        }
        $where = ['o.id = %d'];
        $args = [$id];
        if ($scope['kind'] === 'own_sales') {
            $where[] = 'o.created_by = %d';
            $args[] = $scope['user_id'];
        } elseif ($scope['kind'] === 'warehouse_ids') {
            $warehouseIds = $scope['warehouse_ids'] ?? [];
            $statuses = $scope['statuses'] ?? [];
            if ($warehouseIds === [] || $statuses === []) {
                return 404;
            }
            $where[] = 'o.warehouse_id IN (' . implode(',', array_fill(0, count($warehouseIds), '%d')) . ')';
            array_push($args, ...$warehouseIds);
            $where[] = 'o.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
            array_push($args, ...$statuses);
        }

        $warehouseProjection = $scope['kind'] === 'warehouse_ids';
        $projection = $warehouseProjection
            ? 'o.id,o.order_no,o.warehouse_id,o.status,o.created_at,w.name AS warehouse_name'
            : 'o.id,o.order_no,o.customer_id,o.warehouse_id,o.created_by,o.status,o.total_vnd,o.created_at,c.name AS customer_name,w.name AS warehouse_name';
        try {
            $order = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT {$projection} FROM {$orders} o LEFT JOIN {$customers} c ON c.id=o.customer_id INNER JOIN {$warehouses} w ON w.id=o.warehouse_id WHERE " . implode(' AND ', $where),
                    $args
                ),
                ARRAY_A
            );
        } catch (Throwable) {
            return 503;
        }
        if ($wpdb->last_error !== '') {
            return 503;
        }
        if (!is_array($order) || !$policy->canReadSalesOrder($actor, $order)) {
            return 404;
        }

        self::$authorizedDetailId = $id;
        self::$authorizedDetailActorId = $actor->id;
        self::$authorizedEditId = $editId;
        self::$authorizedDetail = $order;
        return null;
    }

    /** Return 404 for an absent or out-of-scope mutation target, before processing its fields. */
    public static function preflightMutationTarget(Actor $actor, int $id, string $operation, ?int $expectedVersion = null): ?int
    {
        if ($id < 1 || !in_array($operation, ['read', 'edit'], true)) return 404;
        if ($operation === 'edit' && !$actor->can('erp_sales_create')) return 404;
        global $wpdb;
        if (!$wpdb instanceof wpdb) return 503;
        try {
            $orders = self::table($wpdb, 'erp_sales_orders');
            $row = $wpdb->get_row($wpdb->prepare("SELECT id,created_by,warehouse_id,status,version FROM {$orders} WHERE id=%d", $id), ARRAY_A);
        } catch (Throwable) {
            return 503;
        }
        if ($wpdb->last_error !== '') return 503;
        if (!is_array($row) || !(new ReadPolicy())->canReadSalesOrder($actor, $row)) return 404;
        if ($operation === 'edit' && (string) $row['status'] !== 'DRAFT') return 404;
        if ($operation === 'edit' && $expectedVersion !== null && (int) $row['version'] !== $expectedVersion) return 409;
        return null;
    }

    private static function clearAuthorizedDetail(): void
    {
        self::$authorizedDetailId = 0;
        self::$authorizedDetailActorId = 0;
        self::$authorizedEditId = 0;
        self::$authorizedDetail = null;
    }

    private static function renderCreateForm(wpdb $db, string $customersTable, string $warehousesTable, ?int $editingId, Actor $actor): void
    {
        $customers = $db->get_results("SELECT id,code,name FROM {$customersTable} WHERE is_active=1 ORDER BY name LIMIT 500", ARRAY_A);
        $warehouses = $db->get_results("SELECT id,code,name FROM {$warehousesTable} WHERE is_active=1 ORDER BY name LIMIT 500", ARRAY_A);
        $productsTable = self::table($db, 'erp_products');
        $editing = null; $existingItems = [];
        if ($editingId !== null) {
            $editing = $db->get_row($db->prepare("SELECT id,customer_id,warehouse_id,created_by,status,discount_bps,note,version FROM " . self::table($db,'erp_sales_orders') . " WHERE id=%d", $editingId), ARRAY_A);
            if (!is_array($editing) || (int)$editing['created_by'] !== $actor->id && !$actor->can('erp_sales_view_all') || $editing['status'] !== 'DRAFT') $editing = null;
            if ($editing !== null) $existingItems = $db->get_results($db->prepare("SELECT product_id,quantity FROM " . self::table($db,'erp_sales_order_items') . " WHERE order_id=%d ORDER BY id LIMIT 21", $editingId), ARRAY_A) ?: [];
        }
        if (count($existingItems) > 20) { echo '<div class="notice notice-error"><p>Đơn này vượt giới hạn 20 dòng nên chưa thể sửa an toàn. Hãy liên hệ quản trị viên.</p></div>'; return; }
        $products = $db->get_results("SELECT id,sku,name,unit,unit_step,sale_price_vnd,is_active FROM {$productsTable} WHERE is_active=1 ORDER BY name LIMIT 500", ARRAY_A);
        if (!is_array($customers) || $customers === [] || !is_array($warehouses) || $warehouses === [] || !is_array($products) || $products === []) { echo '<div class="notice notice-warning"><p>Tạo khách hàng, kho và ít nhất một sản phẩm đang kinh doanh trước khi lập đơn bán.</p></div>'; return; }
        $activeProductIds = array_fill_keys(array_map(static fn(array $product): int => (int) $product['id'], $products), true);
        $preservedIds = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int) $item['product_id'], $existingItems), static fn(int $id): bool => !isset($activeProductIds[$id]))));
        if ($preservedIds !== []) {
            $slots = implode(',', array_fill(0, count($preservedIds), '%d'));
            $preservedProducts = $db->get_results($db->prepare("SELECT id,sku,name,unit,unit_step,sale_price_vnd,is_active FROM {$productsTable} WHERE id IN ({$slots}) ORDER BY name", $preservedIds), ARRAY_A) ?: [];
            $products = array_merge($products, $preservedProducts);
        }
        $open = $editing !== null ? ' open' : '';
        echo '<details class="erp-order-create"' . $open . '><summary class="button button-primary">' . ($editing ? 'Sửa đơn nháp' : 'Tạo đơn bán') . '</summary><form method="post" data-order-form-state="sales" data-order-actor="' . esc_attr((string) $actor->id) . '" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mini_erp_sales_save">';
        wp_nonce_field('mini_erp_sales_save');
        if ($editing) echo '<input type="hidden" name="order_id" value="' . esc_attr((string)$editing['id']) . '"><input type="hidden" name="version" value="' . esc_attr((string)$editing['version']) . '">';
        echo '<div class="erp-customer-lookup"><label>Tìm khách hàng <input type="search" data-customer-search maxlength="100" placeholder="Mã hoặc tên khách hàng" autocomplete="off"></label><label>Khách hàng <select required name="customer_id" data-customer-select><option value="">Chọn khách hàng</option>';
        foreach ($customers as $row) echo '<option value="' . esc_attr((string) $row['id']) . '"' . selected((int)($editing['customer_id']??0),(int)$row['id'],false) . '>' . esc_html($row['code'] . ' · ' . $row['name']) . '</option>';
        echo '</select></label><span class="description" data-customer-search-status aria-live="polite">Tìm theo mã hoặc tên để tra cứu thêm khách hàng.</span></div><p><label>Kho giao <select required name="warehouse_id" data-product-warehouse><option value="">Chọn kho</option>';
        foreach ($warehouses as $row) echo '<option value="' . esc_attr((string) $row['id']) . '"' . selected((int)($editing['warehouse_id']??0),(int)$row['id'],false) . '>' . esc_html($row['code'] . ' · ' . $row['name']) . '</option>';
        echo '</select></label></p><h3>Sản phẩm</h3>';
        $lineCount = max(5, count($existingItems));
        echo '<div class="erp-order-lines" data-order-lines data-max-lines="20" data-line-label="Sản phẩm">';
        for ($i = 0; $i < $lineCount; $i++) {
            echo '<div class="erp-order-line" data-order-line><div class="erp-order-product-field"><label><span data-line-title>Tìm sản phẩm ' . ($i + 1) . '</span> <input type="search" data-product-search maxlength="100" placeholder="Tìm SKU hoặc tên" autocomplete="off"></label><label>Sản phẩm <select name="product_id[]" data-product-select><option value="">— Chọn sản phẩm —</option>';
            $existingProductId = (int)($existingItems[$i]['product_id']??0);
            foreach ($products as $product) {
                $inactiveLabel = (int) $product['is_active'] === 1 ? '' : ' · Ngừng kinh doanh — cần đổi hoặc bỏ dòng này';
                echo '<option value="' . esc_attr((string) $product['id']) . '"' . selected($existingProductId,(int)$product['id'],false) . '>' . esc_html($product['sku'] . ' · ' . $product['name'] . ' · ' . number_format((int) $product['sale_price_vnd'], 0, ',', '.') . ' VND' . $inactiveLabel) . '</option>';
            }
            echo '</select></label><span class="description" data-product-search-status aria-live="polite">Tra cứu theo SKU hoặc tên sản phẩm.</span></div> <label>Số lượng <input name="quantity[]" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,3})?" value="' . esc_attr((string)($existingItems[$i]['quantity']??'')) . '" placeholder="0"></label><button type="button" class="button" data-remove-order-line hidden aria-label="Xóa dòng sản phẩm ' . ($i + 1) . '">Xóa dòng</button></div>';
        }
        echo '</div><div class="erp-order-lines-actions"><button type="button" class="button" data-add-order-line hidden>Thêm sản phẩm</button> <span class="description" data-order-lines-status aria-live="polite">Tối đa 20 dòng sản phẩm.</span></div><p><label>Chiết khấu (basis points) <input name="discount_bps" type="number" min="0" max="10000" step="1" value="' . esc_attr((string)($editing['discount_bps']??0)) . '"></label> <span class="description">100 bps = 1%; đơn vượt ngưỡng chờ Manager duyệt.</span></p><p><label>Ghi chú<br><textarea class="large-text" name="note" maxlength="1000" rows="2">' . esc_textarea((string)($editing['note']??'')) . '</textarea></label></p><p><button class="button" name="submit_action" value="draft">' . ($editing?'Cập nhật nháp':'Lưu nháp') . '</button> <button class="button button-primary" name="submit_action" value="submit">' . ($editing?'Cập nhật và gửi đơn':'Lưu và gửi đơn') . '</button></p></form></details>';
    }

    private static function renderDetail(wpdb $db, Actor $actor, int $id, bool $canShip, bool $canAll, bool $canOwn): void
    {
        $orderItems = self::table($db, 'erp_sales_order_items'); $shipments = self::table($db, 'erp_shipments'); $shipmentItems = self::table($db, 'erp_shipment_items'); $balances = self::table($db, 'erp_stock_balances');
        $order = self::$authorizedDetailId === $id && self::$authorizedDetailActorId === $actor->id ? self::$authorizedDetail : null;
        self::clearAuthorizedDetail();
        if (!is_array($order)) { echo '<div class="notice notice-error"><p>Không tìm thấy đơn hoặc bạn không có quyền truy cập.</p></div>'; return; }
        $canViewCommercial = $canAll || $canOwn;
        $itemProjection = $canViewCommercial
            ? 'id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_price_vnd,quantity,line_total_vnd'
            : 'id,product_id,sku_snapshot,name_snapshot,unit_snapshot,quantity';
        $items = $db->get_results($db->prepare("SELECT {$itemProjection} FROM {$orderItems} WHERE order_id=%d ORDER BY id", $id), ARRAY_A);
        $shipped = $db->get_results($db->prepare("SELECT sales_item_id,SUM(quantity) AS quantity FROM {$shipmentItems} si JOIN {$shipments} sh ON sh.id=si.shipment_id WHERE sh.sales_order_id=%d GROUP BY sales_item_id", $id), ARRAY_A);
        $shippedByLine = []; foreach (is_array($shipped) ? $shipped : [] as $row) $shippedByLine[(int) $row['sales_item_id']] = (string) $row['quantity'];
        $shipmentPage = max(1, min(10000, absint($_GET['shipment_page'] ?? 1)));
        $shipmentCount = (int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$shipments} WHERE sales_order_id=%d", $id));
        $shipmentPages = max(1, (int) ceil($shipmentCount / 50));
        $shipmentPage = min($shipmentPage, $shipmentPages);
        $shipmentRows = $db->get_results($db->prepare("SELECT sh.id,sh.shipment_no,sh.shipped_at,sh.shipped_by,si.sales_item_id,si.quantity,oi.sku_snapshot,oi.unit_snapshot FROM (SELECT id,shipment_no,shipped_at,shipped_by FROM {$shipments} WHERE sales_order_id=%d ORDER BY shipped_at DESC,id DESC LIMIT %d OFFSET %d) sh LEFT JOIN {$shipmentItems} si ON si.shipment_id=sh.id LEFT JOIN {$orderItems} oi ON oi.id=si.sales_item_id ORDER BY sh.shipped_at DESC,sh.id DESC,si.sales_item_id", $id, 50, ($shipmentPage - 1) * 50), ARRAY_A);
        $shipmentHistory = [];
        foreach (is_array($shipmentRows) ? $shipmentRows : [] as $row) {
            $shipmentId = (int) $row['id'];
            if (!isset($shipmentHistory[$shipmentId])) {
                $shipmentHistory[$shipmentId] = ['shipment_no' => (string) $row['shipment_no'], 'shipped_at' => (string) $row['shipped_at'], 'shipped_by' => (int) $row['shipped_by'], 'lines' => []];
            }
            if ($row['sales_item_id'] !== null) {
                $shipmentHistory[$shipmentId]['lines'][] = (string) $row['sku_snapshot'] . ' · ' . (string) $row['quantity'] . ' ' . (string) $row['unit_snapshot'];
            }
        }
        $statusClass = match ((string) $order['status']) {
            'PENDING_APPROVAL' => 'is-warning',
            'CONFIRMED', 'PARTIALLY_SHIPPED' => 'is-info',
            'SHIPPED' => 'is-success',
            'CANCELLED' => 'is-danger',
            default => 'is-neutral',
        };
        echo '<section class="erp-order-detail"><a class="erp-detail-back" href="' . esc_url(add_query_arg(['page' => self::PAGE], home_url('/'))) . '">' . esc_html__('← Danh sách đơn', 'mini-erp') . '</a><header class="erp-order-detail-heading"><div><p class="erp-eyebrow">' . esc_html__('Đơn bán hàng', 'mini-erp') . '</p><h2>' . esc_html((string) $order['order_no']) . '</h2></div><span class="erp-status-pill ' . esc_attr($statusClass) . '">' . esc_html(self::statusLabel((string) $order['status'])) . '</span></header>';
        if ($canViewCommercial) echo '<p>Khách hàng: ' . esc_html((string) $order['customer_name']) . ' · Kho: ' . esc_html((string) $order['warehouse_name']) . ' · Tổng: ' . esc_html(number_format((int) $order['total_vnd'], 0, ',', '.')) . ' VND</p>';
        else echo '<p>Kho: ' . esc_html((string) $order['warehouse_name']) . ' · Số tồn trên tay chỉ để tham khảo; số dư sẽ được khóa và kiểm tra lúc ghi phiếu xuất.</p>';
        echo '<div class="erp-table-scroll"><table class="widefat erp-order-lines-table"><thead><tr><th scope="col">Sản phẩm</th><th scope="col">Đặt</th><th scope="col">Đã giao</th><th scope="col">Còn</th>' . ($canViewCommercial ? '<th scope="col">Đơn giá</th><th scope="col">Thành tiền</th>' : '') . ($canShip ? '<th scope="col">Tồn trên tay*</th><th scope="col">Giao đợt này</th>' : '') . '</tr></thead><tbody>';
        foreach (is_array($items) ? $items : [] as $item) {
            $old = $shippedByLine[(int) $item['id']] ?? '0.000'; $remaining = bcsub((string) $item['quantity'], $old, 3);
            $onHand = $db->get_var($db->prepare("SELECT quantity FROM {$balances} WHERE warehouse_id=%d AND product_id=%d", (int) $order['warehouse_id'], (int) $item['product_id']));
            echo '<tr><td>' . esc_html((string) $item['sku_snapshot'] . ' · ' . (string) $item['name_snapshot']) . '</td><td>' . esc_html((string) $item['quantity'] . ' ' . (string) $item['unit_snapshot']) . '</td><td>' . esc_html($old) . '</td><td>' . esc_html($remaining) . '</td>';
            if ($canViewCommercial) echo '<td>' . esc_html(number_format((int) $item['unit_price_vnd'], 0, ',', '.')) . '</td><td>' . esc_html(number_format((int) $item['line_total_vnd'], 0, ',', '.')) . '</td>';
            if ($canShip) echo '<td>' . esc_html($onHand === null ? '0.000' : (string) $onHand) . '</td><td><input form="erp-shipment-form" type="hidden" name="sales_item_id[]" value="' . esc_attr((string) $item['id']) . '"><input form="erp-shipment-form" name="ship_quantity[]" inputmode="decimal" pattern="[0-9]+(\.[0-9]{1,3})?" placeholder="0" aria-label="Xuất ' . esc_attr((string) $item['sku_snapshot']) . '"></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<h3>Lịch sử phiếu giao hàng</h3><p>Trang ' . esc_html((string) $shipmentPage) . ' / ' . esc_html((string) $shipmentPages) . ' · ' . esc_html(number_format($shipmentCount, 0, ',', '.')) . ' phiếu</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Phiếu giao</th><th>Ngày ghi nhận (Asia/Ho_Chi_Minh)</th><th>Người ghi nhận</th><th>Mặt hàng và số lượng</th></tr></thead><tbody>';
        foreach ($shipmentHistory as $shipment) {
            echo '<tr><td>' . esc_html($shipment['shipment_no']) . '</td><td>' . esc_html(self::localTime($shipment['shipped_at'])) . '</td><td>#' . esc_html((string) $shipment['shipped_by']) . '</td><td>';
            foreach ($shipment['lines'] as $lineIndex => $line) echo ($lineIndex > 0 ? '<br>' : '') . esc_html($line);
            echo '</td></tr>';
        }
        if ($shipmentHistory === []) echo '<tr><td colspan="4">Chưa có phiếu giao hàng.</td></tr>';
        echo '</tbody></table></div>';
        if ($shipmentPages > 1) {
            echo '<nav class="erp-pagination" aria-label="Phân trang phiếu giao hàng">';
            if ($shipmentPage > 1) echo '<a class="button" rel="prev" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'order' => $id, 'shipment_page' => $shipmentPage - 1], home_url('/'))) . '">Trang trước</a> ';
            if ($shipmentPage < $shipmentPages) echo '<a class="button" rel="next" href="' . esc_url(add_query_arg(['page' => self::PAGE, 'order' => $id, 'shipment_page' => $shipmentPage + 1], home_url('/'))) . '">Trang sau</a>';
            echo '</nav>';
        }
        self::renderLinkedInvoices($db, $actor, $id, (string) $order['status'], $shipmentPage);
        if ($canAll || $canOwn) self::renderActions($actor, $order);
        if ((string)$order['status']==='DRAFT' && ((int)$order['created_by']===$actor->id || $actor->can('erp_sales_view_all'))) {
            echo '<a class="button" href="' . esc_url(add_query_arg(['page'=>self::PAGE,'order'=>(int)$order['id'],'edit'=>(int)$order['id']],home_url('/'))) . '">Sửa nội dung đơn</a> ';
        }
        if ($canShip && in_array((string) $order['status'], ['CONFIRMED','PARTIALLY_SHIPPED'], true) && in_array((int) $order['warehouse_id'], $actor->warehouseIds, true)) {
            echo '<form id="erp-shipment-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mini_erp_shipment_post">';
            wp_nonce_field('mini_erp_shipment_post');
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order['id']) . '"><input type="hidden" name="request_key" value="' . esc_attr(wp_generate_uuid4()) . '"><button class="button button-primary">Ghi phiếu giao hàng</button></form>';
        }
        echo '</section>';
    }

    private static function renderLinkedInvoices(wpdb $db, Actor $actor, int $orderId, string $orderStatus, int $page): void
    {
        $scope = (new ReadPolicy())->invoiceScope($actor);
        if (!in_array($scope['kind'], ['all', 'own_sales'], true)) return;

        $invoices = self::table($db, 'erp_invoices');
        $shipments = self::table($db, 'erp_shipments');
        $orders = self::table($db, 'erp_sales_orders');
        $where = 'sh.sales_order_id=%d';
        $args = [$orderId];
        if ($scope['kind'] === 'own_sales') {
            $where .= ' AND so.created_by=%d';
            $args[] = $actor->id;
        }

        try {
            $rows = $db->get_results($db->prepare(
                "SELECT source.id AS shipment_id,source.shipment_no,source.shipped_at,i.id AS invoice_id,i.invoice_no,i.total_vnd,i.status,i.issued_at,i.due_at FROM (SELECT sh.id,sh.shipment_no,sh.shipped_at FROM {$shipments} sh INNER JOIN {$orders} so ON so.id=sh.sales_order_id WHERE {$where} ORDER BY sh.shipped_at DESC,sh.id DESC LIMIT %d OFFSET %d) source LEFT JOIN {$invoices} i ON i.source_type='SHIPMENT' AND i.source_id=source.id ORDER BY source.shipped_at DESC,source.id DESC",
                [...$args, 50, ($page - 1) * 50]
            ), ARRAY_A);
            if (!is_array($rows)) throw new \RuntimeException('Linked invoice query failed.');
        } catch (Throwable) {
            echo '<section class="erp-document-chain"><h3>Hóa đơn từ các phiếu giao</h3><p>Không thể tải liên kết hóa đơn lúc này.</p></section>';
            return;
        }

        $canViewPayments = $actor->can('erp_payments_view') && $actor->cashAccountIds !== [];
        $canIssue = $scope['kind'] === 'all' && $actor->can('erp_invoices_issue') && $actor->can('erp_invoice_sources_view_all');
        $canCreateFromOrder = $canIssue && in_array($orderStatus, ['PARTIALLY_SHIPPED', 'SHIPPED', 'CLOSED'], true);
        echo '<section class="erp-document-chain"><h3>Hóa đơn từ các phiếu giao</h3><p>Mỗi đợt giao có trạng thái lập hóa đơn riêng. Thông tin giao dịch chỉ mở trong phạm vi quỹ được gán.</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Phiếu giao</th><th>Hóa đơn</th><th>Phát hành / hạn</th><th>Tổng</th><th>Trạng thái</th><th>Liên kết</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html((string) $row['shipment_no']) . '</td>';
            if ($row['invoice_id'] !== null) {
                $invoiceUrl = add_query_arg(['page' => 'mini-erp-invoices', 'invoice' => (int) $row['invoice_id']], home_url('/'));
                $status = match ((string) $row['status']) {
                    'ISSUED' => 'Đã phát hành',
                    'VOID' => 'Đã hủy',
                    default => (string) $row['status'],
                };
                echo '<td><a href="' . esc_url($invoiceUrl) . '">' . esc_html((string) $row['invoice_no']) . '</a></td><td>' . esc_html(self::localTime((string) $row['issued_at'])) . ' / ' . esc_html(substr((string) $row['due_at'], 0, 10)) . '</td><td>' . esc_html(number_format((int) $row['total_vnd'], 0, ',', '.')) . ' ₫</td><td>' . esc_html($status) . '</td><td>';
            } else {
                echo '<td>Chưa lập</td><td>—</td><td>—</td><td>Chưa phát hành</td><td>';
                if ($canCreateFromOrder) {
                    $issueUrl = add_query_arg(['page' => 'mini-erp-invoices', 'source_type' => 'SHIPMENT', 'source_id' => (int) $row['shipment_id']], home_url('/')) . '#erp-invoice-sources';
                    echo '<a class="button" href="' . esc_url($issueUrl) . '">Lập hóa đơn</a>';
                }
            }
            if ($row['invoice_id'] !== null && $canViewPayments) {
                $paymentsUrl = add_query_arg(['page' => 'mini-erp-payments', 'q' => (string) $row['invoice_no']], home_url('/'));
                echo '<a class="button" href="' . esc_url($paymentsUrl) . '">Giao dịch trong quỹ được gán</a>';
            } elseif ($row['invoice_id'] !== null) {
                echo '—';
            }
            echo '</td></tr>';
        }
        if ($rows === []) echo '<tr><td colspan="6">Chưa có phiếu giao để lập hóa đơn.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private static function renderActions(Actor $actor, array $order): void
    {
        $status = (string) $order['status']; $creatorId = (int) $order['created_by']; $canManage = $actor->can('erp_sales_view_all');
        $actions = [];
        if ($status === 'DRAFT' && ($creatorId === $actor->id || $canManage)) $actions[] = ['submit','Gửi duyệt / xác nhận'];
        if ($status === 'PENDING_APPROVAL' && $canManage && $creatorId !== $actor->id) { $actions[] = ['approve','Duyệt']; $actions[] = ['reject','Trả về nháp']; }
        if (in_array($status, ['DRAFT','PENDING_APPROVAL','CONFIRMED'], true) && ($creatorId === $actor->id || $canManage)) $actions[] = ['cancel','Đóng đơn'];
        if ($status === 'PARTIALLY_SHIPPED' && $canManage) $actions[] = ['cancel','Đóng phần còn lại'];
        foreach ($actions as [$action, $label]) {
            echo '<form class="erp-inline-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mini_erp_sales_transition">';
            wp_nonce_field('mini_erp_sales_transition'); echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order['id']) . '"><input type="hidden" name="transition" value="' . esc_attr($action) . '">';
            if ($action === 'cancel') echo '<label>Lý do <input name="reason" required maxlength="1000"></label> ';
            echo '<button class="button">' . esc_html($label) . '</button></form>';
        }
    }

    private static function service(wpdb $db): SalesOrderService { return new SalesOrderService($db, new TransactionManager($db), new AuditLogger()); }
    private static function actor(): Actor { return CapabilityPolicy::currentActor() ?? throw new DomainException('Phiên ERP không hợp lệ.'); }
    private static function requireMutationTarget(Actor $actor, int $id, string $operation, ?int $expectedVersion = null): void
    {
        $status = self::preflightMutationTarget($actor, $id, $operation, $expectedVersion);
        if ($status === 404) self::failure(404, 'Không tìm thấy đơn hoặc bạn không có quyền truy cập.');
        if ($status === 409) self::failure(409, 'Đơn đã được cập nhật. Hãy mở lại bản nháp mới nhất trước khi lưu.', 'conflict', $id, true, 'sales');
        if ($status === 503) self::failure(503, 'ERP tạm thời không thể xác minh đơn bán.', 'unavailable');
    }
    private static function table(wpdb $db, string $suffix): string { if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $db->prefix) || !in_array($suffix, ['erp_sales_orders','erp_sales_order_items','erp_stock_balances','erp_stock_movements','erp_shipments','erp_shipment_items','erp_invoices','erp_customers','erp_warehouses','erp_products'], true)) throw new DomainException('Cấu hình bảng không hợp lệ.'); return '`' . $db->prefix . $suffix . '`'; }
    private static function text(array $source, string $key): string { return isset($source[$key]) && is_scalar($source[$key]) ? (string) wp_unslash((string) $source[$key]) : ''; }
    private static function postId(string $key): ?int { return self::positiveInt(self::text($_POST, $key)); }
    private static function positiveInt(mixed $value): ?int { $value = is_scalar($value) ? (string) $value : ''; return preg_match('/\A[1-9][0-9]*\z/D', $value) && (string) (int) $value === $value ? (int) $value : null; }
    private static function redirect(string $notice, ?int $id = null, bool $edit = false): never { $args = ['page' => self::PAGE, 'notice' => $notice]; if ($id !== null) { $args['order'] = $id; if ($edit) $args['edit'] = $id; } wp_safe_redirect(add_query_arg($args, home_url('/')), 303); exit; }
    private static function failure(int $status, string $message, string $notice = '', ?int $id = null, bool $edit = false, string $recoverForm = ''): never
    {
        $body = '<p>' . esc_html($message) . '</p>';
        if ($notice !== '') {
            $args = ['page' => self::PAGE, 'notice' => $notice];
            if ($recoverForm !== '') $args['recover_form'] = $recoverForm;
            if ($id !== null) { $args['order'] = $id; if ($edit) $args['edit'] = $id; }
            $url = add_query_arg($args, home_url('/'));
            $body .= '<p><a href="' . esc_url($url) . '">Quay lại đơn bán</a></p>';
        }
        wp_die($body, 'Mini ERP', ['response' => $status]);
        exit;
    }
    private static function pagination(int $page, int $pages, string $filter): void { if ($pages < 2) return; echo '<p class="tablenav-pages">'; for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) echo '<a class="button" href="' . esc_url(add_query_arg(['page'=>self::PAGE,'paged'=>$i,'status'=>$filter], home_url('/'))) . '">' . esc_html((string) $i) . '</a> '; echo '</p>'; }
    private static function localTime(string $utc): string { $timestamp = strtotime($utc . ' UTC'); return $timestamp === false ? $utc : wp_date('Y-m-d H:i', $timestamp, new \DateTimeZone('Asia/Ho_Chi_Minh')); }
    private static function statusLabel(string $status): string { return ['DRAFT'=>'Nháp','PENDING_APPROVAL'=>'Chờ duyệt','CONFIRMED'=>'Đã xác nhận','PARTIALLY_SHIPPED'=>'Giao một phần','SHIPPED'=>'Đã giao đủ','CANCELLED'=>'Đã hủy','CLOSED'=>'Đã đóng'][$status] ?? $status; }
    private static function notice(string $notice): string { return ['saved'=>'Đã lưu đơn bán.','shipped'=>'Đã ghi phiếu giao hàng và cập nhật kho.','invalid'=>'Thông tin không hợp lệ.','duplicate'=>'Mã chứng từ đã tồn tại.','conflict'=>'Trạng thái đơn đã thay đổi. Hãy tải lại.','stock_error'=>'Không thể ghi phiếu: kiểm tra quyền, trạng thái và số tồn.','unavailable'=>'ERP hiện chưa sẵn sàng.'][$notice] ?? ''; }
}
