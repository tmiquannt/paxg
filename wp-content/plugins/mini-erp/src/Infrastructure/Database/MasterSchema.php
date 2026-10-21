<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use RuntimeException;
use ReflectionProperty;
use Throwable;
use wpdb;

/** Installs and verifies the first five Mini ERP master tables. */
final class MasterSchema
{
    private const SUFFIXES = [
        'categories' => 'erp_categories',
        'products' => 'erp_products',
        'customers' => 'erp_customers',
        'suppliers' => 'erp_suppliers',
        'warehouses' => 'erp_warehouses',
    ];

    private readonly string $prefix;
    private readonly string $database;
    private readonly ReflectionProperty $connectionProperty;
    private ?object $connection = null;

    public function __construct(private readonly wpdb $db)
    {
        $prefix = $db->prefix;
        if (!is_string($prefix) || strlen($prefix) > 32 || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix)) {
            throw new RuntimeException('The WordPress table prefix is not supported.');
        }
        if (!is_string($db->dbname) || $db->dbname === '') {
            throw new RuntimeException('The database name is unavailable.');
        }

        $this->prefix = $prefix;
        $this->database = $db->dbname;
        try {
            $this->connectionProperty = new ReflectionProperty($db, 'dbh');
        } catch (Throwable) {
            throw new RuntimeException('The WordPress database connection is unavailable.');
        }
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        return array_map(fn (string $suffix): string => $this->prefix . $suffix, array_values(self::SUFFIXES));
    }

    /** Create missing compatible tables and reject incompatible existing tables without changing them. */
    public function migrate(): void
    {
        $connection = $this->connectionProperty->getValue($this->db);
        if (!$connection instanceof \mysqli) {
            throw new RuntimeException('Master schema migration requires a live MySQL connection.');
        }
        $this->connection = $connection;
        $this->assertSupportedServer();
        $lockName = 'mini-erp-migrate:' . substr(hash('sha256', $this->database . ':' . $this->prefix), 0, 40);
        $lockResult = $this->scalar($this->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
        if ((string) $lockResult !== '1') {
            $this->connection = null;
            throw new RuntimeException('Could not acquire the bounded schema migration lock.');
        }

        try {
            foreach ($this->definitions() as $key => $definition) {
                $table = $this->prefix . self::SUFFIXES[$key];
                if ($this->tableExists($table)) {
                    $this->assertCompatible($table, $definition);
                    continue;
                }

                $this->query($this->createSql($table, $definition));
                $this->assertCompatible($table, $definition);
            }
        } finally {
            $released = $this->scalar($this->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $this->connection = null;
            if ((string) $released !== '1') {
                throw new RuntimeException('The schema migration lock could not be released cleanly.');
            }
        }
    }

    private function assertSupportedServer(): void
    {
        $version = $this->scalar('SELECT VERSION()');
        if (stripos($version, 'mariadb') !== false
            || !preg_match('/\A(\d+)\.(\d+)\.(\d+)/', $version, $matches)
            || ((int) $matches[1] === 8 && (int) $matches[2] === 0 && (int) $matches[3] < 16)
            || (int) $matches[1] < 8
        ) {
            throw new RuntimeException('Master schema requires MySQL 8.0.16 or later with enforced CHECK constraints.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function definitions(): array
    {
        $common = [
            'id' => ['type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null, 'extra' => 'auto_increment'],
            'is_active' => ['type' => 'tinyint', 'nullable' => 'NO', 'default' => '1', 'extra' => ''],
            'created_at' => ['type' => 'datetime(6)', 'nullable' => 'NO', 'default' => 'current_timestamp(6)', 'extra' => ''],
            'updated_at' => ['type' => 'datetime(6)', 'nullable' => 'NO', 'default' => 'current_timestamp(6)', 'extra' => ''],
        ];

        return [
            'categories' => [
                'columns' => $common + [
                    'code' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'name' => ['type' => 'varchar(191)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                ],
                'indexes' => ['PRIMARY' => ['id'], 'code' => ['code']],
                'unique_indexes' => ['PRIMARY', 'code'],
                'checks' => ['is_active in (0,1)'],
                'foreign_keys' => [],
                'create_columns' => '`code` VARCHAR(64) NOT NULL, `name` VARCHAR(191) NOT NULL, UNIQUE KEY `code` (`code`)',
            ],
            'products' => [
                'columns' => $common + [
                    'sku' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'name' => ['type' => 'varchar(191)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'category_id' => ['type' => 'bigint unsigned', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'unit' => ['type' => 'varchar(32)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'unit_step' => ['type' => 'decimal(18,3)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'sale_price_vnd' => ['type' => 'bigint', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                ],
                'indexes' => ['PRIMARY' => ['id'], 'sku' => ['sku'], 'category_id' => ['category_id']],
                'unique_indexes' => ['PRIMARY', 'sku'],
                'checks' => ['is_active in (0,1)', 'unit_step>0', 'sale_price_vnd>=0'],
                'foreign_keys' => ['category_id' => ['table' => 'erp_categories', 'column' => 'id', 'delete' => 'RESTRICT', 'update' => 'RESTRICT']],
                'create_columns' => '`sku` VARCHAR(64) NOT NULL, `name` VARCHAR(191) NOT NULL, `category_id` BIGINT UNSIGNED NOT NULL, `unit` VARCHAR(32) NOT NULL, `unit_step` DECIMAL(18,3) NOT NULL, `sale_price_vnd` BIGINT NOT NULL, UNIQUE KEY `sku` (`sku`), KEY `category_id` (`category_id`)',
            ],
            'customers' => [
                'columns' => $common + [
                    'code' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'name' => ['type' => 'varchar(191)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'tax_id' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                    'phone' => ['type' => 'varchar(32)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                    'email' => ['type' => 'varchar(254)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                    'address' => ['type' => 'varchar(1000)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                ],
                'indexes' => ['PRIMARY' => ['id'], 'code' => ['code']],
                'unique_indexes' => ['PRIMARY', 'code'],
                'checks' => ['is_active in (0,1)'],
                'foreign_keys' => [],
                'create_columns' => "`code` VARCHAR(64) NOT NULL, `name` VARCHAR(191) NOT NULL, `tax_id` VARCHAR(64) NOT NULL DEFAULT '', `phone` VARCHAR(32) NOT NULL DEFAULT '', `email` VARCHAR(254) NOT NULL DEFAULT '', `address` VARCHAR(1000) NOT NULL DEFAULT '', UNIQUE KEY `code` (`code`)",
            ],
            'suppliers' => [
                'columns' => $common + [
                    'code' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'name' => ['type' => 'varchar(191)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'tax_id' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                    'phone' => ['type' => 'varchar(32)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                    'email' => ['type' => 'varchar(254)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                    'address' => ['type' => 'varchar(1000)', 'nullable' => 'NO', 'default' => '', 'extra' => ''],
                ],
                'indexes' => ['PRIMARY' => ['id'], 'code' => ['code']],
                'unique_indexes' => ['PRIMARY', 'code'],
                'checks' => ['is_active in (0,1)'],
                'foreign_keys' => [],
                'create_columns' => "`code` VARCHAR(64) NOT NULL, `name` VARCHAR(191) NOT NULL, `tax_id` VARCHAR(64) NOT NULL DEFAULT '', `phone` VARCHAR(32) NOT NULL DEFAULT '', `email` VARCHAR(254) NOT NULL DEFAULT '', `address` VARCHAR(1000) NOT NULL DEFAULT '', UNIQUE KEY `code` (`code`)",
            ],
            'warehouses' => [
                'columns' => $common + [
                    'code' => ['type' => 'varchar(64)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                    'name' => ['type' => 'varchar(191)', 'nullable' => 'NO', 'default' => null, 'extra' => ''],
                ],
                'indexes' => ['PRIMARY' => ['id'], 'code' => ['code']],
                'unique_indexes' => ['PRIMARY', 'code'],
                'checks' => ['is_active in (0,1)'],
                'foreign_keys' => [],
                'create_columns' => '`code` VARCHAR(64) NOT NULL, `name` VARCHAR(191) NOT NULL, UNIQUE KEY `code` (`code`)',
            ],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function createSql(string $table, array $definition): string
    {
        $hash = substr(hash('sha256', $this->database . ':' . $table), 0, 10);
        $checks = [
            'CONSTRAINT `ck_' . $hash . '_active` CHECK (`is_active` IN (0, 1))',
        ];
        if ($table === $this->prefix . self::SUFFIXES['products']) {
            $checks[] = 'CONSTRAINT `ck_' . $hash . '_step` CHECK (`unit_step` > 0)';
            $checks[] = 'CONSTRAINT `ck_' . $hash . '_price` CHECK (`sale_price_vnd` >= 0)';
            $foreignTable = $this->prefix . self::SUFFIXES['categories'];
            $checks[] = 'CONSTRAINT `fk_' . $hash . '_category` FOREIGN KEY (`category_id`) REFERENCES ' . $this->identifier($foreignTable) . ' (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT';
        }

        $base = '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . '`is_active` TINYINT NOT NULL DEFAULT 1, '
            . '`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . '`updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), '
            . $definition['create_columns'];

        return 'CREATE TABLE ' . $this->identifier($table) . ' (' . $base . ', ' . implode(', ', $checks) . ')'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    private function tableExists(string $table): bool
    {
        return (string) $this->scalar($this->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $this->database,
            $table
        )) === '1';
    }

    /** @param array<string, mixed> $definition */
    private function assertCompatible(string $table, array $definition): void
    {
        $tableRow = $this->row($this->prepare(
            'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $this->database,
            $table
        ));
        if ($tableRow === null || strtoupper((string) $tableRow['ENGINE']) !== 'INNODB'
            || !str_starts_with(strtolower((string) $tableRow['TABLE_COLLATION']), 'utf8mb4_')
        ) {
            throw new RuntimeException('An existing master table is incompatible; it was left unchanged.');
        }

        $columns = $this->rows($this->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            $this->database,
            $table
        ));
        $actualColumns = [];
        foreach ($columns as $column) {
            $actualColumns[(string) $column['COLUMN_NAME']] = $column;
        }
        foreach ($definition['columns'] as $name => $expected) {
            $actual = $actualColumns[$name] ?? null;
            if ($actual === null
                || strtolower((string) $actual['COLUMN_TYPE']) !== $expected['type']
                || strtoupper((string) $actual['IS_NULLABLE']) !== $expected['nullable']
                || !$this->sameDefault($actual['COLUMN_DEFAULT'], $expected['default'])
                || $this->normalizeExtra((string) $actual['EXTRA']) !== $expected['extra']
            ) {
                throw new RuntimeException('An existing master table is incompatible; it was left unchanged.');
            }
        }

        $indexRows = $this->rows($this->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            $this->database,
            $table
        ));
        $indexes = [];
        $unique = [];
        foreach ($indexRows as $indexRow) {
            $name = (string) $indexRow['INDEX_NAME'];
            $indexes[$name][] = (string) $indexRow['COLUMN_NAME'];
            if ((int) $indexRow['NON_UNIQUE'] === 0) {
                $unique[$name] = true;
            }
        }
        foreach ($definition['indexes'] as $name => $expectedColumns) {
            if (($indexes[$name] ?? null) !== $expectedColumns
                || (in_array($name, $definition['unique_indexes'], true) !== isset($unique[$name]))
            ) {
                throw new RuntimeException('An existing master table has incompatible indexes; it was left unchanged.');
            }
        }

        $checkRows = $this->rows($this->prepare(
            'SELECT cc.CHECK_CLAUSE, tc.ENFORCED FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = %s AND tc.TABLE_NAME = %s AND tc.CONSTRAINT_TYPE = \'CHECK\'',
            $this->database,
            $table
        ));
        $actualChecks = [];
        foreach ($checkRows as $checkRow) {
            if (strtoupper((string) $checkRow['ENFORCED']) === 'YES') {
                $actualChecks[] = $this->normalizeExpression((string) $checkRow['CHECK_CLAUSE']);
            }
        }
        foreach ($definition['checks'] as $requiredCheck) {
            $required = $this->normalizeExpression($requiredCheck);
            if (!in_array($required, $actualChecks, true)) {
                throw new RuntimeException('An existing master table is missing an enforced check; it was left unchanged.');
            }
        }
        if (count($actualChecks) !== count($definition['checks'])) {
            throw new RuntimeException('An existing master table has incompatible CHECK constraints; it was left unchanged.');
        }

        $foreignRows = $this->rows($this->prepare(
            'SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, rc.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE kcu JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME WHERE kcu.TABLE_SCHEMA = %s AND kcu.TABLE_NAME = %s AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            $this->database,
            $table
        ));
        foreach ($definition['foreign_keys'] as $column => $expected) {
            $match = false;
            foreach ($foreignRows as $foreignRow) {
                if ((string) $foreignRow['COLUMN_NAME'] === $column
                    && (string) $foreignRow['REFERENCED_TABLE_NAME'] === $this->prefix . $expected['table']
                    && (string) $foreignRow['REFERENCED_COLUMN_NAME'] === $expected['column']
                    && strtoupper((string) $foreignRow['DELETE_RULE']) === $expected['delete']
                    && strtoupper((string) $foreignRow['UPDATE_RULE']) === $expected['update']
                ) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                throw new RuntimeException('An existing master table has an incompatible foreign key; it was left unchanged.');
            }
        }
        if (count($foreignRows) !== count($definition['foreign_keys'])) {
            throw new RuntimeException('An existing master table has incompatible foreign keys; it was left unchanged.');
        }
    }

    private function sameDefault(mixed $actual, ?string $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }
        return strtolower(trim((string) $actual, " '\"")) === strtolower(trim($expected, " '\""));
    }

    private function normalizeExpression(string $expression): string
    {
        $normalized = strtolower(preg_replace('/[`\s]+/', '', $expression) ?? $expression);
        while (str_starts_with($normalized, '(') && str_ends_with($normalized, ')')) {
            $depth = 0;
            $wrapsWholeExpression = true;
            $length = strlen($normalized);
            for ($index = 0; $index < $length; ++$index) {
                if ($normalized[$index] === '(') {
                    ++$depth;
                } elseif ($normalized[$index] === ')') {
                    --$depth;
                    if ($depth === 0 && $index < $length - 1) {
                        $wrapsWholeExpression = false;
                        break;
                    }
                }
            }
            if (!$wrapsWholeExpression || $depth !== 0) {
                break;
            }
            $normalized = substr($normalized, 1, -1);
        }
        return $normalized;
    }

    private function normalizeExtra(string $extra): string
    {
        return strtolower(trim(preg_replace('/\bdefault_generated\b/i', '', $extra) ?? $extra));
    }

    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $result = $this->runQuery($sql);
        if (!$result instanceof \mysqli_result) {
            throw new RuntimeException('Could not inspect master schema metadata.');
        }
        $rows = [];
        while (($row = $result->fetch_assoc()) !== null) {
            $rows[] = $row;
        }
        $result->free();
        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function row(string $sql): ?array
    {
        $rows = $this->rows($sql);
        return $rows[0] ?? null;
    }

    private function scalar(string $sql): mixed
    {
        $result = $this->runQuery($sql);
        if (!$result instanceof \mysqli_result) {
            throw new RuntimeException('A required schema database operation failed.');
        }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? null;
    }

    private function query(string $sql): void
    {
        $result = $this->runQuery($sql);
        if ($result instanceof \mysqli_result) {
            $result->free();
        }
    }

    private function prepare(string $query, mixed ...$args): string
    {
        $this->assertPinnedConnection();
        try {
            $prepared = $this->db->prepare($query, $args);
        } catch (Throwable) {
            throw new RuntimeException('Could not prepare a master schema database query.');
        }
        $this->assertPinnedConnection();
        if (!is_string($prepared) || $prepared === '') {
            throw new RuntimeException('Could not prepare a master schema database query.');
        }
        return $prepared;
    }

    private function runQuery(string $sql): \mysqli_result|bool
    {
        $this->assertPinnedConnection();
        try {
            $result = mysqli_query($this->connection, $sql);
        } catch (Throwable $failure) {
            $code = filter_var($failure->getCode(), FILTER_VALIDATE_INT);
            throw new RuntimeException('A master schema operation failed (database error ' . ($code === false ? 0 : $code) . ').');
        }
        $this->assertPinnedConnection();
        if ($result === false) {
            throw new RuntimeException('A master schema operation failed (database error ' . mysqli_errno($this->connection) . ').');
        }
        return $result;
    }

    private function assertPinnedConnection(): void
    {
        if ($this->connection === null || $this->connectionProperty->getValue($this->db) !== $this->connection) {
            throw new RuntimeException('The database connection changed during schema migration.');
        }
    }
}
