<?php
declare(strict_types=1);

use MiniERP\Security\UserAccessController;

if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/wp-load.php')) {
    fwrite(STDERR, "Local WordPress CLI is required.\n");
    exit(2);
}
require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/mini-erp/mini-erp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$assert(wp_get_environment_type() === 'local' && DB_NAME === 'mini_erp_local' && $wpdb->prefix === 'paxg_', 'Refusing to run outside the isolated local WordPress ERP database.');

$suffix = strtoupper(bin2hex(random_bytes(5)));
$userIds = [];
$lastDisplayName = 'ZZZ ERP Pagination ' . $suffix . ' 100';
$failureMessage = null;
$exitCode = 0;
try {
    for ($index = 0; $index <= 100; $index++) {
        $login = 'erp_page_' . strtolower($suffix) . '_' . str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $userId = wp_insert_user([
            'user_login' => $login,
            'user_pass' => wp_generate_password(32, true, true),
            'user_email' => $login . '@example.invalid',
            'display_name' => 'ZZZ ERP Pagination ' . $suffix . ' ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'role' => 'subscriber',
        ]);
        $assert(!is_wp_error($userId), 'Could not create isolated pagination user ' . $index . '.');
        $userIds[] = (int) $userId;
    }

    $systemAdminLogin = 'erp_page_admin_' . strtolower($suffix);
    $systemAdminId = wp_insert_user([
        'user_login' => $systemAdminLogin,
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => $systemAdminLogin . '@example.invalid',
        'display_name' => 'ZZZ ERP Pagination Actor ' . $suffix,
        'role' => 'erp_system_admin',
    ]);
    $assert(!is_wp_error($systemAdminId), 'Could not create the isolated ERP System Admin.');
    $systemAdminId = (int) $systemAdminId;
    $userIds[] = $systemAdminId;
    update_user_meta($systemAdminId, '_mini_erp_enabled', '1');

    $technicalAdminId = (int) username_exists('erp_local_admin');
    $assert($technicalAdminId > 0, 'The local technical WordPress administrator is missing.');
    wp_set_current_user($technicalAdminId);
    $assert(current_user_can('manage_options'), 'The local technical account cannot access the bootstrap screen.');
    $render = static function (callable $screen, array $query): string {
        $_GET = $query;
        ob_start();
        $screen();
        return (string) ob_get_clean();
    };

    $bootstrapFirst = $render(static fn() => UserAccessController::renderBootstrap(), ['erp_user_page' => '1']);
    $bootstrapLast = $render(static fn() => UserAccessController::renderBootstrap(), ['erp_user_page' => '3']);
    $assert(!str_contains($bootstrapFirst, $lastDisplayName), 'Bootstrap page 1 unexpectedly contains the last fixture user.');
    $assert(str_contains($bootstrapLast, $lastDisplayName), 'The bootstrap screen does not expose users beyond the first 100.');
    $assert(str_contains($bootstrapLast, 'Trang 3 / 3'), 'The bootstrap screen does not show its current page and page count.');
    $assert(str_contains($bootstrapLast, 'erp_user_page=2'), 'The bootstrap screen is missing an accessible previous-page link.');

    wp_set_current_user($systemAdminId);
    $assert(MiniERP\Security\CapabilityPolicy::currentActor() !== null, 'The ERP System Admin test identity is not enabled.');
    $erpFirst = $render(static fn() => UserAccessController::render(), ['erp_user_page' => '1']);
    $erpLast = $render(static fn() => UserAccessController::render(), ['erp_user_page' => '3']);
    $assert(!str_contains($erpFirst, $lastDisplayName), 'ERP user-access page 1 unexpectedly contains the last fixture user.');
    $assert(str_contains($erpLast, $lastDisplayName), 'The ERP user-access screen does not expose users beyond the first 100.');
    $assert(str_contains($erpLast, 'Trang 3 / 3'), 'The ERP user-access screen does not show its current page and page count.');
    $assert(!str_contains($erpFirst, 'ZZZ ERP Pagination Actor ' . $suffix), 'The System Admin can edit their own access row.');
    $assert(str_contains($erpLast, 'data-access-form="user-access" data-access-actor="' . $systemAdminId . '"')
        && str_contains($erpLast, 'name="erp_user_page" value="3"'), 'ERP user-access rows do not carry actor identity and the current page for safe form recovery.');

    fwrite(STDOUT, "PASS: WordPress bootstrap and ERP role pages paginate beyond 100 users, exclude administrators/current actor, and show page navigation.\n");
} catch (Throwable $failure) {
    $exitCode = 1;
    $failureMessage = 'User-access pagination smoke failed: ' . get_class($failure) . ' (' . $failure->getCode() . ') ' . $failure->getMessage();
} finally {
    wp_set_current_user(0);
    foreach ($userIds as $userId) {
        if (!wp_delete_user($userId)) {
            $exitCode = 1;
            $failureMessage = 'Could not remove temporary pagination user ' . $userId . '.';
        }
    }
    $_GET = [];
    $remaining = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s",
        'erp_page_' . strtolower($suffix) . '_%'
    ));
    if ($remaining !== 0) {
        $exitCode = 1;
        $failureMessage = 'Temporary pagination users remain after cleanup.';
    }
}

if ($failureMessage !== null) fwrite(STDERR, $failureMessage . "\n");
if ($exitCode === 0) fwrite(STDOUT, "Pagination smoke passed; temporary users were removed.\n");
exit($exitCode);
