<?php
declare(strict_types=1);

// Local fixture bootstrap only. Never an ERP data migration or production installer.
if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_NAME') !== 'mini_erp_local') {
    throw new RuntimeException('Only the isolated local fixture database is permitted.');
}
define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Refusing non-local WordPress.');
}
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
// Local fixture must never send email externally.
add_filter('pre_wp_mail', '__return_true');
if (!is_blog_installed()) {
    if (in_array('--check', $argv, true)) {
        throw new RuntimeException('WordPress is not installed; run local.sh start.');
    }
    $password = getenv('ERP_LOCAL_ADMIN_PASSWORD');
    if (!is_string($password) || strlen($password) < 20) {
        throw new RuntimeException('Missing local fixture password.');
    }
    wp_install('Mini ERP — môi trường kiểm thử nội bộ', 'erp_local_admin', 'local@example.invalid', false, '', $password);
    update_option('timezone_string', 'Asia/Ho_Chi_Minh');
    update_option('blog_public', '0');
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!in_array('--check', $argv, true) && !is_plugin_active('mini-erp/mini-erp.php')) {
    $activation = activate_plugin('mini-erp/mini-erp.php');
    if (is_wp_error($activation)) {
        throw new RuntimeException('Could not activate the local Mini ERP plugin: ' . $activation->get_error_message());
    }
}
global $wpdb, $wp_version;
echo json_encode([
    'installed' => is_blog_installed(),
    'wordpress' => $wp_version,
    'php' => PHP_VERSION,
    'mysql' => $wpdb->get_var('SELECT VERSION()'),
    'prefix' => $wpdb->prefix,
    'environment' => wp_get_environment_type(),
    'timezone' => wp_timezone_string(),
    'bcmath' => extension_loaded('bcmath'),
    'site_id' => get_option('siteurl'),
    'admin_id' => (int) username_exists('erp_local_admin'),
    'mini_erp_active' => is_plugin_active('mini-erp/mini-erp.php'),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
