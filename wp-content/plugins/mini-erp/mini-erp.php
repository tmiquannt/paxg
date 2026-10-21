<?php
/**
 * Plugin Name: Mini ERP
 * Description: Internal operations, inventory and accounting workflows for WordPress.
 * Version: 1.1.2
 * Requires PHP: 8.3
 * Text Domain: mini-erp
 */
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('MINI_ERP_VERSION', '1.1.2');
define('MINI_ERP_PLUGIN_FILE', __FILE__);

$miniErpComposerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($miniErpComposerAutoload)) {
    require_once $miniErpComposerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'MiniERP\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, [MiniERP\Bootstrap::class, 'activate']);
MiniERP\Bootstrap::register();
