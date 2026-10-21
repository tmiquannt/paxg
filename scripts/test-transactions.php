<?php
declare(strict_types=1);

use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This transaction smoke test is CLI-only.\n");
    exit(2);
}

$wpLoad = '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "Local WordPress bootstrap is unavailable.\n");
    exit(2);
}
require_once $wpLoad;

global $wpdb;

$pluginSource = WP_PLUGIN_DIR . '/mini-erp/src/Infrastructure/Database';
require_once $pluginSource . '/DatabaseException.php';
require_once $pluginSource . '/TransactionManager.php';

$table = 'paxg_erp_test_tx_' . bin2hex(random_bytes(8));
if (!preg_match('/\Apaxg_erp_test_tx_[a-f0-9]{16}\z/D', $table)) {
    fwrite(STDERR, "Generated fixture name failed its safety allowlist.\n");
    exit(2);
}

$created = false;
$lockConnection = null;
$lossTestDb = null;
$previousLockWait = null;
$exitCode = 0;
$summary = [];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$scalar = static function (string $sql) use ($wpdb): string {
    $value = $wpdb->get_var($sql);
    if ($value === null) {
        throw new RuntimeException('Fixture verification query failed.');
    }
    return (string) $value;
};

try {
    $assert(defined('WP_ENVIRONMENT_TYPE') && wp_get_environment_type() === 'local', 'Refusing to run outside the local WordPress environment.');
    $assert(defined('DB_NAME') && DB_NAME === 'mini_erp_local', 'Refusing to run against an unexpected database.');
    $assert(isset($wpdb->prefix) && $wpdb->prefix === 'paxg_', 'Refusing to run with an unexpected table prefix.');

    $create = "CREATE TABLE `{$table}` ("
        . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . '`value` VARCHAR(255) NOT NULL,'
        . 'PRIMARY KEY (`id`), UNIQUE KEY `value_unique` (`value`)'
        . ') ENGINE=InnoDB';
    $assert($wpdb->query($create) !== false, 'Could not create the isolated InnoDB fixture.');
    $created = true;

    $transactions = new TransactionManager($wpdb);

    $affected = $transactions->run(static fn (TransactionManager $tx): int => $tx->execute(
        "INSERT INTO `{$table}` (`value`) VALUES (%s)",
        ['committed-row']
    ));
    $assert($affected === 1, 'Committed insert did not report one affected row.');
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'committed-row'") === '1', 'Committed row was not durable.');
    $summary[] = 'commit/value: pass';

    try {
        $transactions->run(static function (TransactionManager $tx) use ($table): void {
            $tx->execute("INSERT INTO `{$table}` (`value`) VALUES (%s)", ['rolled-back-row']);
            throw new RuntimeException('intentional rollback');
        });
        throw new RuntimeException('Callback rollback was not propagated.');
    } catch (RuntimeException $failure) {
        $assert($failure->getMessage() === 'intentional rollback', 'Unexpected callback rollback result.');
    }
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'rolled-back-row'") === '0', 'Callback exception left a fixture row committed.');
    $summary[] = 'callback rollback: pass';

    $payload = "safe value'); DROP TABLE `{$table}`; --";
    $transactions->run(static fn (TransactionManager $tx): int => $tx->execute(
        "INSERT INTO `{$table}` (`value`) VALUES (%s)",
        [$payload]
    ));
    $stored = $wpdb->get_var($wpdb->prepare("SELECT `value` FROM `{$table}` WHERE `value` = %s", $payload));
    $assert($stored === $payload, 'Prepared value was not stored literally.');
    $assert($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table, 'Prepared value altered the fixture table.');
    $summary[] = 'prepared-values safety: pass';

    $transactions->run(static fn (TransactionManager $tx): int => $tx->execute(
        "INSERT INTO `{$table}` (`value`) VALUES (%s)",
        ['unique-duplicate']
    ));
    try {
        $transactions->run(static function (TransactionManager $tx) use ($table): void {
            $tx->execute("INSERT INTO `{$table}` (`value`) VALUES (%s)", ['must-rollback-after-sql-error']);
            $tx->execute("INSERT INTO `{$table}` (`value`) VALUES (%s)", ['unique-duplicate']);
        });
        throw new RuntimeException('Expected duplicate-key database error.');
    } catch (DatabaseException $failure) {
        $assert($failure->getCode() === 1062, 'SQL error did not expose the expected numeric error code.');
    }
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'must-rollback-after-sql-error'") === '0', 'SQL failure did not roll back prior DML.');
    $summary[] = 'SQL-error rollback (1062): pass';

    $swallowedCallbackReturned = false;
    try {
        $transactions->run(static function (TransactionManager $tx) use ($table, &$swallowedCallbackReturned): string {
            $tx->execute("INSERT INTO `{$table}` (`value`) VALUES (%s)", ['swallowed-error-prior-write']);
            try {
                $tx->execute("INSERT INTO `{$table}` (`value`) VALUES (%s)", ['unique-duplicate']);
            } catch (DatabaseException) {
                // Deliberately swallowed: run() must still abort the attempt.
            }
            try {
                $tx->execute("INSERT INTO `{$table}` (`value`) VALUES (%s)", ['swallowed-error-followup-write']);
            } catch (DatabaseException) {
                // The checked failure latch must prevent this statement reaching MySQL.
            }
            $swallowedCallbackReturned = true;
            return 'must not be committed';
        });
        throw new RuntimeException('Swallowed checked failure incorrectly allowed COMMIT.');
    } catch (DatabaseException $failure) {
        $assert($failure->getCode() === 1062, 'Swallowed duplicate failure did not surface as code 1062.');
    }

    $assert($swallowedCallbackReturned, 'Callback did not reach its deliberate swallowed-error return path.');
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'swallowed-error-prior-write'") === '0', 'Swallowed duplicate failure committed earlier DML.');
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'swallowed-error-followup-write'") === '0', 'Execute after a latched failure reached the database.');
    $summary[] = 'real swallowed duplicate: prior DML rolled back and subsequent DML blocked: pass';

    $transactions->run(static fn (TransactionManager $tx): int => $tx->execute(
        "INSERT INTO `{$table}` (`value`) VALUES (%s)",
        ['after-swallowed-error-valid']
    ));
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'after-swallowed-error-valid'") === '1', 'A later valid transaction did not commit cleanly.');
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` IN ('swallowed-error-prior-write', 'swallowed-error-followup-write')") === '0', 'A later valid transaction exposed failed-attempt DML.');
    $summary[] = 'subsequent valid transaction: committed without exposing failed-attempt DML: pass';

    $hostParts = explode(':', DB_HOST, 2);
    $lockHost = $hostParts[0];
    $lockPort = isset($hostParts[1]) && ctype_digit($hostParts[1]) ? (int) $hostParts[1] : 3306;
    $lockConnection = new mysqli($lockHost, DB_USER, DB_PASSWORD, DB_NAME, $lockPort);
    $assert($lockConnection->connect_errno === 0, 'Could not open separate lock-test connection.');

    // Probe documented START semantics only on our disposable session/fixture.
    // This checks fake parity, not a server-induced COMMIT failure.
    $assert($lockConnection->begin_transaction(), 'Could not begin implicit-commit parity probe.');
    $assert($lockConnection->query("INSERT INTO `{$table}` (`value`) VALUES ('implicit-start-parity')") !== false, 'Could not write parity fixture.');
    $assert($lockConnection->query('START TRANSACTION') !== false, 'Could not start second parity transaction.');
    $assert($lockConnection->rollback(), 'Could not end parity transaction.');
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'implicit-start-parity'") === '1', 'MySQL START implicit-commit semantics did not match the stateful fake.');
    $summary[] = 'real START implicit-commit semantics: stateful-fake parity only: pass';

    $transactions->run(static fn (TransactionManager $tx): int => $tx->execute(
        "INSERT INTO `{$table}` (`value`) VALUES (%s)",
        ['locked-row']
    ));
    $previousLockWait = (int) $scalar('SELECT @@SESSION.innodb_lock_wait_timeout');
    $assert($wpdb->query('SET SESSION innodb_lock_wait_timeout = 1') !== false, 'Could not bound lock wait for smoke test.');
    $assert($lockConnection->begin_transaction(), 'Could not begin blocker transaction.');
    $lockedResult = $lockConnection->query("SELECT `id` FROM `{$table}` WHERE `value` = 'locked-row' FOR UPDATE");
    $assert($lockedResult instanceof mysqli_result && $lockedResult->num_rows === 1, 'Separate connection did not acquire the fixture row lock.');
    if ($lockedResult instanceof mysqli_result) {
        $lockedResult->free();
    }

    $callbackAttempts = 0;
    try {
        $transactions->run(static function (TransactionManager $tx) use (&$callbackAttempts, $table): int {
            ++$callbackAttempts;
            return $tx->execute("UPDATE `{$table}` SET `value` = %s WHERE `value` = %s", ['lock-retry-succeeded', 'locked-row']);
        }, 2);
        throw new RuntimeException('Expected bounded lock timeout while the separate fixture blocker held its row lock.');
    } catch (DatabaseException $failure) {
        $assert($failure->getCode() === 1205, 'Real lock contention did not surface as MySQL lock timeout 1205.');
    }

    $assert($callbackAttempts === 2, 'Real lock timeout did not retry exactly once within maxAttempts=2.');
    $assert($scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'locked-row'") === '1', 'Failed bounded retries changed the locked fixture row.');
    $summary[] = 'real InnoDB lock timeout + bounded retry exhaustion + unchanged row: pass';

    $lossTestDb = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $connectionProperty = new ReflectionProperty(wpdb::class, 'dbh');
    $lossHandle = $connectionProperty->getValue($lossTestDb);
    $controlHandle = $connectionProperty->getValue($wpdb);
    $assert($lossHandle instanceof mysqli && $controlHandle instanceof mysqli, 'Expected real mysqli handles for connection-loss probe.');
    $lossThreadId = mysqli_thread_id($lossHandle);
    $controlThreadId = mysqli_thread_id($controlHandle);
    $assert($lossThreadId > 0 && $controlThreadId > 0 && $lossThreadId !== $controlThreadId, 'Refusing to kill a non-distinct or invalid connection ID.');

    $lossTransactions = new TransactionManager($lossTestDb);
    $connectionFailureObserved = false;
    try {
        $lossTransactions->run(static function (TransactionManager $tx) use ($controlHandle, $lossThreadId, $table): void {
            // The control session targets only this separately created test wpdb session.
            if (mysqli_query($controlHandle, 'KILL CONNECTION ' . $lossThreadId) === false) {
                throw new RuntimeException('Local DB account cannot kill its own disposable test session.');
            }
            $tx->execute("INSERT INTO `{$table}` (`value`) VALUES ('orphan-after-connection-loss')");
        });
    } catch (DatabaseException) {
        $connectionFailureObserved = true;
    }

    $orphanCount = $scalar("SELECT COUNT(*) FROM `{$table}` WHERE `value` = 'orphan-after-connection-loss'");
    $assert($connectionFailureObserved, 'Killed test connection was not reported as a transaction failure.');
    $assert($orphanCount === '0', 'Connection-loss probe left orphaned DML committed.');
    $summary[] = 'killed disposable wpdb connection: failure surfaced, no orphan DML: pass';
} catch (Throwable $failure) {
    $exitCode = 1;
    fwrite(STDERR, 'Transaction smoke failed (' . get_class($failure) . ', code ' . $failure->getCode() . '). ' . $failure->getMessage() . "\n");
} finally {
    if ($lockConnection instanceof mysqli) {
        // Rollback is harmless if the retry hook already committed and closed its transaction.
        $lockConnection->rollback();
        $lockConnection->close();
    }

    if ($lossTestDb instanceof wpdb) {
        $lossTestDb->close();
    }

    if ($previousLockWait !== null && isset($wpdb) && $wpdb->prefix === 'paxg_') {
        $wpdb->query('SET SESSION innodb_lock_wait_timeout = ' . max(1, $previousLockWait));
    }

    if ($created && preg_match('/\Apaxg_erp_test_tx_[a-f0-9]{16}\z/D', $table)) {
        if ($wpdb->query("DROP TABLE `{$table}`") === false) {
            $exitCode = 1;
            fwrite(STDERR, "Could not remove the allowlisted transaction fixture table.\n");
        }
    }
}

if ($exitCode === 0) {
    fwrite(STDOUT, "Transaction smoke passed; isolated fixture was removed.\n - " . implode("\n - ", $summary) . "\n");
}
exit($exitCode);
