<?php
declare(strict_types=1);

namespace MiniERP;

use MiniERP\Infrastructure\Database\MasterSchema;
use MiniERP\Infrastructure\Database\AuditSchema;
use MiniERP\Security\RolesInstaller;
use RuntimeException;
use Throwable;

final class Bootstrap
{
    private const SCHEMA_VERSION = '2026.10.20.4';
    private const ROLE_VERSION = '2026.10.21.2';
    private const SCHEMA_GATED_AJAX_ACTIONS = [
        'erp_search_products',
        'erp_search_customers',
        'erp_search_suppliers',
    ];
    private static ?string $schemaUpgradeError = null;
    private static ?string $roleUpgradeError = null;

    public static function register(): void
    {
        if (function_exists('add_action')) {
            add_action('init', [self::class, 'upgradeSchemaIfNeeded'], 0);
            add_action('init', [self::class, 'upgradeRoleCapabilitiesIfNeeded'], 1);
            add_action('admin_init', [self::class, 'enforceSchemaForErpAction'], -1);
            add_action('admin_notices', [self::class, 'renderSchemaUpgradeNotice']);
            add_action('admin_menu', [self::class, 'registerBootstrapAdminPage']);
            add_action('admin_menu', [self::class, 'registerUiStudioAdminPage']);
            add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
            add_action('login_enqueue_scripts', [self::class, 'enqueueLoginAssets']);
            add_filter('login_headerurl', [self::class, 'loginHeaderUrl']);
            add_filter('login_headertext', [self::class, 'loginHeaderText']);
            add_filter('editable_roles', [Security\UserAccessController::class, 'hideErpRolesFromCoreRoleDropdown']);
            add_action('admin_post_mini_erp_user_access_save', [Security\UserAccessController::class, 'save']);
            add_action('admin_post_mini_erp_user_create', [Security\UserAccessController::class, 'create']);
            add_action('admin_post_mini_erp_bootstrap_user_access_save', [Security\UserAccessController::class, 'saveBootstrap']);
            add_action('admin_post_mini_erp_ui_studio_save', [Admin\Controllers\UIStudioController::class, 'save']);
            add_action('admin_post_mini_erp_category_save', [Admin\Controllers\CatalogController::class, 'saveCategory']);
            add_action('admin_post_mini_erp_product_save', [Admin\Controllers\CatalogController::class, 'saveProduct']);
            add_action('admin_post_mini_erp_product_status', [Admin\Controllers\CatalogController::class, 'setProductActive']);
            add_action('admin_post_mini_erp_category_status', [Admin\Controllers\CatalogController::class, 'setCategoryActive']);
            add_action('admin_post_mini_erp_customer_save', [Admin\Controllers\PartyController::class, 'saveCustomer']);
            add_action('admin_post_mini_erp_supplier_save', [Admin\Controllers\PartyController::class, 'saveSupplier']);
            add_action('admin_post_mini_erp_warehouse_save', [Admin\Controllers\PartyController::class, 'saveWarehouse']);
            add_action('admin_post_mini_erp_customer_status', [Admin\Controllers\PartyController::class, 'customerStatus']);
            add_action('admin_post_mini_erp_supplier_status', [Admin\Controllers\PartyController::class, 'supplierStatus']);
            add_action('admin_post_mini_erp_warehouse_status', [Admin\Controllers\PartyController::class, 'warehouseStatus']);
            add_action('admin_post_mini_erp_sales_save', [Admin\Controllers\SalesController::class, 'save']);
            add_action('admin_post_mini_erp_sales_transition', [Admin\Controllers\SalesController::class, 'transition']);
            add_action('admin_post_mini_erp_shipment_post', [Admin\Controllers\SalesController::class, 'ship']);
            add_action('admin_post_mini_erp_purchase_save', [Admin\Controllers\PurchaseController::class, 'save']);
            add_action('admin_post_mini_erp_purchase_transition', [Admin\Controllers\PurchaseController::class, 'transition']);
            add_action('admin_post_mini_erp_goods_receipt', [Admin\Controllers\PurchaseController::class, 'receive']);
            add_action('admin_post_mini_erp_invoice_issue', [Admin\Controllers\InvoiceController::class, 'issue']);
            add_action('admin_post_mini_erp_invoice_print', [Admin\Controllers\InvoiceController::class, 'print']);
            add_action('admin_post_mini_erp_invoice_pdf', [Admin\Controllers\InvoiceController::class, 'pdf']);
            add_action('admin_post_mini_erp_invoice_void', [Admin\Controllers\InvoiceController::class, 'void']);
            add_action('admin_post_mini_erp_company_profile_save', [Admin\Controllers\CompanyProfileController::class, 'save']);
            add_action('admin_post_mini_erp_cash_account_save', [Admin\Controllers\CashController::class, 'saveAccount']);
            add_action('admin_post_mini_erp_cash_account_activate', [Admin\Controllers\CashController::class, 'activateAccount']);
            add_action('admin_post_mini_erp_payment_post', [Admin\Controllers\CashController::class, 'postPayment']);
            add_action('admin_post_mini_erp_expense_post', [Admin\Controllers\CashController::class, 'postExpense']);
            add_action('admin_post_mini_erp_payment_void', [Admin\Controllers\CashController::class, 'paymentVoid']);
            add_action('admin_post_mini_erp_payment_voucher_print', [Admin\Controllers\CashController::class, 'paymentVoucherPrint']);
            add_action('admin_post_mini_erp_payment_voucher_pdf', [Admin\Controllers\CashController::class, 'paymentVoucherPdf']);
            add_action('admin_post_mini_erp_expense_voucher_print', [Admin\Controllers\CashController::class, 'expenseVoucherPrint']);
            add_action('admin_post_mini_erp_expense_voucher_pdf', [Admin\Controllers\CashController::class, 'expenseVoucherPdf']);
            add_action('admin_post_mini_erp_reconciliation_create', [Admin\Controllers\ReconciliationController::class, 'create']);
            add_action('admin_post_mini_erp_reconciliation_match', [Admin\Controllers\ReconciliationController::class, 'match']);
            add_action('admin_post_mini_erp_reconciliation_close', [Admin\Controllers\ReconciliationController::class, 'close']);
            add_action('wp_ajax_erp_search_products', [Admin\Controllers\LookupController::class, 'searchProducts']);
            add_action('wp_ajax_erp_search_customers', [Admin\Controllers\LookupController::class, 'searchCustomers']);
            add_action('wp_ajax_erp_search_suppliers', [Admin\Controllers\LookupController::class, 'searchSuppliers']);
            add_action('admin_post_mini_erp_inventory_start', [Admin\Controllers\InventoryController::class, 'startDraft']);
            add_action('admin_post_mini_erp_inventory_count', [Admin\Controllers\InventoryController::class, 'saveDraft']);
            add_action('admin_post_mini_erp_inventory_request', [Admin\Controllers\InventoryController::class, 'request']);
            add_action('admin_post_mini_erp_inventory_decide', [Admin\Controllers\InventoryController::class, 'decide']);
            add_action('admin_post_mini_erp_inventory_apply', [Admin\Controllers\InventoryController::class, 'apply']);
            add_action('admin_post_mini_erp_report_export', [Admin\Controllers\ReportController::class, 'export']);
            Frontend\AppRouter::register();
        }
        if (function_exists('add_filter')) {
            add_filter('is_protected_meta', [self::class, 'protectAccessMetadata'], 10, 3);
        }
    }

    public static function activate(): void
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new RuntimeException('Mini ERP requires the WordPress database connection.');
        }

        (new MasterSchema($wpdb))->migrate();
        (new AuditSchema($wpdb))->migrate();
        (new Infrastructure\Database\SalesSchema($wpdb))->migrate();
        (new Infrastructure\Database\InventorySchema($wpdb))->migrate();
        (new Infrastructure\Database\PurchaseSchema($wpdb))->migrate();
        (new Infrastructure\Database\InvoiceSchema($wpdb))->migrate();
        (new Infrastructure\Database\CashSchema($wpdb))->migrate();
        RolesInstaller::install();
        if (function_exists('update_option')) update_option('mini_erp_schema_version', self::SCHEMA_VERSION, false);
        if (function_exists('update_option')) update_option('mini_erp_role_version', self::ROLE_VERSION, false);
    }

    /** Upgrade role capabilities separately from business schema migrations. */
    public static function upgradeRoleCapabilitiesIfNeeded(): void
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !function_exists('get_option')
            || get_option('mini_erp_role_version', '') === self::ROLE_VERSION) {
            return;
        }
        if (!function_exists('current_user_can') || (!current_user_can('manage_options') && !current_user_can('erp_settings_manage'))) {
            return;
        }
        try {
            RolesInstaller::install();
            update_option('mini_erp_role_version', self::ROLE_VERSION, false);
            self::$roleUpgradeError = null;
        } catch (Throwable) {
            self::$roleUpgradeError = 'Mini ERP role capabilities could not be upgraded. ERP administration remains restricted until the update succeeds.';
        }
    }

    /** Run idempotent additive migrations for an already-active plugin after a code update. */
    public static function upgradeSchemaIfNeeded(): void
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !function_exists('get_option')
            || get_option('mini_erp_schema_version', '') === self::SCHEMA_VERSION) return;
        if (!function_exists('current_user_can') || (!current_user_can('manage_options') && !current_user_can('erp_settings_manage'))) return;
        global $wpdb;
        if (!$wpdb instanceof \wpdb) { self::$schemaUpgradeError = 'WordPress database connection unavailable.'; return; }
        try {
            (new MasterSchema($wpdb))->migrate();
            (new AuditSchema($wpdb))->migrate();
            (new Infrastructure\Database\SalesSchema($wpdb))->migrate();
            (new Infrastructure\Database\InventorySchema($wpdb))->migrate();
            (new Infrastructure\Database\PurchaseSchema($wpdb))->migrate();
            (new Infrastructure\Database\InvoiceSchema($wpdb))->migrate();
            (new Infrastructure\Database\CashSchema($wpdb))->migrate();
            update_option('mini_erp_schema_version', self::SCHEMA_VERSION, false);
            self::$schemaUpgradeError = null;
        } catch (Throwable) {
            self::$schemaUpgradeError = 'Mini ERP schema upgrade failed. Business screens remain unavailable until the database upgrade succeeds.';
        }
    }

    public static function schemaReady(): bool
    {
        return function_exists('get_option') && get_option('mini_erp_schema_version', '') === self::SCHEMA_VERSION;
    }

    /** Block ERP mutation/download posts if a privileged upgrade did not finish first. */
    public static function enforceSchemaForErpAction(): void
    {
        $action=$_REQUEST['action']??null;
        if(!self::isSchemaGatedAction($action)||self::schemaReady())return;
        wp_die('Bản cập nhật cơ sở dữ liệu Mini ERP chưa hoàn tất. Hãy nhờ ERP System Admin hoặc WordPress administrator hoàn tất cập nhật trước khi tiếp tục.','Mini ERP',['response'=>503]);
    }

    /** Identify ERP request actions whose handlers depend on the installed schema. */
    public static function isSchemaGatedAction(mixed $action): bool
    {
        if (!is_scalar($action)) return false;
        $action = function_exists('wp_unslash') ? (string) wp_unslash($action) : (string) $action;
        return str_starts_with($action, 'mini_erp_') || in_array($action, self::SCHEMA_GATED_AJAX_ACTIONS, true);
    }

    public static function renderSchemaUpgradeNotice(): void
    {
        if (self::$schemaUpgradeError !== null) echo '<div class="notice notice-error"><p>' . esc_html(self::$schemaUpgradeError) . '</p></div>';
        if (self::$roleUpgradeError !== null) echo '<div class="notice notice-error"><p>' . esc_html(self::$roleUpgradeError) . '</p></div>';
    }

    public static function registerBootstrapAdminPage(): void
    {
        if (!function_exists('add_users_page')) {
            return;
        }

        add_users_page(
            __('Cấp vai trò ERP', 'mini-erp'),
            __('ERP Access', 'mini-erp'),
            'manage_options',
            'mini-erp-bootstrap-users',
            [Security\UserAccessController::class, 'renderBootstrap']
        );
    }

    public static function registerUiStudioAdminPage(): void
    {
        if (!function_exists('add_menu_page')) {
            return;
        }
        add_menu_page(
            __('ERP UI Studio', 'mini-erp'),
            __('ERP UI Studio', 'mini-erp'),
            'erp_ui_studio_manage',
            'mini-erp-ui-studio',
            [Admin\Controllers\UIStudioController::class, 'render'],
            'dashicons-admin-customizer',
            58
        );
    }

    public static function enqueueAdminAssets(string $hookSuffix): void
    {
        $page = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page === 'mini-erp' || str_starts_with($page, 'mini-erp-')) {
            $tokensPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/design-tokens.css';
            $adminPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/admin.css';
            $tokensVersion = is_file($tokensPath) ? (string) filemtime($tokensPath) : MINI_ERP_VERSION;
            $adminVersion = is_file($adminPath) ? (string) filemtime($adminPath) : MINI_ERP_VERSION;
            wp_enqueue_style('mini-erp-design-tokens', plugins_url('../assets/design-tokens.css', __FILE__), [], $tokensVersion);
            wp_enqueue_style('mini-erp-admin', plugins_url('../assets/admin.css', __FILE__), ['mini-erp-design-tokens'], $adminVersion);
            if ($page === 'mini-erp-bootstrap-users') {
                $scriptPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/responsive-tables.js';
                $scriptVersion = is_file($scriptPath) ? (string) filemtime($scriptPath) : MINI_ERP_VERSION;
                wp_enqueue_script('mini-erp-responsive-tables', plugins_url('../assets/responsive-tables.js', __FILE__), [], $scriptVersion, true);
            }
        }
    }

    public static function enqueueLoginAssets(): void
    {
        $tokensPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/design-tokens.css';
        $loginPath = plugin_dir_path(MINI_ERP_PLUGIN_FILE) . 'assets/login.css';
        $tokensVersion = is_file($tokensPath) ? (string) filemtime($tokensPath) : MINI_ERP_VERSION;
        $loginVersion = is_file($loginPath) ? (string) filemtime($loginPath) : MINI_ERP_VERSION;
        wp_enqueue_style('mini-erp-design-tokens', plugins_url('../assets/design-tokens.css', __FILE__), [], $tokensVersion);
        wp_enqueue_style('mini-erp-login', plugins_url('../assets/login.css', __FILE__), ['mini-erp-design-tokens'], $loginVersion);
    }

    public static function loginHeaderUrl(string $url): string
    {
        return home_url('/');
    }

    public static function loginHeaderText(string $text): string
    {
        return Admin\Controllers\UIStudioController::applicationName();
    }

    public static function protectAccessMetadata(bool $protected, string $metaKey, string $metaType): bool
    {
        if ($metaType === 'user' && in_array($metaKey, [
            '_mini_erp_enabled',
            '_mini_erp_warehouse_ids',
            '_mini_erp_cash_account_ids',
        ], true)) {
            return true;
        }
        return $protected;
    }

    public static function renderHome(): void
    {
        Admin\Controllers\ReportController::dashboard();
    }
}
