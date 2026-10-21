<?php
declare(strict_types=1);

use MiniERP\Infrastructure\Database\MasterSchema;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This master-schema smoke test is CLI-only.\n");
    exit(2);
}

$wpLoad = '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "Local WordPress bootstrap is unavailable.\n");
    exit(2);
}
require_once $wpLoad;
require_once WP_PLUGIN_DIR . '/mini-erp/src/Infrastructure/Database/MasterSchema.php';

global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$assert(defined('WP_ENVIRONMENT_TYPE') && wp_get_environment_type() === 'local', 'Refusing to run outside the local WordPress environment.');
$assert(defined('DB_NAME') && DB_NAME === 'mini_erp_local', 'Refusing to run against an unexpected database.');
$assert(isset($wpdb->prefix) && $wpdb->prefix === 'paxg_', 'Refusing to run with an unexpected WordPress table prefix.');

$prefix = 'paxg_test_' . bin2hex(random_bytes(6)) . '_';
$assert((bool) preg_match('/\Apaxg_test_[a-f0-9]{12}_\z/D', $prefix), 'Generated fixture prefix failed its safety allowlist.');
$db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$db->prefix = $prefix;
$schema = null;
$lockDb = null;
$fixtureNames = [];
$exitCode = 0;
$summary = [];
$expectFailure = static function (callable $operation, string $message): Throwable {
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    throw new RuntimeException($message);
};
$query = static function (wpdb $connection, string $sql, array $args = []): int|bool {
    $prepared = $args === [] ? $sql : $connection->prepare($sql, $args);
    $result = $connection->query($prepared);
    if ($result === false) {
        throw new RuntimeException('A fixture database operation failed.');
    }
    return $result;
};
$scalar = static function (wpdb $connection, string $sql, array $args = []): string {
    $prepared = $args === [] ? $sql : $connection->prepare($sql, $args);
    $value = $connection->get_var($prepared);
    if ($value === null) {
        throw new RuntimeException('A fixture verification query failed.');
    }
    return (string) $value;
};

try {
    $schema = new MasterSchema($db);
    $fixtureNames = $schema->tableNames();
    $expectedNames = [
        $prefix . 'erp_categories',
        $prefix . 'erp_products',
        $prefix . 'erp_customers',
        $prefix . 'erp_suppliers',
        $prefix . 'erp_warehouses',
    ];
    $assert($fixtureNames === $expectedNames, 'Migration returned unexpected fixture table names.');
    foreach ($fixtureNames as $name) {
        $assert((bool) preg_match('/\A' . preg_quote($prefix, '/') . 'erp_(categories|products|customers|suppliers|warehouses)\z/D', $name), 'Refusing to touch a table outside the allowlisted test prefix.');
    }

    $unsafePrefix = $db->prefix;
    foreach (['bad-prefix_', str_repeat('x', 33) . '_'] as $invalidPrefix) {
        $db->prefix = $invalidPrefix;
        $expectFailure(static fn (): MasterSchema => new MasterSchema($db), 'Unsafe prefix was accepted.');
    }
    $db->prefix = $unsafePrefix;
    $summary[] = 'unsafe and overlong prefixes rejected before schema queries: pass';

    // Hold the migration lock on a distinct WPDB session to prove acquisition is bounded.
    $lockDb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lockName = 'mini-erp-migrate:' . substr(hash('sha256', DB_NAME . ':' . $prefix), 0, 40);
    $assert($scalar($lockDb, 'SELECT GET_LOCK(%s, 0)', [$lockName]) === '1', 'Could not acquire the disposable migration-lock fixture.');
    $lockStarted = microtime(true);
    $expectFailure(static fn () => $schema->migrate(), 'Migration proceeded while another session held its lock.');
    $lockElapsed = microtime(true) - $lockStarted;
    $assert($lockElapsed >= 4.0 && $lockElapsed < 12.0, 'Migration lock wait was not bounded near the configured timeout.');
    $assert($scalar($lockDb, 'SELECT RELEASE_LOCK(%s)', [$lockName]) === '1', 'Could not release the disposable migration-lock fixture.');
    $summary[] = 'database advisory lock contention is bounded and released: pass';

    $schema->migrate();
    $summary[] = 'five master tables created under isolated prefix: pass';

    $category = $prefix . 'erp_categories';
    $product = $prefix . 'erp_products';
    $customer = $prefix . 'erp_customers';
    $supplier = $prefix . 'erp_suppliers';
    $warehouse = $prefix . 'erp_warehouses';

    $query($db, "INSERT INTO `{$category}` (`code`, `name`) VALUES (%s, %s)", ['CAT-1', 'Test category']);
    $categoryId = (int) $db->insert_id;
    $query($db, "INSERT INTO `{$product}` (`sku`, `name`, `category_id`, `unit`, `unit_step`, `sale_price_vnd`) VALUES (%s, %s, %d, %s, %s, %d)", ['SKU-1', 'Test product', $categoryId, 'kg', '0.001', 125]);
    $query($db, "INSERT INTO `{$customer}` (`code`, `name`) VALUES (%s, %s)", ['CUS-1', 'Test customer']);
    $query($db, "INSERT INTO `{$supplier}` (`code`, `name`) VALUES (%s, %s)", ['SUP-1', 'Test supplier']);
    $query($db, "INSERT INTO `{$warehouse}` (`code`, `name`) VALUES (%s, %s)", ['WH-1', 'Test warehouse']);

    $assert($scalar($db, "SELECT COUNT(*) FROM `{$product}` WHERE `sku` = %s", ['SKU-1']) === '1', 'Valid product row was not persisted.');
    $timestamp = $scalar($db, "SELECT `created_at` FROM `{$category}` WHERE `id` = %d", [$categoryId]);
    $assert((bool) preg_match('/\.\d{6}\z/D', $timestamp), 'DATETIME(6) did not preserve microsecond precision.');

    foreach ([
        fn () => $query($db, "INSERT INTO `{$category}` (`code`, `name`) VALUES (%s, %s)", ['CAT-1', 'Duplicate']),
        fn () => $query($db, "INSERT INTO `{$product}` (`sku`, `name`, `category_id`, `unit`, `unit_step`, `sale_price_vnd`) VALUES (%s, %s, %d, %s, %s, %d)", ['SKU-1', 'Duplicate', $categoryId, 'kg', '1.000', 1]),
        fn () => $query($db, "INSERT INTO `{$product}` (`sku`, `name`, `category_id`, `unit`, `unit_step`, `sale_price_vnd`) VALUES (%s, %s, %d, %s, %s, %d)", ['SKU-ORPHAN', 'Orphan', 999999999, 'kg', '1.000', 1]),
        fn () => $query($db, "DELETE FROM `{$category}` WHERE `id` = %d", [$categoryId]),
        fn () => $query($db, "INSERT INTO `{$product}` (`sku`, `name`, `category_id`, `unit`, `unit_step`, `sale_price_vnd`) VALUES (%s, %s, %d, %s, %s, %d)", ['SKU-BAD-STEP', 'Bad step', $categoryId, 'kg', '0.000', 1]),
        fn () => $query($db, "INSERT INTO `{$product}` (`sku`, `name`, `category_id`, `unit`, `unit_step`, `sale_price_vnd`) VALUES (%s, %s, %d, %s, %s, %d)", ['SKU-BAD-PRICE', 'Bad price', $categoryId, 'kg', '1.000', -1]),
        fn () => $query($db, "INSERT INTO `{$warehouse}` (`code`, `name`, `is_active`) VALUES (%s, %s, %d)", ['WH-BAD', 'Bad active', 2]),
    ] as $case) {
        $expectFailure($case, 'A required unique, foreign-key, or CHECK constraint was not enforced.');
    }
    $schema->migrate();
    $assert($scalar($db, "SELECT COUNT(*) FROM `{$category}` WHERE `code` = %s", ['CAT-1']) === '1', 'Repeat migration changed existing rows.');
    $summary[] = 'uniqueness, FK/restrict, CHECKs, timestamps and idempotent repeat: pass';

    // Keep one compatible table and row, remove the other four exact fixture tables, then resume.
    foreach ([$warehouse, $supplier, $customer, $product] as $name) {
        $query($db, "DROP TABLE `{$name}`");
    }
    $schema->migrate();
    $assert($scalar($db, "SELECT COUNT(*) FROM `{$category}` WHERE `code` = %s", ['CAT-1']) === '1', 'Resuming a partial schema did not preserve the compatible table.');
    $summary[] = 'partial compatible schema resumes without replacing existing data: pass';

    $query($db, "DROP TABLE `{$warehouse}`");
    $query($db, "CREATE TABLE `{$warehouse}` (`id` INT NOT NULL PRIMARY KEY, `sentinel` VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $query($db, "INSERT INTO `{$warehouse}` (`id`, `sentinel`) VALUES (1, 'preserve-me')");
    $expectFailure(static fn () => $schema->migrate(), 'Incompatible existing table was accepted.');
    $assert($scalar($db, "SELECT `sentinel` FROM `{$warehouse}` WHERE `id` = 1") === 'preserve-me', 'Incompatible table data was modified.');
    $summary[] = 'incompatible existing table fails closed and preserves its row: pass';
} catch (Throwable $failure) {
    $exitCode = 1;
    fwrite(STDERR, 'Master-schema smoke failed (' . get_class($failure) . ', code ' . $failure->getCode() . '). ' . $failure->getMessage() . "\n");
} finally {
    if ($lockDb instanceof wpdb) {
        $lockDb->close();
    }

    if ($schema instanceof MasterSchema && $db instanceof wpdb && $db->prefix === $prefix) {
        foreach (array_reverse($fixtureNames) as $name) {
            if (preg_match('/\A' . preg_quote($prefix, '/') . 'erp_(categories|products|customers|suppliers|warehouses)\z/D', $name)) {
                if ($db->query("DROP TABLE IF EXISTS `{$name}`") === false) {
                    $exitCode = 1;
                    fwrite(STDERR, "Could not remove an allowlisted master-schema fixture table.\n");
                }
            }
        }
    }
    if ($db instanceof wpdb) {
        $db->close();
    }
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Master-schema smoke passed; isolated fixture tables were removed.\n - " . implode("\n - ", $summary) . "\n");
}
exit($exitCode);
