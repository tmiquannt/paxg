<?php
declare(strict_types=1);

namespace MiniERP\Security;

use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Frontend\AppRouter;
use Throwable;

/** ERP account creation and transactional access changes. */
final class UserAccessController
{
    private const USERS_PER_PAGE = 50;

    /** Keep ERP role assignment inside the guarded access screens with enabled state, scope, and audit handling. */
    public static function hideErpRolesFromCoreRoleDropdown(array $roles): array
    {
        foreach (array_keys(RolesInstaller::definitions()) as $erpRole) {
            unset($roles[$erpRole]);
        }
        return $roles;
    }

    /** One-time trust-boundary entry point exposed only to WordPress technical administrators. */
    public static function renderBootstrap(): void
    {
        if (!current_user_can('manage_options') || !function_exists('get_users')) {
            wp_die(esc_html__('Bạn không có quyền cấp vai trò ERP ban đầu.', 'mini-erp'), '', ['response' => 403]);
        }
        [$users, $page, $pages] = self::usersPage();
        $systemAdminIds = SystemAdminAssignment::assignedUserIds();
        echo '<div class="wrap erp-bootstrap-page"><h1>' . esc_html__('Cấp vai trò ERP', 'mini-erp') . '</h1>';
        echo '<p>' . esc_html__('Tài khoản WordPress Administrator chỉ được dùng trang này để gán vai trò ERP. Hãy tạo tài khoản người dùng riêng trong WordPress rồi cấp ERP System Admin; tài khoản kỹ thuật không nhận quyền nghiệp vụ ERP.', 'mini-erp') . '</p>';
        if (count($systemAdminIds) > 1) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Đang có nhiều tài khoản ERP System Admin. Giữ lại một tài khoản; với tài khoản còn lại, chọn “Không có quyền ERP” rồi lưu. Sau đó ERP System Admin có thể cấp vai trò nghiệp vụ cho các tài khoản khác.', 'mini-erp') . '</p></div>';
        } elseif (count($systemAdminIds) === 1) {
            echo '<div class="notice notice-info"><p>' . esc_html__('ERP System Admin đã được gán cho một tài khoản. Trang này vẫn cho phép thu hồi quyền trong trường hợp cần khôi phục; hãy dùng màn Người dùng ERP để gán vai trò nghiệp vụ cho các tài khoản khác.', 'mini-erp') . '</p></div>';
        }
        if (isset($_GET['erp_access_saved'])) echo '<div class="notice notice-success"><p>' . esc_html__('Đã cập nhật vai trò ERP.', 'mini-erp') . '</p></div>';
        elseif (isset($_GET['system_admin_conflict'])) echo '<div class="notice notice-error"><p>' . esc_html__('Chỉ được có một ERP System Admin. Hãy hạ role của tài khoản hiện đang giữ quyền rồi thử lại.', 'mini-erp') . '</p></div>';
        elseif (isset($_GET['erp_access_error'])) echo '<div class="notice notice-error"><p>' . esc_html__('Không thể cập nhật vai trò ERP.', 'mini-erp') . '</p></div>';
        echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>' . esc_html__('Tài khoản WordPress', 'mini-erp') . '</th><th>' . esc_html__('Vai trò ban đầu', 'mini-erp') . '</th><th>' . esc_html__('ERP hoạt động', 'mini-erp') . '</th><th>' . esc_html__('Lưu', 'mini-erp') . '</th></tr></thead><tbody>';
        foreach ($users as $user) {
            if (in_array('administrator', (array) $user->roles, true) || (int) $user->ID === (int) get_current_user_id()) continue;
            $id = (int) $user->ID; $form = 'mini-erp-bootstrap-' . $id;
            echo '<tr><td><form id="' . esc_attr($form) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('mini_erp_bootstrap_user_access_' . $id, '_mini_erp_nonce');
            echo '<input type="hidden" name="action" value="mini_erp_bootstrap_user_access_save"><input type="hidden" name="user_id" value="' . $id . '"><strong>' . esc_html($user->display_name) . '</strong><br><small>' . esc_html($user->user_email) . '</small></form></td><td>';
            $isSystemAdmin = in_array('erp_system_admin', (array) $user->roles, true);
            $adminAssignedElsewhere = array_diff($systemAdminIds, [$id]) !== [];
            echo '<select form="' . esc_attr($form) . '" name="erp_role"><option value="">' . esc_html__('Không có quyền ERP', 'mini-erp') . '</option><option value="erp_system_admin" ' . selected($isSystemAdmin ? 'erp_system_admin' : '', 'erp_system_admin', false) . disabled($adminAssignedElsewhere && !$isSystemAdmin, true, false) . '>' . esc_html__('ERP System Admin', 'mini-erp') . '</option></select></td><td><input form="' . esc_attr($form) . '" type="checkbox" name="enabled" value="1" ' . checked(get_user_meta($id, '_mini_erp_enabled', true), '1', false) . ' aria-label="' . esc_attr__('ERP hoạt động', 'mini-erp') . '"></td><td><button form="' . esc_attr($form) . '" class="button" type="submit">' . esc_html__('Lưu', 'mini-erp') . '</button></td></tr>';
        }
        if ($users === []) echo '<tr><td colspan="4">' . esc_html__('Chưa có tài khoản WordPress.', 'mini-erp') . '</td></tr>';
        echo '</tbody></table></div>';
        self::renderUserPagination($page, $pages, true);
        echo '<p class="description">' . esc_html__('Chỉ tài khoản thường xuất hiện tại đây. Sau bước bootstrap, ERP System Admin quản lý vai trò nghiệp vụ và phạm vi kho/quỹ trong màn Người dùng ERP.', 'mini-erp') . '</p></div>';
    }

    public static function saveBootstrap(): void
    {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Bạn không có quyền cấp vai trò ERP.', 'mini-erp'), '', ['response' => 403]);
        $userId = isset($_POST['user_id']) ? filter_var(wp_unslash($_POST['user_id']), FILTER_VALIDATE_INT) : false;
        if ($userId === false || $userId < 1) self::bootstrapRedirect(false);
        check_admin_referer('mini_erp_bootstrap_user_access_' . $userId, '_mini_erp_nonce');
        $target = get_userdata($userId); $rawRole = wp_unslash($_POST['erp_role'] ?? ''); $role = is_string($rawRole) ? sanitize_key($rawRole) : '';
        $enabled = isset($_POST['enabled']) && wp_unslash($_POST['enabled']) === '1';
        if (!$target || $userId === (int) get_current_user_id() || in_array('administrator', (array) $target->roles, true)
            || !in_array($role, ['', 'erp_system_admin'], true) || ($enabled && $role === '')) self::bootstrapRedirect(false);
        global $wpdb; $erpRoles = array_keys(RolesInstaller::definitions()); $actorId = (int) get_current_user_id();
        try {
            $beforeRoles = array_values(array_intersect($erpRoles, (array) $target->roles));
            self::saveWithRoleLock($wpdb, function (TransactionManager $tx) use ($wpdb, $target, $erpRoles, $role, $enabled, $userId, $actorId, $beforeRoles): void {
                if ($role === 'erp_system_admin') {
                    SystemAdminAssignment::assertSoleAssignee($tx, $wpdb, $userId);
                }
                foreach ($erpRoles as $existingRole) if (in_array($existingRole, (array) $target->roles, true)) $target->remove_role($existingRole);
                if ($role !== '') $target->add_role($role);
                update_user_meta($userId, '_mini_erp_enabled', $enabled ? '1' : '0');
                update_user_meta($userId, '_mini_erp_warehouse_ids', []);
                update_user_meta($userId, '_mini_erp_cash_account_ids', []);
                $assigned = array_values(array_intersect($erpRoles, (array) $target->roles));
                if (($role === '' ? $assigned !== [] : $assigned !== [$role])
                    || get_user_meta($userId, '_mini_erp_enabled', true) !== ($enabled ? '1' : '0')) throw new \RuntimeException('ERP bootstrap role did not persist.');
                (new AuditLogger())->record($tx, self::requestId(), $actorId, 'role.bootstrap', 'user', $userId, 'success',
                    ['role' => $beforeRoles === [] ? 'none' : (count($beforeRoles) === 1 ? $beforeRoles[0] : 'ambiguous')],
                    ['role' => $role === '' ? 'none' : $role, 'is_active' => $enabled ? 1 : 0, 'warehouse_scope_count' => 0, 'cash_scope_count' => 0]);
            });
            if (function_exists('clean_user_cache')) clean_user_cache($userId);
        } catch (SystemAdminAssignmentConflict) {
            if (function_exists('clean_user_cache')) clean_user_cache($userId);
            self::bootstrapRedirect(false, true);
        } catch (Throwable) {
            if (function_exists('clean_user_cache')) clean_user_cache($userId);
            self::bootstrapRedirect(false);
        }
        self::bootstrapRedirect(true);
    }

    public static function render(): void
    {
        CapabilityPolicy::requireCapability('erp_users_view');
        if (!function_exists('get_users')) {
            wp_die(esc_html__('WordPress user APIs are unavailable.', 'mini-erp'), '', ['response' => 500]);
        }

        [$users, $page, $pages] = self::usersPage();
        $warehouseOptions = self::scopeOptions('erp_warehouses');
        $cashAccountOptions = self::scopeOptions('erp_cash_accounts');
        $actor = CapabilityPolicy::currentActor();
        $systemAdminIds = SystemAdminAssignment::assignedUserIds();
        if ($actor === null) {
            wp_die(esc_html__('Phiên ERP không hợp lệ.', 'mini-erp'), '', ['response' => 403]);
        }
        $canManageUsers = current_user_can('erp_users_manage') && current_user_can('erp_roles_manage');
        $isCreateMode = $canManageUsers && isset($_GET['erp_create_user']) && is_scalar($_GET['erp_create_user'])
            && wp_unslash((string) $_GET['erp_create_user']) === '1';
        $usersById = [];
        foreach ($users as $user) {
            if (!in_array('administrator', (array) $user->roles, true)) {
                $usersById[(int) $user->ID] = $user;
            }
        }
        $requestedUserId = $_GET['recover_user_id'] ?? $_GET['erp_user_id'] ?? 0;
        $selectedUserId = is_scalar($requestedUserId) ? absint(wp_unslash((string) $requestedUserId)) : 0;
        $hasExplicitSelection = array_key_exists('recover_user_id', $_GET) || array_key_exists('erp_user_id', $_GET);
        if (!$isCreateMode && !isset($usersById[$selectedUserId]) && (!$hasExplicitSelection || $selectedUserId !== 0)) {
            $selectedUserId = $usersById === [] ? 0 : (int) array_key_first($usersById);
        }
        $selectedUser = $isCreateMode ? null : ($usersById[$selectedUserId] ?? null);

        echo '<div class="wrap erp-app erp-users-page' . ($selectedUser instanceof \WP_User || $isCreateMode ? ' has-selected-user' : '') . '"><h1>' . esc_html__('Người dùng ERP', 'mini-erp') . '</h1>';
        if (isset($_GET['erp_user_created'])) {
            if (isset($_GET['erp_user_invite_error'])) {
                echo '<div class="notice notice-warning"><p>' . esc_html__('Đã tạo tài khoản và gán quyền ERP nhưng email hướng dẫn chưa gửi được. Người dùng có thể chọn “Quên mật khẩu” tại màn đăng nhập để đặt mật khẩu.', 'mini-erp') . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Đã tạo tài khoản và gán quyền ERP. Hệ thống đã gửi hướng dẫn đặt mật khẩu tới email người dùng; nếu chưa nhận, người dùng có thể chọn “Quên mật khẩu” ở màn đăng nhập.', 'mini-erp') . '</p></div>';
            }
        } elseif (isset($_GET['erp_user_create_error'])) {
            $createError = is_scalar($_GET['erp_user_create_error']) ? sanitize_key(wp_unslash((string) $_GET['erp_user_create_error'])) : '';
            $createErrorMessage = match ($createError) {
                'username_exists' => __('Tên đăng nhập đã được sử dụng.', 'mini-erp'),
                'email_exists' => __('Email đã được gắn với một tài khoản khác.', 'mini-erp'),
                default => __('Không thể tạo tài khoản. Hãy kiểm tra thông tin bắt buộc, vai trò và phạm vi kho/quỹ.', 'mini-erp'),
            };
            echo '<div class="notice notice-error"><p>' . esc_html($createErrorMessage) . '</p></div>';
        } elseif (isset($_GET['erp_access_saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Đã cập nhật quyền truy cập.', 'mini-erp') . '</p></div>';
        } elseif (isset($_GET['system_admin_conflict'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Chỉ được có một ERP System Admin. Bạn vẫn có thể cấp các role nghiệp vụ khác; để chuyển quyền, hạ role tài khoản Super Admin hiện tại trước.', 'mini-erp') . '</p></div>';
        } elseif (isset($_GET['erp_access_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Không thể cập nhật quyền. Hãy kiểm tra vai trò, phạm vi và trạng thái tài khoản.', 'mini-erp') . '</p></div>';
        }
        echo '<div class="erp-user-page-actions"><p class="erp-page-intro">' . esc_html__('Quản lý tài khoản, vai trò ERP và phạm vi dữ liệu theo công việc.', 'mini-erp') . '</p>';
        if ($canManageUsers && !$isCreateMode) {
            echo '<a class="button button-primary" href="' . esc_url(AppRouter::url('mini-erp-users', ['erp_user_page' => $page, 'erp_create_user' => 1])) . '">' . esc_html__('Tạo tài khoản', 'mini-erp') . '</a>';
        }
        echo '</div>';
        if (count($systemAdminIds) > 1) {
            echo '<div class="notice notice-warning" role="status"><p>' . esc_html__('Đang có nhiều tài khoản ERP System Admin. Giữ một tài khoản Super Admin; mở tài khoản còn lại và đổi sang vai trò nghiệp vụ hoặc “Không có quyền ERP”. Bạn vẫn có thể quản lý các tài khoản khác như bình thường.', 'mini-erp') . '</p></div>';
        } elseif (count($systemAdminIds) === 1) {
            echo '<div class="notice notice-info" role="status"><p>' . esc_html__('Đã có một ERP System Admin. Các tài khoản khác vẫn có thể được gán role nghiệp vụ, kho và quỹ; role ERP System Admin thứ hai sẽ bị chặn.', 'mini-erp') . '</p></div>';
        }
        echo '<div class="erp-user-access-layout"><section class="erp-user-list" aria-labelledby="erp-user-list-title"><header><h2 id="erp-user-list-title">' . esc_html__('Tài khoản ERP', 'mini-erp') . '</h2><span class="erp-user-count">' . esc_html(number_format(count($usersById), 0, ',', '.')) . '</span></header>';
        $roles = RolesInstaller::definitions();
        if ($usersById === []) {
            echo '<p class="erp-empty-state">' . esc_html__('Chưa có tài khoản WordPress để cấp quyền ERP.', 'mini-erp') . '</p>';
        }
        foreach ($usersById as $userId => $user) {
            $assigned = array_values(array_intersect(array_keys($roles), $user->roles));
            $role = count($assigned) === 1 ? $assigned[0] : '';
            $enabled = get_user_meta((int) $userId, '_mini_erp_enabled', true) === '1';
            $userUrl = add_query_arg(['erp_page' => 'mini-erp-users', 'erp_user_page' => $page, 'erp_user_id' => (int) $userId], home_url('/'));
            echo '<a class="erp-user-list-item" href="' . esc_url($userUrl) . '"' . ($selectedUserId === (int) $userId ? ' aria-current="page"' : '') . '><span class="erp-user-list-identity"><strong>' . esc_html($user->display_name) . '</strong><small>' . esc_html($user->user_email) . '</small></span><span class="erp-user-list-meta"><span class="erp-role-label">' . esc_html($role !== '' ? $roles[$role]['label'] : '—') . '</span><span class="erp-status-pill ' . ($enabled && $role !== '' ? 'is-success' : 'is-warning') . '">' . esc_html($enabled && $role !== '' ? __('Đang hoạt động', 'mini-erp') : __('Chưa cấp quyền', 'mini-erp')) . '</span></span></a>';
        }
        echo '</section>';

        if ($isCreateMode) {
            self::renderCreateForm($page, $roles, $warehouseOptions, $cashAccountOptions);
        } elseif ($selectedUser instanceof \WP_User) {
            $formId = 'mini-erp-user-access-' . (int) $selectedUser->ID;
            $selectedRoles = array_values(array_intersect(array_keys($roles), $selectedUser->roles));
            $selectedRole = count($selectedRoles) === 1 ? $selectedRoles[0] : '';
            $selectedEnabled = get_user_meta((int) $selectedUser->ID, '_mini_erp_enabled', true) === '1';
            echo '<section class="erp-user-editor" aria-labelledby="erp-user-editor-title"><header class="erp-user-editor-heading"><div><h2 id="erp-user-editor-title">' . esc_html($selectedUser->display_name) . '</h2><p>' . esc_html($selectedUser->user_email) . '</p></div><span class="erp-status-pill ' . ($selectedEnabled && $selectedRole !== '' ? 'is-success' : 'is-warning') . '">' . esc_html($selectedEnabled && $selectedRole !== '' ? __('Đang hoạt động', 'mini-erp') : __('Chưa cấp quyền', 'mini-erp')) . '</span></header>';
            echo '<a class="erp-user-back-link" href="' . esc_url(AppRouter::url('mini-erp-users', ['erp_user_page' => $page, 'erp_user_id' => 0])) . '">' . esc_html__('← Tất cả tài khoản', 'mini-erp') . '</a>';
            echo '<form id="' . esc_attr($formId) . '" class="erp-user-access-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-access-form="user-access" data-access-actor="' . esc_attr((string) $actor->id) . '" data-access-user="' . esc_attr((string) $selectedUser->ID) . '" data-access-page="' . esc_attr((string) $page) . '">';
            wp_nonce_field('mini_erp_user_access_' . (int) $selectedUser->ID, '_mini_erp_nonce');
            echo '<input type="hidden" name="action" value="mini_erp_user_access_save"><input type="hidden" name="user_id" value="' . (int) $selectedUser->ID . '"><input type="hidden" name="erp_user_page" value="' . esc_attr((string) $page) . '">';
            echo '<div class="erp-user-field"><label for="' . esc_attr($formId . '-erp_role') . '">' . esc_html__('Vai trò ERP', 'mini-erp') . '</label><select id="' . esc_attr($formId . '-erp_role') . '" name="erp_role"><option value="">' . esc_html__('Không có quyền ERP', 'mini-erp') . '</option>';
            $adminAssignedElsewhere = array_diff($systemAdminIds, [(int) $selectedUser->ID]) !== [];
            foreach ($roles as $slug => $definition) {
                $disableSystemAdmin = $slug === 'erp_system_admin' && $adminAssignedElsewhere && $selectedRole !== 'erp_system_admin';
                $roleLabel = $definition['label'] . ($disableSystemAdmin ? ' — đã có tài khoản Super Admin' : '');
                echo '<option value="' . esc_attr($slug) . '" ' . selected($selectedRole, $slug, false) . disabled($disableSystemAdmin, true, false) . '>' . esc_html($roleLabel) . '</option>';
            }
            echo '</select><p class="description">' . esc_html__('Vai trò dùng bộ quyền nghiệp vụ cố định; kho và quỹ được gán riêng theo nhu cầu công việc.', 'mini-erp') . '</p></div><div class="erp-user-scope-group"><h3>' . esc_html__('Phạm vi truy cập', 'mini-erp') . '</h3><div class="erp-user-field"><span class="erp-field-label">' . esc_html__('Kho được gán', 'mini-erp') . '</span>';
            self::renderScopeSelect($formId, 'warehouse_ids', 'Kho được gán', $warehouseOptions, self::storedIds((int) $selectedUser->ID, '_mini_erp_warehouse_ids'));
            echo '<p class="description">' . esc_html__('Chỉ gán kho mà người dùng cần vận hành. Manager có phạm vi tồn kho toàn công ty theo vai trò.', 'mini-erp') . '</p></div><div class="erp-user-field"><span class="erp-field-label">' . esc_html__('Quỹ được gán', 'mini-erp') . '</span>';
            self::renderScopeSelect($formId, 'cash_account_ids', 'Quỹ được gán', $cashAccountOptions, self::storedIds((int) $selectedUser->ID, '_mini_erp_cash_account_ids'));
            echo '<p class="description">' . esc_html__('Người dùng chỉ xem số dư và lịch sử của quỹ/tài khoản ngân hàng được gán.', 'mini-erp') . '</p></div></div>';
            echo '<label class="erp-user-enabled"><input type="checkbox" name="enabled" value="1" ' . checked($selectedEnabled, true, false) . '> <span><strong>' . esc_html__('Được phép đăng nhập ERP', 'mini-erp') . '</strong><small>' . esc_html__('Chỉ bật khi tài khoản đã được gán vai trò phù hợp.', 'mini-erp') . '</small></span></label>';
            echo '<div class="erp-user-editor-actions"><button class="button button-primary" type="submit">' . esc_html__('Lưu thay đổi', 'mini-erp') . '</button></div></form></section>';
        } else {
            echo '<section class="erp-user-editor erp-user-editor-empty"><h2>' . esc_html__('Chọn một tài khoản', 'mini-erp') . '</h2><p>' . esc_html__('Chọn người dùng trong danh sách để xem và chỉnh sửa vai trò, kho, quỹ và trạng thái truy cập.', 'mini-erp') . '</p></section>';
        }
        echo '</div>';
        self::renderUserPagination($page, $pages, false);
        echo '<p class="description erp-user-access-help">' . esc_html__('Giữ Ctrl (Windows/Linux) hoặc Command (macOS) để chọn nhiều kho hoặc quỹ. Quỹ nháp có thể được gán để Manager khác kích hoạt; kho và quỹ ngừng hoạt động không thể cấp mới.', 'mini-erp') . '</p></div>';
    }

    public static function save(): void
    {
        CapabilityPolicy::requireCapability('erp_users_manage');
        if (!current_user_can('erp_roles_manage')) {
            wp_die(esc_html__('Bạn không có quyền quản lý vai trò ERP.', 'mini-erp'), '', ['response' => 403]);
        }
        $userIdInput = $_POST['user_id'] ?? null;
        $userId = is_scalar($userIdInput) ? filter_var(wp_unslash((string) $userIdInput), FILTER_VALIDATE_INT) : false;
        $pageInput = $_POST['erp_user_page'] ?? null;
        $pageValue = $pageInput === null ? 1 : (is_scalar($pageInput) ? filter_var(wp_unslash((string) $pageInput), FILTER_VALIDATE_INT) : false);
        $page = is_int($pageValue) && $pageValue > 0 ? min($pageValue, 10000) : 1;
        if ($userId === false || $userId < 1) {
            self::redirect(false, null, $page);
        }
        check_admin_referer('mini_erp_user_access_' . $userId, '_mini_erp_nonce');

        $actor = CapabilityPolicy::currentActor();
        $target = get_userdata($userId);
        $rawRole = wp_unslash($_POST['erp_role'] ?? '');
        $role = is_string($rawRole) ? sanitize_key($rawRole) : '';
        $allowedRoles = array_keys(RolesInstaller::definitions());
        $enabled = isset($_POST['enabled']) && wp_unslash($_POST['enabled']) === '1';
        $warehouseIds = self::parseIds(wp_unslash($_POST['warehouse_ids'] ?? ''));
        $cashAccountIds = self::parseIds(wp_unslash($_POST['cash_account_ids'] ?? ''));

        if ($actor === null || $actor->id === $userId || !$target
            || in_array('administrator', (array) $target->roles, true)
            || ($role !== '' && !in_array($role, $allowedRoles, true))
            || ($enabled && $role === '')
            || $warehouseIds === null || $cashAccountIds === null
            || !self::idsExist('erp_warehouses', $warehouseIds)
            || !self::idsExist('erp_cash_accounts', $cashAccountIds)
        ) {
            self::redirect(false, $userId, $page);
        }

        global $wpdb;
        try {
            $beforeRoles = array_values(array_intersect($allowedRoles, (array) $target->roles));
            $before = [
                'role' => count($beforeRoles) === 1 ? $beforeRoles[0] : 'ambiguous',
                'is_active' => get_user_meta($userId, '_mini_erp_enabled', true) === '1' ? 1 : 0,
                'warehouse_scope_count' => count(self::storedIds($userId, '_mini_erp_warehouse_ids')),
                'cash_scope_count' => count(self::storedIds($userId, '_mini_erp_cash_account_ids')),
            ];
            $nextRole = $role;
            self::saveWithRoleLock($wpdb, function (TransactionManager $transaction) use (
                $wpdb, $target, $allowedRoles, $nextRole, $enabled, $warehouseIds, $cashAccountIds, $actor, $before, $userId
            ): void {
                if ($nextRole === 'erp_system_admin') {
                    SystemAdminAssignment::assertSoleAssignee($transaction, $wpdb, $userId);
                }
                foreach ($allowedRoles as $existingRole) {
                    if (in_array($existingRole, (array) $target->roles, true)) {
                        $target->remove_role($existingRole);
                    }
                }
                if ($nextRole !== '') {
                    $target->add_role($nextRole);
                }
                update_user_meta($userId, '_mini_erp_enabled', $enabled ? '1' : '0');
                update_user_meta($userId, '_mini_erp_warehouse_ids', $warehouseIds);
                update_user_meta($userId, '_mini_erp_cash_account_ids', $cashAccountIds);

                $assignedRoles = array_values(array_intersect($allowedRoles, (array) $target->roles));
                $validRoleState = $nextRole === '' ? $assignedRoles === [] : $assignedRoles === [$nextRole];
                if (!$validRoleState
                    || get_user_meta($userId, '_mini_erp_enabled', true) !== ($enabled ? '1' : '0')
                    || self::storedIds($userId, '_mini_erp_warehouse_ids') !== $warehouseIds
                    || self::storedIds($userId, '_mini_erp_cash_account_ids') !== $cashAccountIds
                ) {
                    throw new \RuntimeException('Could not persist ERP user access changes.');
                }

                (new AuditLogger())->record(
                    $transaction,
                    self::requestId(),
                    $actor->id,
                    'role.change',
                    'user',
                    $userId,
                    'success',
                    $before,
                    [
                        'role' => $nextRole === '' ? 'none' : $nextRole,
                        'is_active' => $enabled ? 1 : 0,
                        'warehouse_scope_count' => count($warehouseIds),
                        'cash_scope_count' => count($cashAccountIds),
                    ]
                );
            });
        } catch (SystemAdminAssignmentConflict) {
            if (function_exists('clean_user_cache')) {
                clean_user_cache($userId);
            }
            self::redirect(false, $userId, $page, true);
        } catch (Throwable $failure) {
            if (function_exists('clean_user_cache')) {
                clean_user_cache($userId);
            }
            self::redirect(false, $userId, $page);
        }

        if (function_exists('clean_user_cache')) {
            clean_user_cache($userId);
        }
        self::redirect(true, $userId, $page);
    }

    /** Create a WordPress identity with a non-privileged base role and ERP access in one transaction. */
    public static function create(): void
    {
        CapabilityPolicy::requireCapability('erp_users_manage');
        if (!current_user_can('erp_roles_manage')) {
            wp_die(esc_html__('Bạn không có quyền quản lý vai trò ERP.', 'mini-erp'), '', ['response' => 403]);
        }
        check_admin_referer('mini_erp_user_create', '_mini_erp_nonce');

        $actor = CapabilityPolicy::currentActor();
        $displayNameInput = $_POST['display_name'] ?? '';
        $loginInput = $_POST['user_login'] ?? '';
        $emailInput = $_POST['user_email'] ?? '';
        $roleInput = $_POST['erp_role'] ?? '';
        $displayName = is_scalar($displayNameInput) ? sanitize_text_field(wp_unslash((string) $displayNameInput)) : '';
        $userLogin = is_scalar($loginInput) ? sanitize_user(trim(wp_unslash((string) $loginInput)), true) : '';
        $userEmail = is_scalar($emailInput) ? sanitize_email(trim(wp_unslash((string) $emailInput))) : '';
        $role = is_scalar($roleInput) ? sanitize_key(wp_unslash((string) $roleInput)) : '';
        $allowedRoles = array_values(array_diff(array_keys(RolesInstaller::definitions()), ['erp_system_admin']));
        $warehouseIds = self::parseIds(wp_unslash($_POST['warehouse_ids'] ?? ''));
        $cashAccountIds = self::parseIds(wp_unslash($_POST['cash_account_ids'] ?? ''));
        $enabled = isset($_POST['enabled']) && wp_unslash($_POST['enabled']) === '1';

        if ($actor === null || $displayName === '' || $userLogin === '' || strlen($userLogin) > 60
            || !is_email($userEmail) || !in_array($role, $allowedRoles, true)
            || $warehouseIds === null || $cashAccountIds === null
            || !self::idsExist('erp_warehouses', $warehouseIds)
            || !self::idsExist('erp_cash_accounts', $cashAccountIds)
            || !function_exists('wp_generate_password') || !function_exists('get_role')
            || get_role('subscriber') === null || get_role($role) === null
        ) {
            self::createRedirect(null, 'invalid');
        }
        if (username_exists($userLogin) !== false) {
            self::createRedirect(null, 'username_exists');
        }
        if (email_exists($userEmail) !== false) {
            self::createRedirect(null, 'email_exists');
        }

        global $wpdb;
        $newUserId = 0;
        $insertedUserId = 0;
        try {
            $newUserId = (int) self::saveWithRoleLock($wpdb, function (TransactionManager $transaction) use (
                $wpdb, $displayName, $userLogin, $userEmail, $role, $allowedRoles, $enabled, $warehouseIds, $cashAccountIds, $actor, &$insertedUserId
            ): int {
                $createdId = wp_insert_user([
                    'user_login' => $userLogin,
                    'user_email' => $userEmail,
                    'display_name' => $displayName,
                    'user_pass' => wp_generate_password(40, true, true),
                    'role' => 'subscriber',
                ]);
                if (is_wp_error($createdId) || !is_int($createdId) || $createdId < 1) {
                    throw new \RuntimeException('WordPress could not create the account.');
                }
                $insertedUserId = $createdId;

                if (function_exists('clean_user_cache')) {
                    clean_user_cache($createdId);
                }
                $createdUser = get_userdata($createdId);
                if (!$createdUser instanceof \WP_User) {
                    throw new \RuntimeException('The created WordPress account could not be loaded.');
                }
                foreach ($allowedRoles as $existingRole) {
                    if (in_array($existingRole, (array) $createdUser->roles, true)) {
                        $createdUser->remove_role($existingRole);
                    }
                }
                $createdUser->add_role($role);
                update_user_meta($createdId, '_mini_erp_enabled', $enabled ? '1' : '0');
                update_user_meta($createdId, '_mini_erp_warehouse_ids', $warehouseIds);
                update_user_meta($createdId, '_mini_erp_cash_account_ids', $cashAccountIds);

                $assignedRoles = array_values(array_intersect(array_keys(RolesInstaller::definitions()), (array) $createdUser->roles));
                if ($assignedRoles !== [$role]
                    || get_user_meta($createdId, '_mini_erp_enabled', true) !== ($enabled ? '1' : '0')
                    || self::storedIds($createdId, '_mini_erp_warehouse_ids') !== $warehouseIds
                    || self::storedIds($createdId, '_mini_erp_cash_account_ids') !== $cashAccountIds
                ) {
                    throw new \RuntimeException('ERP access did not persist for the new account.');
                }

                (new AuditLogger())->record(
                    $transaction,
                    self::requestId(),
                    $actor->id,
                    'user.create',
                    'user',
                    $createdId,
                    'success',
                    [],
                    [
                        'role' => $role,
                        'is_active' => $enabled ? 1 : 0,
                        'warehouse_scope_count' => count($warehouseIds),
                        'cash_scope_count' => count($cashAccountIds),
                    ]
                );

                return $createdId;
            });
        } catch (Throwable) {
            if ($insertedUserId > 0 && function_exists('clean_user_cache')) {
                clean_user_cache($insertedUserId);
            }
            self::createRedirect(null, 'save_failed');
        }

        if (function_exists('clean_user_cache')) {
            clean_user_cache($newUserId);
        }
        $inviteFailed = !function_exists('wp_new_user_notification');
        if (!$inviteFailed) {
            $trackMailFailure = static function () use (&$inviteFailed): void {
                $inviteFailed = true;
            };
            add_action('wp_mail_failed', $trackMailFailure);
            try {
                wp_new_user_notification($newUserId, null, 'user');
            } catch (Throwable) {
                $inviteFailed = true;
            } finally {
                remove_action('wp_mail_failed', $trackMailFailure);
            }
        }
        self::createRedirect($newUserId, $inviteFailed ? 'invite_failed' : '');
    }

    /** @param array<string, array{label: string, capabilities: list<string>}> $roles
     *  @param list<array{id:int,label:string,assignable:bool}> $warehouseOptions
     *  @param list<array{id:int,label:string,assignable:bool}> $cashAccountOptions
     */
    private static function renderCreateForm(int $page, array $roles, array $warehouseOptions, array $cashAccountOptions): void
    {
        $formId = 'mini-erp-user-create';
        echo '<section class="erp-user-editor erp-user-create-editor" aria-labelledby="erp-user-create-title"><header class="erp-user-editor-heading"><div><h2 id="erp-user-create-title">' . esc_html__('Tạo tài khoản ERP', 'mini-erp') . '</h2><p>' . esc_html__('Tạo tài khoản đăng nhập và gán quyền ngay trong hệ thống ERP.', 'mini-erp') . '</p></div></header>';
        echo '<a class="erp-user-back-link" href="' . esc_url(AppRouter::url('mini-erp-users', ['erp_user_page' => $page, 'erp_user_id' => 0])) . '">' . esc_html__('← Danh sách tài khoản', 'mini-erp') . '</a>';
        echo '<form id="' . esc_attr($formId) . '" class="erp-user-access-form erp-user-create-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mini_erp_user_create', '_mini_erp_nonce');
        echo '<input type="hidden" name="action" value="mini_erp_user_create">';
        echo '<div class="erp-user-field"><label for="' . esc_attr($formId . '-display_name') . '">' . esc_html__('Tên hiển thị', 'mini-erp') . '</label><input id="' . esc_attr($formId . '-display_name') . '" name="display_name" type="text" autocomplete="name" maxlength="120" required></div>';
        echo '<div class="erp-user-field"><label for="' . esc_attr($formId . '-user_login') . '">' . esc_html__('Tên đăng nhập', 'mini-erp') . '</label><input id="' . esc_attr($formId . '-user_login') . '" name="user_login" type="text" autocomplete="username" maxlength="60" required><p class="description">' . esc_html__('Người dùng sử dụng tên này hoặc email để đăng nhập ERP.', 'mini-erp') . '</p></div>';
        echo '<div class="erp-user-field"><label for="' . esc_attr($formId . '-user_email') . '">' . esc_html__('Email', 'mini-erp') . '</label><input id="' . esc_attr($formId . '-user_email') . '" name="user_email" type="email" autocomplete="email" maxlength="100" required></div>';
        echo '<div class="erp-user-field"><label for="' . esc_attr($formId . '-erp_role') . '">' . esc_html__('Vai trò ERP', 'mini-erp') . '</label><select id="' . esc_attr($formId . '-erp_role') . '" name="erp_role" required><option value="">' . esc_html__('Chọn vai trò ERP', 'mini-erp') . '</option>';
        foreach ($roles as $slug => $definition) {
            if ($slug === 'erp_system_admin') continue;
            echo '<option value="' . esc_attr($slug) . '">' . esc_html($definition['label']) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Tài khoản WordPress mới dùng role Subscriber; vai trò nghiệp vụ chỉ áp dụng trong ERP.', 'mini-erp') . '</p></div>';
        echo '<div class="erp-user-scope-group"><h3>' . esc_html__('Phạm vi truy cập', 'mini-erp') . '</h3><div class="erp-user-field"><span class="erp-field-label">' . esc_html__('Kho được gán', 'mini-erp') . '</span>';
        self::renderScopeSelect($formId, 'warehouse_ids', 'Kho được gán', $warehouseOptions, []);
        echo '<p class="description">' . esc_html__('Chỉ gán kho mà người dùng cần vận hành.', 'mini-erp') . '</p></div><div class="erp-user-field"><span class="erp-field-label">' . esc_html__('Quỹ được gán', 'mini-erp') . '</span>';
        self::renderScopeSelect($formId, 'cash_account_ids', 'Quỹ được gán', $cashAccountOptions, []);
        echo '<p class="description">' . esc_html__('Người dùng chỉ xem số dư và lịch sử của quỹ/tài khoản ngân hàng được gán.', 'mini-erp') . '</p></div></div>';
        echo '<label class="erp-user-enabled"><input type="checkbox" name="enabled" value="1" checked> <span><strong>' . esc_html__('Cho phép đăng nhập ERP', 'mini-erp') . '</strong><small>' . esc_html__('Có thể tắt quyền truy cập sau khi tạo tài khoản.', 'mini-erp') . '</small></span></label>';
        echo '<p class="description erp-user-invite-note">' . esc_html__('Mật khẩu được tạo ngẫu nhiên; hệ thống gửi email để người dùng tự đặt mật khẩu.', 'mini-erp') . '</p>';
        echo '<div class="erp-user-editor-actions"><button class="button button-primary" type="submit">' . esc_html__('Tạo tài khoản và cấp quyền', 'mini-erp') . '</button></div></form></section>';
    }

    /** @return list<int> */
    private static function storedIds(int $userId, string $key): array
    {
        return ScopeAssignmentIds::normalizeStored(get_user_meta($userId, $key, true)) ?? [];
    }

    private static function saveWithRoleLock(\wpdb $db, callable $work): mixed
    {
        return SystemAdminAssignment::withLock(
            $db,
            static fn (): mixed => (new TransactionManager($db))->run($work)
        );
    }

    /** @return array{0:list<object>,1:int,2:int} */
    private static function usersPage(): array
    {
        $requestedPage = isset($_GET['erp_user_page']) && is_scalar($_GET['erp_user_page'])
            ? max(1, min(10000, absint(wp_unslash($_GET['erp_user_page']))))
            : 1;
        $queryArgs = [
            'number' => self::USERS_PER_PAGE,
            'orderby' => ['display_name' => 'ASC', 'ID' => 'ASC'],
            'role__not_in' => ['administrator'],
            'exclude' => [(int) get_current_user_id()],
        ];
        $query = new \WP_User_Query($queryArgs + ['offset' => ($requestedPage - 1) * self::USERS_PER_PAGE]);
        $total = max(0, (int) $query->get_total());
        $pageCount = max(1, (int) ceil($total / self::USERS_PER_PAGE));
        $page = min($requestedPage, $pageCount);
        if ($page !== $requestedPage) {
            $query = new \WP_User_Query($queryArgs + ['offset' => ($page - 1) * self::USERS_PER_PAGE]);
        }
        return [$query->get_results(), $page, $pageCount];
    }

    private static function renderUserPagination(int $page, int $pageCount, bool $bootstrap): void
    {
        if ($pageCount <= 1) return;
        $baseUrl = $bootstrap ? admin_url('users.php') : home_url('/');
        $baseArgs = $bootstrap ? ['page' => 'mini-erp-bootstrap-users'] : ['erp_page' => 'mini-erp-users'];
        echo '<nav class="erp-pagination" aria-label="' . esc_attr__('Phân trang người dùng', 'mini-erp') . '">';
        if ($page > 1) {
            echo '<a class="button" rel="prev" href="' . esc_url(add_query_arg([...$baseArgs, 'erp_user_page' => $page - 1], $baseUrl)) . '">' . esc_html__('Trang trước', 'mini-erp') . '</a> ';
        }
        echo '<span aria-current="page">' . esc_html(sprintf(__('Trang %1$d / %2$d', 'mini-erp'), $page, $pageCount)) . '</span>';
        if ($page < $pageCount) {
            echo ' <a class="button" rel="next" href="' . esc_url(add_query_arg([...$baseArgs, 'erp_user_page' => $page + 1], $baseUrl)) . '">' . esc_html__('Trang sau', 'mini-erp') . '</a>';
        }
        echo '</nav>';
    }

    /** @return list<int>|null */
    private static function parseIds(mixed $value): ?array
    {
        return ScopeAssignmentIds::parseSelection($value);
    }

    /** @return list<array{id:int,label:string,assignable:bool}> */
    private static function scopeOptions(string $suffix): array
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb || !in_array($suffix, ['erp_warehouses', 'erp_cash_accounts'], true)) {
            return [];
        }
        $table = '`' . esc_sql($wpdb->prefix . $suffix) . '`';
        $sql = $suffix === 'erp_warehouses'
            ? "SELECT id,code,name,IF(is_active=1,'ACTIVE','INACTIVE') state FROM {$table} ORDER BY code,id"
            : "SELECT id,account_code code,name,type,status state FROM {$table} ORDER BY account_code,id";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            return [];
        }
        $options = [];
        foreach ($rows as $row) {
            $state = (string) ($row['state'] ?? '');
            if ($suffix === 'erp_warehouses') {
                $status = $state === 'ACTIVE' ? 'Đang hoạt động' : 'Ngừng hoạt động';
                $type = '';
                $assignable = $state === 'ACTIVE';
            } else {
                $status = match ($state) {
                    'DRAFT' => 'Nháp',
                    'ACTIVE' => 'Đang hoạt động',
                    default => 'Ngừng hoạt động',
                };
                $type = ($row['type'] ?? '') === 'BANK' ? 'Ngân hàng' : 'Tiền mặt';
                $assignable = in_array($state, ['DRAFT', 'ACTIVE'], true);
            }
            $label = (string) $row['code'] . ' · ' . (string) $row['name'];
            if ($type !== '') {
                $label .= ' · ' . $type;
            }
            $options[] = ['id' => (int) $row['id'], 'label' => $label . ' · ' . $status, 'assignable' => $assignable];
        }
        return $options;
    }

    /** @param list<array{id:int,label:string,assignable:bool}> $options @param list<int> $selectedIds */
    private static function renderScopeSelect(string $formId, string $field, string $label, array $options, array $selectedIds): void
    {
        $selectId = $formId . '-' . $field;
        echo '<label class="screen-reader-text" for="' . esc_attr($selectId) . '">' . esc_html($label) . '</label>';
        echo '<select class="erp-scope-select" id="' . esc_attr($selectId) . '" form="' . esc_attr($formId) . '" name="' . esc_attr($field) . '[]" multiple size="5" aria-label="' . esc_attr($label) . '">';
        foreach ($options as $option) {
            $isSelected = in_array($option['id'], $selectedIds, true);
            $disabled = !$option['assignable'] && !$isSelected ? ' disabled' : '';
            echo '<option value="' . esc_attr((string) $option['id']) . '"' . selected($isSelected, true, false) . $disabled . '>' . esc_html($option['label']) . '</option>';
        }
        if ($options === []) {
            echo '<option disabled>' . esc_html__('Chưa có mục để gán.', 'mini-erp') . '</option>';
        }
        echo '</select>';
    }

    /** @param list<int>|null $ids */
    private static function idsExist(string $suffix, ?array $ids): bool
    {
        if ($ids === null || $ids === []) {
            return $ids !== null;
        }
        global $wpdb;
        $table = $wpdb->prefix . $suffix;
        // Draft accounts must be assignable so a different Manager can review and activate them.
        $activePredicate = $suffix === 'erp_cash_accounts' ? "status IN ('DRAFT','ACTIVE')" : 'is_active = 1';
        $found = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM `' . esc_sql($table) . '` WHERE ' . $activePredicate . ' AND id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
            ...$ids
        ));
        return is_array($found) && count(array_unique(array_map('intval', $found))) === count($ids);
    }

    private static function requestId(): string
    {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffffffffffff));
    }

    private static function redirect(bool $saved, ?int $userId = null, int $page = 1, bool $systemAdminConflict = false): never
    {
        $args = [
            'erp_page' => 'mini-erp-users',
            $saved ? 'erp_access_saved' : ($systemAdminConflict ? 'system_admin_conflict' : 'erp_access_error') => '1',
            'erp_user_page' => max(1, min($page, 10000)),
        ];
        if ($userId !== null && $userId > 0) {
            $args['recover_user_id'] = $userId;
            $args['erp_user_id'] = $userId;
        }
        $url = add_query_arg($args, home_url('/'));
        wp_safe_redirect($url, 303);
        exit;
    }

    private static function createRedirect(?int $userId, string $error = ''): never
    {
        $args = ['erp_page' => 'mini-erp-users'];
        if ($userId !== null && $userId > 0) {
            $args['erp_user_created'] = '1';
            $args['erp_user_id'] = $userId;
            if ($error === 'invite_failed') {
                $args['erp_user_invite_error'] = '1';
            }
        } else {
            $args['erp_create_user'] = '1';
            $args['erp_user_create_error'] = $error !== '' ? $error : 'save_failed';
        }
        wp_safe_redirect(add_query_arg($args, home_url('/')), 303);
        exit;
    }

    private static function bootstrapRedirect(bool $saved, bool $systemAdminConflict = false): never
    {
        $notice = $saved ? 'erp_access_saved' : ($systemAdminConflict ? 'system_admin_conflict' : 'erp_access_error');
        $url = add_query_arg($notice, '1', admin_url('users.php?page=mini-erp-bootstrap-users'));
        wp_safe_redirect($url, 303);
        exit;
    }
}
