<?php
declare(strict_types=1);

use MiniERP\Admin\Controllers\CatalogController;
use MiniERP\Admin\Controllers\CompanyProfileController;
use MiniERP\Admin\Controllers\PartyController;
use MiniERP\Security\CapabilityPolicy;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Master form page smoke is CLI-only.\n");
    exit(2);
}
$wpLoad = '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "Local WordPress bootstrap is unavailable.\n");
    exit(2);
}
require_once $wpLoad;
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

global $wpdb;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the isolated local WordPress site.');
$suffix = bin2hex(random_bytes(6));
$categoryCode = 'MASTER-FORM-' . strtoupper($suffix);
$categoryId = 0;
$userIds = [];
$results = [];
$exitCode = 0;

try {
    $assert($wpdb->insert($wpdb->prefix . 'erp_categories', ['code' => $categoryCode, 'name' => 'Master form recovery smoke', 'is_active' => 1]) === 1, 'Could not create a local category fixture for the product form.');
    $categoryId = (int) $wpdb->insert_id;
    $login = 'erp_master_form_' . $suffix;
    $createdUser = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $login . '@example.invalid',
        'role' => 'erp_manager',
    ]);
    if (is_wp_error($createdUser)) {
        throw new RuntimeException('Could not create temporary Manager fixture.');
    }
    $managerId = (int) $createdUser;
    $userIds[] = $managerId;
    update_user_meta($managerId, '_mini_erp_enabled', '1');
    wp_set_current_user($managerId);
    $actor = CapabilityPolicy::currentActor();
    $assert($actor !== null, 'Temporary Manager did not resolve to an ERP actor.');

    $_GET = [];
    ob_start();
    CatalogController::render();
    $catalog = (string) ob_get_clean();
    foreach (['product', 'category'] as $type) {
        $assert(str_contains($catalog, 'data-master-form="' . $type . '" data-master-actor="' . $managerId . '" data-master-scope="new"'), 'Catalog ' . $type . ' form is missing its actor-bound recovery identity.');
    }
    $results[] = 'Product and category pages expose actor/form-scoped recovery markers';

    $systemAdminLogin = 'erp_master_profile_' . $suffix;
    $systemAdmin = wp_insert_user([
        'user_login' => $systemAdminLogin,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $systemAdminLogin . '@example.invalid',
        'role' => 'erp_system_admin',
    ]);
    if (is_wp_error($systemAdmin)) {
        throw new RuntimeException('Could not create temporary ERP System Admin fixture.');
    }
    $systemAdminId = (int) $systemAdmin;
    $userIds[] = $systemAdminId;
    update_user_meta($systemAdminId, '_mini_erp_enabled', '1');
    wp_set_current_user($systemAdminId);
    $_GET = [];
    ob_start();
    CompanyProfileController::render();
    $companyProfile = (string) ob_get_clean();
    $assert(str_contains($companyProfile, 'data-master-form="company-profile" data-master-actor="' . $systemAdminId . '" data-master-scope="profile"'), 'Company profile page is missing its actor-bound recovery marker.');
    $results[] = 'Company profile form exposes its scoped recovery identity';

    wp_set_current_user($managerId);
    $partyPages = [
        'customer' => [PartyController::class, 'customers'],
        'supplier' => [PartyController::class, 'suppliers'],
        'warehouse' => [PartyController::class, 'warehouses'],
    ];
    foreach ($partyPages as $type => $callback) {
        $_GET = [];
        ob_start();
        call_user_func($callback);
        $html = (string) ob_get_clean();
        $assert(str_contains($html, 'data-master-form="' . $type . '" data-master-actor="' . $managerId . '" data-master-scope="new"'), ucfirst($type) . ' page is missing its actor-bound recovery marker.');
        $results[] = ucfirst($type) . ' page renders its scoped recovery form';
    }
} catch (Throwable $failure) {
    $exitCode = 1;
    fwrite(STDERR, 'Master form page smoke failed (' . get_class($failure) . ', code ' . $failure->getCode() . '). ' . $failure->getMessage() . "\n");
} finally {
    wp_set_current_user(1);
    foreach ($userIds as $userId) {
        if (!wp_delete_user($userId)) {
            $exitCode = 1;
            fwrite(STDERR, "Could not remove a temporary ERP actor fixture.\n");
        }
    }
    if ($categoryId > 0 && $wpdb->delete($wpdb->prefix . 'erp_categories', ['id' => $categoryId]) !== 1) {
        $exitCode = 1;
        fwrite(STDERR, "Could not remove the temporary category fixture.\n");
    }
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Master form page smoke passed; temporary user and category were removed.\n - " . implode("\n - ", $results) . "\n");
}
exit($exitCode);
