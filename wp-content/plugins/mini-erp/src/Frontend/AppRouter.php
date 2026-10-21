<?php
declare(strict_types=1);

namespace MiniERP\Frontend;

use MiniERP\Bootstrap;
use MiniERP\Admin\Controllers\CashController;
use MiniERP\Admin\Controllers\CatalogController;
use MiniERP\Admin\Controllers\CompanyProfileController;
use MiniERP\Admin\Controllers\InventoryController;
use MiniERP\Admin\Controllers\InvoiceController;
use MiniERP\Admin\Controllers\PartyController;
use MiniERP\Admin\Controllers\PurchaseController;
use MiniERP\Admin\Controllers\ReconciliationController;
use MiniERP\Admin\Controllers\ReportController;
use MiniERP\Admin\Controllers\SalesController;
use MiniERP\Admin\Controllers\UIStudioController;
use MiniERP\Security\AuditLogController;
use MiniERP\Security\CapabilityPolicy;

/** Frontend entry point for the ERP app. WordPress admin remains a separate surface. */
final class AppRouter
{
    /** @var array<string, array{label: string, capabilities: list<string>, callback: callable, preflight?: callable}> */
    private const PAGES = [
        'mini-erp' => [
            'label' => 'Tổng quan',
            'capabilities' => ['erp_dashboard_view'],
            'callback' => [self::class, 'renderDashboard'],
        ],
        'mini-erp-products' => [
            'label' => 'Danh mục và sản phẩm',
            'capabilities' => ['erp_products_view'],
            'callback' => [CatalogController::class, 'render'],
        ],
        'mini-erp-customers' => [
            'label' => 'Khách hàng',
            'capabilities' => ['erp_customers_manage'],
            'callback' => [PartyController::class, 'customers'],
        ],
        'mini-erp-suppliers' => [
            'label' => 'Nhà cung cấp',
            'capabilities' => ['erp_suppliers_view'],
            'callback' => [PartyController::class, 'suppliers'],
        ],
        'mini-erp-warehouses' => [
            'label' => 'Kho hàng',
            'capabilities' => ['erp_warehouses_view'],
            'callback' => [PartyController::class, 'warehouses'],
        ],
        'mini-erp-sales' => [
            'label' => 'Đơn bán hàng',
            'capabilities' => ['erp_sales_view_own', 'erp_sales_view_all', 'erp_stock_ship'],
            'callback' => [SalesController::class, 'render'],
            'preflight' => [SalesController::class, 'preflightDetail'],
        ],
        'mini-erp-purchases' => [
            'label' => 'Đơn mua và nhập hàng',
            'capabilities' => ['erp_purchases_view'],
            'callback' => [PurchaseController::class, 'render'],
            'preflight' => [PurchaseController::class, 'preflightDetail'],
        ],
        'mini-erp-inventory' => [
            'label' => 'Kho hàng và kiểm kê',
            'capabilities' => ['erp_stock_view'],
            'callback' => [InventoryController::class, 'render'],
            'preflight' => [InventoryController::class, 'preflightDetail'],
        ],
        'mini-erp-invoices' => [
            'label' => 'Hóa đơn nội bộ',
            'capabilities' => ['erp_invoices_view'],
            'callback' => [InvoiceController::class, 'render'],
            'preflight' => [InvoiceController::class, 'preflightDetail'],
        ],
        'mini-erp-company' => [
            'label' => 'Hồ sơ công ty',
            'capabilities' => ['erp_settings_manage'],
            'callback' => [CompanyProfileController::class, 'render'],
        ],
        'mini-erp-cash-accounts' => [
            'label' => 'Quỹ tiền mặt và ngân hàng',
            'capabilities' => ['erp_cash_accounts_view'],
            'callback' => [CashController::class, 'renderAccounts'],
        ],
        'mini-erp-payments' => [
            'label' => 'Thu chi và thanh toán',
            'capabilities' => ['erp_payments_view'],
            'callback' => [CashController::class, 'renderPayments'],
        ],
        'mini-erp-expenses' => [
            'label' => 'Phiếu chi',
            'capabilities' => ['erp_expenses_manage', 'erp_reports_finance'],
            'callback' => [CashController::class, 'renderExpenses'],
        ],
        'mini-erp-reconciliation' => [
            'label' => 'Đối soát tài khoản',
            'capabilities' => ['erp_reconciliations_view'],
            'callback' => [ReconciliationController::class, 'render'],
            'preflight' => [ReconciliationController::class, 'preflightDetail'],
        ],
        'mini-erp-users' => [
            'label' => 'Người dùng ERP',
            'capabilities' => ['erp_users_view'],
            'callback' => [\MiniERP\Security\UserAccessController::class, 'render'],
        ],
        'mini-erp-audit' => [
            'label' => 'Nhật ký kiểm toán',
            'capabilities' => ['erp_audit_view'],
            'callback' => [AuditLogController::class, 'render'],
        ],
        'mini-erp-reports' => [
            'label' => 'Báo cáo',
            'capabilities' => ['erp_reports_receivables'],
            'callback' => [ReportController::class, 'render'],
        ],
    ];

    public static function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('template_redirect', [self::class, 'renderRequest'], 0);
        add_action('admin_init', [self::class, 'handleAdminRequest'], 0);
    }

    /** @param array<string, scalar|array|null> $args */
    public static function url(string $page, array $args = []): string
    {
        if (!isset(self::PAGES[$page])) {
            $page = 'mini-erp';
        }

        $query = $page === 'mini-erp' ? [] : ['erp_page' => $page];
        return add_query_arg(array_merge($query, $args), home_url('/'));
    }

    /** @return array<string,string> */
    public static function defaultPageLabels(): array
    {
        $labels = [];
        foreach (self::PAGES as $slug => $definition) {
            $labels[$slug] = $definition['label'];
        }
        return $labels;
    }

    public static function renderRequest(): void
    {
        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || is_feed() || is_robots()) {
            return;
        }

        $requestedPage = self::requestedPage();
        $isAppEntry = $requestedPage !== null || is_front_page();
        if (!$isAppEntry) {
            return;
        }

        add_filter('show_admin_bar', '__return_false');

        $page = $requestedPage ?? 'mini-erp';
        if (!isset(self::PAGES[$page])) {
            self::renderMessage(404, 'Không tìm thấy màn hình', 'Đường dẫn ERP này không tồn tại.');
        }

        if (!is_user_logged_in()) {
            $returnTo = self::url($page, self::currentQueryArgs());
            wp_safe_redirect(wp_login_url($returnTo));
            exit;
        }

        if(!Bootstrap::schemaReady())self::renderMessage(503,'Cập nhật cơ sở dữ liệu đang chờ','ERP System Admin hoặc WordPress administrator cần hoàn tất cập nhật trước khi màn hình hoạt động.');

        $actor = CapabilityPolicy::currentActor();
        if ($actor === null) {
            CapabilityPolicy::logDeniedCapabilityAttempt(self::PAGES[$page]['capabilities'][0]);
            self::renderMessage(403, 'Tài khoản chưa được cấp quyền ERP', 'Hãy đăng nhập bằng tài khoản đã được kích hoạt cho một vai trò ERP.');
        }

        if (!self::canAccessPage($page, $actor)) {
            CapabilityPolicy::logDeniedCapabilityAttempt(self::PAGES[$page]['capabilities'][0]);
            self::renderMessage(403, 'Bạn chưa được cấp quyền cho màn hình này', 'Vai trò ERP hiện tại không có quyền truy cập chức năng này.');
        }

        $preflight = self::PAGES[$page]['preflight'] ?? null;
        if (is_callable($preflight)) {
            try {
                $status = $preflight($actor);
            } catch (\Throwable) {
                $status = 503;
            }
            if ($status !== null) {
                if ($status === 404) {
                    self::renderMessage(404, 'Không tìm thấy chứng từ', 'Chứng từ không tồn tại hoặc bạn không có quyền truy cập.');
                }
                self::renderMessage(503, 'Không thể kiểm tra quyền truy cập', 'ERP tạm thời không thể xác minh chứng từ. Vui lòng thử lại sau.');
            }
        }

        self::renderApplication($page, $actor);
    }

    public static function handleAdminRequest(): void
    {
        if (!is_admin() || !is_user_logged_in()) {
            return;
        }

        $script = isset($_SERVER['PHP_SELF']) && is_string($_SERVER['PHP_SELF'])
            ? basename(wp_unslash($_SERVER['PHP_SELF']))
            : '';
        if (in_array($script, ['admin-post.php', 'admin-ajax.php', 'async-upload.php'], true)) {
            return;
        }

        $page = self::queryValue('page');
        if ($page === 'mini-erp-ui-studio' && CapabilityPolicy::allows('erp_ui_studio_manage')) {
            return;
        }
        if ($page !== null && isset(self::PAGES[$page])) {
            wp_safe_redirect(self::url($page, self::currentQueryArgs('page')));
            exit;
        }

        if (CapabilityPolicy::currentActor() !== null) {
            wp_safe_redirect(self::url('mini-erp'));
            exit;
        }
    }

    public static function renderDashboard(): void
    {
        ReportController::dashboard();
    }

    private static function requestedPage(): ?string
    {
        $page = self::queryValue('erp_page');
        if ($page === null) {
            $legacyPage = self::queryValue('page');
            if ($legacyPage !== null && isset(self::PAGES[$legacyPage])) {
                $page = $legacyPage;
            }
        }

        return $page;
    }

    private static function queryValue(string $key): ?string
    {
        if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
            return null;
        }

        return sanitize_key((string) wp_unslash($_GET[$key]));
    }

    /** @return array<string, mixed> */
    private static function currentQueryArgs(?string $except = null): array
    {
        $args = [];
        foreach ($_GET as $key => $value) {
            if (!is_string($key) || $key === $except || $key === 'erp_page' || !is_scalar($value)) {
                continue;
            }
            $args[$key] = wp_unslash($value);
        }
        return $args;
    }

    private static function canAccessPage(string $page, \MiniERP\Security\Actor $actor): bool
    {
        foreach (self::PAGES[$page]['capabilities'] as $capability) {
            if ($actor->can($capability) && current_user_can($capability)) {
                return true;
            }
        }
        return false;
    }

    private static function renderApplication(string $page, \MiniERP\Security\Actor $actor): never
    {
        nocache_headers();
        self::enqueueStyles($page);
        $definition = self::PAGES[$page];
        $applicationName = UIStudioController::applicationName();
        $pageLabel = UIStudioController::pageLabel($page, $definition['label']);
        $user = wp_get_current_user();
        $title = $pageLabel . ' — ' . $applicationName;
        $navigationGroups = [
            'Bàn làm việc' => ['mini-erp'],
            'Bán hàng' => ['mini-erp-products', 'mini-erp-customers', 'mini-erp-sales', 'mini-erp-invoices'],
            'Mua hàng và kho' => ['mini-erp-purchases', 'mini-erp-suppliers', 'mini-erp-warehouses', 'mini-erp-inventory'],
            'Tài chính' => ['mini-erp-cash-accounts', 'mini-erp-payments', 'mini-erp-expenses', 'mini-erp-reconciliation', 'mini-erp-reports'],
            'Quản trị' => ['mini-erp-company', 'mini-erp-users', 'mini-erp-audit'],
        ];
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="mini-erp-frontend">
            <?php wp_body_open(); ?>
            <a class="erp-skip-link" href="#erp-main">Bỏ qua điều hướng</a>
            <div class="erp-shell">
                <header class="erp-topbar">
                    <a class="erp-brand" href="<?php echo esc_url(self::url('mini-erp')); ?>"><?php echo esc_html($applicationName); ?></a>
                    <span class="erp-topbar-current" aria-current="page"><?php echo esc_html($pageLabel); ?></span>
                    <div class="erp-account">
                        <span><?php echo esc_html($user->display_name); ?></span>
                        <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Đăng xuất</a>
                    </div>
                </header>
                <div class="erp-layout">
                    <aside class="erp-sidebar" aria-label="Điều hướng ứng dụng">
                        <?php self::renderNavigation($page, $actor, $navigationGroups, 'erp-desktop-navigation'); ?>
                        <details class="erp-mobile-navigation">
                            <summary><span>Điều hướng</span><strong><?php echo esc_html($pageLabel); ?></strong></summary>
                            <?php self::renderNavigation($page, $actor, $navigationGroups, 'erp-mobile-navigation-list'); ?>
                        </details>
                    </aside>
                    <main class="erp-main" id="erp-main">
                        <?php call_user_func($definition['callback']); ?>
                    </main>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }

    private static function renderMessage(int $status, string $heading, string $message): never
    {
        status_header($status);
        nocache_headers();
        self::enqueueStyles();
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($heading . ' — Mini ERP'); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="mini-erp-frontend">
            <?php wp_body_open(); ?>
            <main class="erp-message">
                <a class="erp-brand" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(UIStudioController::applicationName()); ?></a>
                <h1><?php echo esc_html($heading); ?></h1>
                <p><?php echo esc_html($message); ?></p>
                <?php if (is_user_logged_in()): ?>
                    <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Đăng xuất</a>
                    <?php if (current_user_can('manage_options')): ?>
                        <p><a href="<?php echo esc_url(admin_url('users.php?page=mini-erp-bootstrap-users')); ?>">Cấu hình quyền truy cập ERP</a></p>
                    <?php endif; ?>
                <?php endif; ?>
            </main>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }

    /** @param array<string, list<string>> $groups */
    private static function renderNavigation(string $page, \MiniERP\Security\Actor $actor, array $groups, string $class): void
    {
        echo '<nav class="erp-navigation ' . esc_attr($class) . '" aria-label="' . esc_attr__('Điều hướng ERP', 'mini-erp') . '">';
        foreach ($groups as $groupLabel => $groupPages) {
            $visiblePages = array_filter(
                $groupPages,
                static fn (string $slug): bool => isset(self::PAGES[$slug]) && self::canAccessPage($slug, $actor)
            );
            $visiblePages = UIStudioController::orderNavigation(array_values($visiblePages));
            if ($visiblePages === []) {
                continue;
            }
            echo '<section class="erp-navigation-group" aria-label="' . esc_attr($groupLabel) . '"><h2>' . esc_html($groupLabel) . '</h2><ul>';
            foreach ($visiblePages as $slug) {
                $item = self::PAGES[$slug];
                $label = UIStudioController::pageLabel($slug, $item['label']);
                echo '<li><a href="' . esc_url(self::url($slug)) . '"' . ($page === $slug ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a></li>';
            }
            echo '</ul></section>';
        }
        echo '</nav>';
    }

    private static function enqueueStyles(string $page = ''): void
    {
        if (!function_exists('wp_enqueue_style')) {
            return;
        }

        $tokensPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/design-tokens.css';
        $tokensVersion = is_file($tokensPath) ? (string) filemtime($tokensPath) : MINI_ERP_VERSION;
        $frontendPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/frontend.css';
        $frontendVersion = is_file($frontendPath) ? (string) filemtime($frontendPath) : MINI_ERP_VERSION;
        $adminPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/admin.css';
        $adminVersion = is_file($adminPath) ? (string) filemtime($adminPath) : MINI_ERP_VERSION;
        wp_enqueue_style('mini-erp-design-tokens', plugins_url('assets/design-tokens.css', MINI_ERP_PLUGIN_FILE), [], $tokensVersion);
        wp_enqueue_style('mini-erp-admin-content', plugins_url('assets/admin.css', MINI_ERP_PLUGIN_FILE), ['mini-erp-design-tokens'], $adminVersion);
        wp_enqueue_style('mini-erp-frontend', plugins_url('assets/frontend.css', MINI_ERP_PLUGIN_FILE), ['mini-erp-admin-content'], $frontendVersion);
        if (function_exists('wp_enqueue_script')) {
            $responsiveTablePath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/responsive-tables.js';
            $responsiveTableVersion = is_file($responsiveTablePath) ? (string) filemtime($responsiveTablePath) : MINI_ERP_VERSION;
            wp_enqueue_script('mini-erp-responsive-tables', plugins_url('assets/responsive-tables.js', MINI_ERP_PLUGIN_FILE), [], $responsiveTableVersion, true);
        }
        if (in_array($page, ['mini-erp-sales', 'mini-erp-purchases'], true) && function_exists('wp_enqueue_script')) {
            $scriptPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/order-lines.js';
            $scriptVersion = is_file($scriptPath) ? (string) filemtime($scriptPath) : MINI_ERP_VERSION;
            wp_enqueue_script('mini-erp-order-lines', plugins_url('assets/order-lines.js', MINI_ERP_PLUGIN_FILE), [], $scriptVersion, true);
            wp_localize_script('mini-erp-order-lines', 'MiniErpOrderLookup', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'productNonce' => wp_create_nonce('erp_search_products'),
                'customerNonce' => wp_create_nonce('erp_search_customers'),
                'supplierNonce' => wp_create_nonce('erp_search_suppliers'),
            ]);
        }
        if (in_array($page, ['mini-erp-invoices', 'mini-erp-expenses', 'mini-erp-reconciliation'], true) && function_exists('wp_enqueue_script')) {
            $scriptPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/finance-form-recovery.js';
            $scriptVersion = is_file($scriptPath) ? (string) filemtime($scriptPath) : MINI_ERP_VERSION;
            wp_enqueue_script('mini-erp-finance-form-recovery', plugins_url('assets/finance-form-recovery.js', MINI_ERP_PLUGIN_FILE), [], $scriptVersion, true);
        }
        if ($page === 'mini-erp-inventory' && function_exists('wp_enqueue_script')) {
            $scriptPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/inventory-form-recovery.js';
            $scriptVersion = is_file($scriptPath) ? (string) filemtime($scriptPath) : MINI_ERP_VERSION;
            wp_enqueue_script('mini-erp-inventory-form-recovery', plugins_url('assets/inventory-form-recovery.js', MINI_ERP_PLUGIN_FILE), [], $scriptVersion, true);
        }
        if (in_array($page, ['mini-erp-products', 'mini-erp-customers', 'mini-erp-suppliers', 'mini-erp-warehouses', 'mini-erp-company'], true) && function_exists('wp_enqueue_script')) {
            $scriptPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/master-form-recovery.js';
            $scriptVersion = is_file($scriptPath) ? (string) filemtime($scriptPath) : MINI_ERP_VERSION;
            wp_enqueue_script('mini-erp-master-form-recovery', plugins_url('assets/master-form-recovery.js', MINI_ERP_PLUGIN_FILE), [], $scriptVersion, true);
        }
        if ($page === 'mini-erp-users' && function_exists('wp_enqueue_script')) {
            $scriptPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/user-access-form-recovery.js';
            $scriptVersion = is_file($scriptPath) ? (string) filemtime($scriptPath) : MINI_ERP_VERSION;
            wp_enqueue_script('mini-erp-user-access-form-recovery', plugins_url('assets/user-access-form-recovery.js', MINI_ERP_PLUGIN_FILE), [], $scriptVersion, true);
        }
    }
}
