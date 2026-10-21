<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\PartyService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use Throwable;
use wpdb;

/** Permission-aware WordPress screens for customers, suppliers, and warehouses. */
final class PartyController
{
    private const CONFIG = [
        'customer' => ['page' => 'mini-erp-customers', 'table' => 'erp_customers', 'view' => 'erp_customers_manage', 'manage' => 'erp_customers_manage', 'title' => 'Khách hàng'],
        'supplier' => ['page' => 'mini-erp-suppliers', 'table' => 'erp_suppliers', 'view' => 'erp_suppliers_view', 'manage' => 'erp_suppliers_manage', 'title' => 'Nhà cung cấp'],
        'warehouse' => ['page' => 'mini-erp-warehouses', 'table' => 'erp_warehouses', 'view' => 'erp_warehouses_view', 'manage' => 'erp_warehouses_manage', 'title' => 'Kho hàng'],
    ];

    public static function saveCustomer(): void { self::save('customer'); }
    public static function saveSupplier(): void { self::save('supplier'); }
    public static function saveWarehouse(): void { self::save('warehouse'); }
    public static function customerStatus(): void { self::setActive('customer'); }
    public static function supplierStatus(): void { self::setActive('supplier'); }
    public static function warehouseStatus(): void { self::setActive('warehouse'); }
    public static function customers(): void { self::render('customer'); }
    public static function suppliers(): void { self::render('supplier'); }
    public static function warehouses(): void { self::render('warehouse'); }

    private static function save(string $entity): void
    {
        $config = self::config($entity);
        CapabilityPolicy::requireCapability($config['manage']);
        check_admin_referer('mini_erp_' . $entity . '_save');
        global $wpdb;
        $id = self::postId('id');
        if (array_key_exists('id', $_POST) && $id === null) self::redirect($config, 'invalid');
        if (!$wpdb instanceof wpdb) self::redirectWithRecovery($config, 'unavailable', $entity, $id);
        $code = strtoupper(sanitize_text_field(self::text($_POST, 'code')));
        $name = sanitize_text_field(self::text($_POST, 'name'));
        if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D', $code) || self::length($name) < 1 || self::length($name) > 191) {
            self::redirectWithRecovery($config, 'invalid', $entity, $id);
        }
        $values = ['code' => $code, 'name' => $name];
        if ($entity !== 'warehouse') {
            $rawEmail = sanitize_text_field(self::text($_POST, 'email'));
            $email = sanitize_email($rawEmail);
            if ($rawEmail !== '' && ($email === '' || !is_email($email))) self::redirectWithRecovery($config, 'invalid', $entity, $id);
            $values += [
                'tax_id' => mb_substr(sanitize_text_field(self::text($_POST, 'tax_id')), 0, 64),
                'phone' => mb_substr(sanitize_text_field(self::text($_POST, 'phone')), 0, 32),
                'email' => mb_substr($email, 0, 254),
                'address' => mb_substr(sanitize_textarea_field(self::text($_POST, 'address')), 0, 1000),
            ];
        }
        try {
            self::service($wpdb)->save(self::actor(), $entity, $values, $id, isset($_POST['updated_at']) ? sanitize_text_field(self::text($_POST, 'updated_at')) : null);
            self::redirectWithRecovery($config, 'saved', $entity, $id);
        } catch (DatabaseException $failure) {
            self::redirectWithRecovery($config, $failure->getCode() === 1062 ? 'duplicate' : 'unavailable', $entity, $id);
        } catch (DomainException $failure) {
            self::redirectWithRecovery($config, str_contains($failure->getMessage(), 'thay đổi') ? 'conflict' : 'invalid', $entity, $id);
        } catch (Throwable) {
            self::redirectWithRecovery($config, 'unavailable', $entity, $id);
        }
    }

    private static function setActive(string $entity): void
    {
        $config = self::config($entity);
        CapabilityPolicy::requireCapability($config['manage']);
        check_admin_referer('mini_erp_' . $entity . '_status');
        global $wpdb;
        if (!$wpdb instanceof wpdb) self::redirect($config, 'unavailable');
        $id = self::postId('id');
        if ($id === null) self::redirect($config, 'invalid');
        try {
            self::service($wpdb)->setActive(self::actor(), $entity, $id, self::text($_POST, 'active') === '1', sanitize_text_field(self::text($_POST, 'updated_at')));
            self::redirect($config, 'saved');
        } catch (Throwable) {
            self::redirect($config, 'conflict');
        }
    }

    private static function render(string $entity): void
    {
        $config = self::config($entity);
        CapabilityPolicy::requireCapability($config['view']);
        $actor = self::actor();
        global $wpdb;
        if (!$wpdb instanceof wpdb) wp_die(esc_html__('Cơ sở dữ liệu ERP chưa sẵn sàng.', 'mini-erp'), '', ['response' => 503]);
        $table = self::table($wpdb, $config['table']);
        $canManage = $actor->can($config['manage']);
        $search = mb_substr(sanitize_text_field(self::text($_GET, 'q')), 0, 100);
        $where = [];
        $args = [];
        if (!$canManage) $where[] = 'is_active = 1';
        if ($entity === 'warehouse' && !$canManage) {
            $ids = $actor->warehouseIds;
            if ($ids === []) $where[] = '1 = 0';
            else { $where[] = 'id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')'; array_push($args, ...$ids); }
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(code LIKE %s OR name LIKE %s)';
            array_push($args, $like, $like);
        }
        $predicate = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) $wpdb->get_var($args === [] ? "SELECT COUNT(*) FROM {$table}{$predicate}" : $wpdb->prepare("SELECT COUNT(*) FROM {$table}{$predicate}", $args));
        $pages = max(1, (int) ceil($total / 50));
        $page = min(max(1, absint($_GET['paged'] ?? 1)), $pages);
        $fields = $entity === 'warehouse' ? 'id, code, name, is_active, updated_at' : 'id, code, name, tax_id, phone, email, address, is_active, updated_at';
        $listArgs = [...$args, 50, ($page - 1) * 50];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT {$fields} FROM {$table}{$predicate} ORDER BY name ASC, id ASC LIMIT %d OFFSET %d", $listArgs), ARRAY_A);
        $editing = null;
        $editId = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        if ($canManage && $editId > 0) $editing = $wpdb->get_row($wpdb->prepare("SELECT {$fields} FROM {$table} WHERE id = %d", $editId), ARRAY_A);

        echo '<div class="wrap erp-app"><h1>' . esc_html($config['title']) . '</h1>';
        $notice = sanitize_key(self::text($_GET, 'notice'));
        if ($notice !== '') echo '<div class="notice ' . ($notice === 'saved' ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html(self::notice($notice)) . '</p></div>';
        echo '<form class="erp-table-filters" method="get" action="' . esc_url(home_url('/')) . '"><input type="hidden" name="page" value="' . esc_attr($config['page']) . '"><label for="erp-master-search">Tìm mã hoặc tên <input id="erp-master-search" type="search" name="q" value="' . esc_attr($search) . '" maxlength="100"></label><button class="button">Tìm</button></form>';
        echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>Mã</th><th>Tên</th>';
        if ($entity !== 'warehouse') echo '<th>MST</th><th>Điện thoại</th><th>Email</th><th>Địa chỉ</th>';
        echo '<th>Trạng thái</th>' . ($canManage ? '<th>Thao tác</th>' : '') . '</tr></thead><tbody>';
        foreach (is_array($rows) ? $rows : [] as $row) {
            echo '<tr><td>' . esc_html((string) $row['code']) . '</td><td>' . esc_html((string) $row['name']) . '</td>';
            if ($entity !== 'warehouse') echo '<td>' . esc_html((string) $row['tax_id']) . '</td><td>' . esc_html((string) $row['phone']) . '</td><td>' . esc_html((string) $row['email']) . '</td><td>' . esc_html((string) $row['address']) . '</td>';
            echo '<td>' . ((int) $row['is_active'] === 1 ? 'Đang dùng' : 'Ngừng dùng') . '</td>';
            if ($canManage) {
                $editUrl = add_query_arg(['page' => $config['page'], 'edit' => (int) $row['id']], home_url('/'));
                echo '<td><a href="' . esc_url($editUrl) . '">Sửa</a> · ';
                self::statusForm($entity, (int) $row['id'], (string) $row['updated_at'], (int) $row['is_active'] === 1);
                echo '</td>';
            }
            echo '</tr>';
        }
        if (!is_array($rows) || $rows === []) echo '<tr><td colspan="' . ($entity === 'warehouse' ? ($canManage ? '4' : '3') : ($canManage ? '8' : '7')) . '">Chưa có dữ liệu phù hợp.</td></tr>';
        echo '</tbody></table></div>';
        self::pagination($page, $pages, $config['page'], $search);
        if ($canManage) self::form($entity, $config, is_array($editing) ? $editing : null, $actor->id);
        echo '</div>';
    }

    private static function form(string $entity, array $config, ?array $editing, int $actorId): void
    {
        $editingNow = $editing !== null;
        $scope = self::recoveryScope($editingNow ? (int) $editing['id'] : null);
        echo '<hr><h2>' . ($editingNow ? 'Sửa' : 'Thêm') . ' ' . esc_html(mb_strtolower($config['title'])) . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-master-form="' . esc_attr($entity) . '" data-master-actor="' . esc_attr((string) $actorId) . '" data-master-scope="' . esc_attr($scope) . '"><input type="hidden" name="action" value="mini_erp_' . esc_attr($entity) . '_save">';
        wp_nonce_field('mini_erp_' . $entity . '_save');
        if ($editingNow) echo '<input type="hidden" name="id" value="' . esc_attr((string) $editing['id']) . '"><input type="hidden" name="updated_at" value="' . esc_attr((string) $editing['updated_at']) . '">';
        self::input('code', 'Mã', $editing['code'] ?? '', 64, true);
        self::input('name', 'Tên', $editing['name'] ?? '', 191, true);
        if ($entity !== 'warehouse') {
            self::input('tax_id', 'Mã số thuế', $editing['tax_id'] ?? '', 64, false);
            self::input('phone', 'Điện thoại', $editing['phone'] ?? '', 32, false);
            self::input('email', 'Email', $editing['email'] ?? '', 254, false, 'email');
            echo '<p><label for="erp-address">Địa chỉ</label><br><textarea id="erp-address" name="address" maxlength="1000" rows="3" class="large-text">' . esc_textarea((string) ($editing['address'] ?? '')) . '</textarea></p>';
        }
        submit_button($editingNow ? 'Lưu thay đổi' : 'Thêm mới');
        echo '</form>';
    }

    private static function input(string $name, string $label, mixed $value, int $max, bool $required, string $type = 'text'): void
    {
        echo '<p><label for="erp-' . esc_attr($name) . '">' . esc_html($label) . '</label><br><input class="regular-text" id="erp-' . esc_attr($name) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" maxlength="' . esc_attr((string) $max) . '" value="' . esc_attr((string) $value) . '"' . ($required ? ' required' : '') . '></p>';
    }

    private static function statusForm(string $entity, int $id, string $updatedAt, bool $active): void
    {
        echo '<form class="erp-inline-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mini_erp_' . esc_attr($entity) . '_status">';
        wp_nonce_field('mini_erp_' . $entity . '_status');
        echo '<input type="hidden" name="id" value="' . esc_attr((string) $id) . '"><input type="hidden" name="updated_at" value="' . esc_attr($updatedAt) . '"><input type="hidden" name="active" value="' . ($active ? '0' : '1') . '"><button class="button-link">' . ($active ? 'Ngừng dùng' : 'Kích hoạt') . '</button></form>';
    }

    private static function pagination(int $page, int $pages, string $slug, string $search): void
    {
        if ($pages < 2) return;
        echo '<p class="tablenav-pages">';
        for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
            $url = add_query_arg(array_filter(['page' => $slug, 'paged' => $i, 'q' => $search]), home_url('/'));
            echo ($i === $page ? '<strong>' : '') . '<a class="button" href="' . esc_url($url) . '">' . esc_html((string) $i) . '</a>' . ($i === $page ? '</strong>' : '') . ' ';
        }
        echo '</p>';
    }

    private static function service(wpdb $db): PartyService { return new PartyService($db, new TransactionManager($db), new AuditLogger()); }
    private static function actor(): Actor { return CapabilityPolicy::currentActor() ?? throw new DomainException('Phiên ERP không hợp lệ.'); }
    private static function config(string $entity): array { return self::CONFIG[$entity] ?? throw new DomainException('Loại dữ liệu không hợp lệ.'); }
    private static function table(wpdb $db, string $suffix): string { if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $db->prefix)) throw new DomainException('Cấu hình bảng không hợp lệ.'); return '`' . $db->prefix . $suffix . '`'; }
    private static function text(array $source, string $key): string { return isset($source[$key]) && is_scalar($source[$key]) ? (string) wp_unslash((string) $source[$key]) : ''; }
    private static function postId(string $key): ?int { $value = self::text($_POST, $key); if ($value === '') return null; return preg_match('/\A[1-9][0-9]*\z/D', $value) && (string) (int) $value === $value ? (int) $value : null; }
    private static function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
    private static function redirect(array $config, string $notice): never { wp_safe_redirect(add_query_arg(['page' => $config['page'], 'notice' => $notice], home_url('/')), 303); exit; }
    private static function recoveryScope(?int $id): string { return $id === null ? 'new' : 'id-' . $id; }
    private static function redirectWithRecovery(array $config, string $notice, string $entity, ?int $id): never
    {
        $args = ['page' => $config['page'], 'notice' => $notice, 'recover_form' => $entity, 'recover_scope' => self::recoveryScope($id)];
        if ($notice !== 'saved' && $id !== null) $args['edit'] = $id;
        wp_safe_redirect(add_query_arg($args, home_url('/')), 303);
        exit;
    }
    private static function notice(string $notice): string { return ['saved' => 'Đã lưu thay đổi.', 'invalid' => 'Dữ liệu không hợp lệ.', 'duplicate' => 'Mã này đã tồn tại.', 'conflict' => 'Bản ghi đã thay đổi. Hãy tải lại.', 'unavailable' => 'Không thể lưu lúc này.'][ $notice] ?? ''; }
}
