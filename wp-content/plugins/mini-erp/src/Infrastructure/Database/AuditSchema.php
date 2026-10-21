<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use ReflectionProperty;
use RuntimeException;
use Throwable;
use wpdb;

/** Installs the append-only audit table independently from the five master tables. */
final class AuditSchema
{
    private readonly string $table;
    private readonly string $database;
    private readonly ReflectionProperty $connectionProperty;
    private ?\mysqli $connection = null;

    public function __construct(private readonly wpdb $db)
    {
        $prefix = $db->prefix;
        if (!is_string($prefix) || strlen($prefix) > 32 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)) {
            throw new RuntimeException('The WordPress table prefix is not supported.');
        }
        if (!is_string($db->dbname) || $db->dbname === '') {
            throw new RuntimeException('The database name is unavailable.');
        }
        $this->table = $prefix . 'erp_audit_logs';
        $this->database = $db->dbname;
        try {
            $this->connectionProperty = new ReflectionProperty($db, 'dbh');
        } catch (Throwable) {
            throw new RuntimeException('The WordPress database connection is unavailable.');
        }
    }

    public function migrate(): void
    {
        $connection = $this->connectionProperty->getValue($this->db);
        if (!$connection instanceof \mysqli) {
            throw new RuntimeException('Audit schema migration requires a live MySQL connection.');
        }
        $this->connection = $connection;
        $lockName = 'mini-erp-audit:' . substr(hash('sha256', $this->database . ':' . $this->table), 0, 40);
        if ((string) $this->scalar('SELECT GET_LOCK(?, 5)', [$lockName]) !== '1') {
            $this->connection = null;
            throw new RuntimeException('Could not acquire the bounded audit schema lock.');
        }

        $migrationFailure = null;
        try {
            if (!$this->tableExists()) {
                $this->query($this->createSql());
            }
            $this->assertCompatible();
        } catch (Throwable $failure) {
            $migrationFailure = $failure;
        }

        $releaseFailure = null;
        try {
            $released = $this->scalar('SELECT RELEASE_LOCK(?)', [$lockName]);
            if ((string) $released !== '1') {
                $releaseFailure = new RuntimeException('The audit schema lock could not be released cleanly.');
            }
        } catch (Throwable $failure) {
            $releaseFailure = $failure;
        } finally {
            $this->connection = null;
        }
        if ($migrationFailure !== null) {
            throw $migrationFailure;
        }
        if ($releaseFailure !== null) {
            throw new RuntimeException('The audit schema lock could not be released cleanly.', 0, $releaseFailure);
        }
    }

    private function createSql(): string
    {
        $suffix = substr(hash('sha256', $this->database . ':' . $this->table), 0, 10);
        return 'CREATE TABLE ' . $this->identifier($this->table) . ' ('
            . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`event_id` CHAR(36) NOT NULL, `request_id` CHAR(36) NOT NULL, '
            . '`actor_user_id` BIGINT UNSIGNED NOT NULL, `action` VARCHAR(96) NOT NULL, '
            . '`entity_type` VARCHAR(64) NOT NULL, `entity_id` BIGINT UNSIGNED NULL, '
            . '`result` VARCHAR(16) NOT NULL, `before_json` JSON NULL, `after_json` JSON NULL, '
            . '`ip_hash` CHAR(64) NULL, `created_at` DATETIME(6) NOT NULL, '
            . 'UNIQUE KEY `event_id` (`event_id`), KEY `actor_created` (`actor_user_id`, `created_at`), '
            . 'KEY `entity_created` (`entity_type`, `entity_id`, `created_at`), '
            . 'CONSTRAINT `ck_' . $suffix . '_result` CHECK (`result` IN (\'success\', \'denied\', \'failure\'))'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function tableExists(): bool
    {
        return (string) $this->scalar(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, $this->table]
        ) === '1';
    }

    private function assertCompatible(): void
    {
        $table = $this->row(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, $this->table]
        );
        if ($table === null || strtoupper((string) $table['ENGINE']) !== 'INNODB'
            || !str_starts_with(strtolower((string) $table['TABLE_COLLATION']), 'utf8mb4_')
        ) {
            throw new RuntimeException('An existing audit table is incompatible; it was left unchanged.');
        }

        $expected = [
            'id' => 'bigint unsigned', 'event_id' => 'char(36)', 'request_id' => 'char(36)',
            'actor_user_id' => 'bigint unsigned', 'action' => 'varchar(96)', 'entity_type' => 'varchar(64)',
            'entity_id' => 'bigint unsigned', 'result' => 'varchar(16)', 'before_json' => 'json',
            'after_json' => 'json', 'ip_hash' => 'char(64)', 'created_at' => 'datetime(6)',
        ];
        $columns = $this->rows(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->database, $this->table]
        );
        $actual = [];
        foreach ($columns as $column) {
            $actual[(string) $column['COLUMN_NAME']] = strtolower((string) $column['COLUMN_TYPE']);
        }
        foreach ($expected as $name => $type) {
            if (($actual[$name] ?? null) !== $type) {
                throw new RuntimeException('An existing audit table is incompatible; it was left unchanged.');
            }
        }
        if (count($actual) !== count($expected)) {
            throw new RuntimeException('An existing audit table has unexpected columns; it was left unchanged.');
        }

        $indexes = $this->rows(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->database, $this->table]
        );
        $actualIndexes = [];
        $actualUnique = [];
        foreach ($indexes as $index) {
            $actualIndexes[(string) $index['INDEX_NAME']][] = (string) $index['COLUMN_NAME'];
            $actualUnique[(string) $index['INDEX_NAME']] = (int) $index['NON_UNIQUE'] === 0;
        }
        $expectedIndexes = [
            'PRIMARY' => ['id'], 'event_id' => ['event_id'],
            'actor_created' => ['actor_user_id', 'created_at'],
            'entity_created' => ['entity_type', 'entity_id', 'created_at'],
        ];
        foreach ($expectedIndexes as $name => $columns) {
            if (($actualIndexes[$name] ?? null) !== $columns) {
                throw new RuntimeException('An existing audit table has incompatible indexes; it was left unchanged.');
            }
        }
        if (count($actualIndexes) !== count($expectedIndexes) || ($actualUnique['event_id'] ?? false) !== true
            || ($actualUnique['PRIMARY'] ?? false) !== true
        ) {
            throw new RuntimeException('An existing audit table has incompatible indexes; it was left unchanged.');
        }

        $checks = $this->rows(
            'SELECT tc.CONSTRAINT_TYPE, cc.CHECK_CLAUSE FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ?',
            [$this->database, $this->table]
        );
        $hasResultCheck = false;
        foreach ($checks as $check) {
            $clause = strtolower((string) $check['CHECK_CLAUSE']);
            $hasResultCheck = $hasResultCheck || (strtoupper((string) $check['CONSTRAINT_TYPE']) === 'CHECK'
                && str_contains($clause, 'result') && str_contains($clause, 'success')
                && str_contains($clause, 'denied') && str_contains($clause, 'failure'));
        }
        if (!$hasResultCheck) {
            throw new RuntimeException('The audit result constraint is missing; the existing table was left unchanged.');
        }
    }

    private function identifier(string $value): string
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /** @param list<mixed> $values @return list<array<string, mixed>> */
    private function rows(string $sql, array $values): array
    {
        $result = $this->run($sql, $values);
        if (!$result instanceof \mysqli_result) {
            throw new RuntimeException('Could not inspect audit schema metadata.');
        }
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    /** @param list<mixed> $values @return array<string, mixed>|null */
    private function row(string $sql, array $values): ?array
    {
        return $this->rows($sql, $values)[0] ?? null;
    }

    /** @param list<mixed> $values */
    private function scalar(string $sql, array $values = []): mixed
    {
        $result = $this->run($sql, $values);
        if (!$result instanceof \mysqli_result) {
            throw new RuntimeException('A required audit schema operation failed.');
        }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? null;
    }

    /** @param list<mixed> $values */
    private function query(string $sql, array $values = []): void
    {
        $result = $this->run($sql, $values);
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
    }

    /** @param list<mixed> $values */
    private function run(string $sql, array $values = []): \mysqli_result|bool
    {
        $this->assertConnection();
        try {
            if ($values === []) {
                $result = mysqli_query($this->connection, $sql);
            } else {
                $statement = $this->connection->prepare($sql);
                if (!$statement) {
                    throw new RuntimeException('Could not prepare an audit schema query.');
                }
                $types = str_repeat('s', count($values));
                $arguments = [$types];
                foreach ($values as $index => &$value) {
                    $arguments[] = &$value;
                }
                unset($value);
                if (!call_user_func_array([$statement, 'bind_param'], $arguments)) {
                    $statement->close();
                    throw new RuntimeException('Could not bind an audit schema query.');
                }
                if (!$statement->execute()) {
                    $statement->close();
                    throw new RuntimeException('An audit schema operation failed.');
                }
                $result = $statement->get_result();
                $statement->close();
            }
        } catch (Throwable) {
            $this->assertConnection();
            throw new RuntimeException('An audit schema operation failed.');
        }
        $this->assertConnection();
        if ($result === false) {
            throw new RuntimeException('An audit schema operation failed.');
        }
        return $result;
    }

    private function assertConnection(): void
    {
        if ($this->connection === null || $this->connectionProperty->getValue($this->db) !== $this->connection) {
            throw new RuntimeException('The database connection changed during audit schema migration.');
        }
    }
}
