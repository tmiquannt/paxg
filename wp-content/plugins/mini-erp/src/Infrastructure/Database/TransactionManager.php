<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Database;

use InvalidArgumentException;
use LogicException;
use ReflectionProperty;
use Throwable;
use WeakMap;
use wpdb;

/** @internal Test seam for deterministic transaction unit tests; production uses mysqli. */
interface TransactionConnection
{
    public function execute(string $sql): int|bool;

    /** @return list<array<string,mixed>> */
    public function fetchAll(string $sql): array;

    public function errorCode(): int;

    public function insertId(): int;
}

/** Checked transaction boundary pinned to the active WordPress mysqli handle. */
final class TransactionManager
{
    private const TRANSIENT_ERROR_CODES = [1205, 1213];

    /** @var WeakMap<wpdb, bool>|null */
    private static ?WeakMap $activeConnections = null;

    /** @var WeakMap<object, bool>|null Terminal handles remain quarantined across managers. */
    private static ?WeakMap $quarantinedConnections = null;

    private readonly ReflectionProperty $connectionProperty;
    private ?object $activeConnection = null;
    private ?Throwable $attemptFailure = null;

    public function __construct(private readonly wpdb $db)
    {
        try {
            $this->connectionProperty = new ReflectionProperty($db, 'dbh');
        } catch (Throwable) {
            throw new LogicException('Unable to inspect the WordPress database connection.');
        }
    }

    /** Run callback work in one transaction, retrying only confirmed rolled-back lock conflicts. */
    public function run(callable $work, int $maxAttempts = 3): mixed
    {
        if ($maxAttempts < 1 || $maxAttempts > 3) {
            throw new InvalidArgumentException('Transaction attempts must be between one and three.');
        }

        self::$activeConnections ??= new WeakMap();
        self::$quarantinedConnections ??= new WeakMap();
        if (isset(self::$activeConnections[$this->db])) {
            throw new LogicException('Nested transactions are not supported.');
        }

        $connection = $this->connection();
        if (!$this->isSupportedConnection($connection) || $this->isQuarantined($connection)) {
            throw new DatabaseException(2006);
        }

        $wasSuppressingErrors = $this->db->suppress_errors(true);
        self::$activeConnections[$this->db] = true;
        $this->activeConnection = $connection;

        try {
            for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
                $this->attemptFailure = null;
                try {
                    $this->assertCurrentConnection($connection);
                    if ($this->isQuarantined($connection)) {
                        throw new DatabaseException(2006);
                    }
                    $this->query('START TRANSACTION', $connection);
                } catch (Throwable $beginFailure) {
                    // A failed BEGIN can leave transaction state uncertain; never reuse it.
                    $this->rollbackBestEffort($connection);
                    $this->quarantineAfterIdentityFailure($connection);
                    throw $this->asDatabaseException($beginFailure);
                }

                try {
                    $result = $work($this);
                    if ($this->attemptFailure !== null) {
                        throw $this->attemptFailure;
                    }
                } catch (Throwable $workFailure) {
                    $workFailure = $this->attemptFailure ?? $workFailure;
                    $rollbackFailure = $this->rollbackBestEffort($connection);
                    if ($rollbackFailure !== null) {
                        $this->quarantineAfterIdentityFailure($connection);
                        throw $this->asDatabaseException($rollbackFailure);
                    }

                    if ($workFailure instanceof DatabaseException
                        && in_array($workFailure->getCode(), self::TRANSIENT_ERROR_CODES, true)
                        && $attempt < $maxAttempts
                        && $this->connection() === $connection
                        && !$this->isQuarantined($connection)
                    ) {
                        continue;
                    }

                    if ($this->isConnectionFailure($workFailure)) {
                        $this->quarantineAfterIdentityFailure($connection);
                    }
                    throw $workFailure;
                }

                try {
                    $this->assertCurrentConnection($connection);
                } catch (Throwable $identityFailure) {
                    $rollbackFailure = $this->rollbackBestEffort($connection);
                    $this->quarantineAfterIdentityFailure($connection);
                    throw $this->asDatabaseException($rollbackFailure ?? $identityFailure);
                }

                try {
                    $this->query('COMMIT', $connection);
                } catch (Throwable $commitFailure) {
                    // COMMIT outcome is ambiguous. Cleanup is best effort only and never
                    // means the commit was undone; this handle is terminally quarantined.
                    $rollbackFailure = $this->rollbackBestEffort($connection);
                    $this->quarantineAfterIdentityFailure($connection);
                    throw $this->asDatabaseException($rollbackFailure ?? $commitFailure);
                }

                return $result;
            }
        } finally {
            $this->attemptFailure = null;
            $this->activeConnection = null;
            unset(self::$activeConnections[$this->db]);
            $this->db->suppress_errors($wasSuppressingErrors);
        }

        throw new LogicException('Transaction retry limit exhausted unexpectedly.');
    }

    /** Execute trusted SQL inside run(); zero affected rows is a successful result. */
    public function execute(string $sql, array $args = []): int
    {
        $connection = $this->activeConnection;
        if ($connection === null || !isset(self::$activeConnections[$this->db])) {
            throw new LogicException('Database statements must run inside a transaction.');
        }
        if ($this->attemptFailure !== null) {
            throw $this->attemptFailure;
        }

        try {
            if (!preg_match('/\A\s*(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|WITH)\b/i', $sql)) {
                throw new LogicException('Only read and DML statements are allowed in transaction callbacks.');
            }

            $this->assertCurrentConnection($connection);
            if ($args !== []) {
                try {
                    $sql = $this->db->prepare($sql, $args);
                } catch (Throwable $failure) {
                    $this->assertCurrentConnection($connection);
                    throw new DatabaseException($this->exceptionCode($failure));
                }
                $this->assertCurrentConnection($connection);
                if (!is_string($sql) || $sql === '') {
                    throw new DatabaseException();
                }
            }

            return $this->query($sql, $connection);
        } catch (Throwable $failure) {
            $this->attemptFailure = $failure;
            throw $failure;
        }
    }

    /** Return the auto-increment ID from the last insert on this pinned connection. */
    public function insertId(): int
    {
        $connection = $this->activeConnection;
        if ($connection === null || !isset(self::$activeConnections[$this->db])) {
            throw new LogicException('Insert IDs are available only inside a transaction.');
        }
        if ($this->attemptFailure !== null) {
            throw $this->attemptFailure;
        }

        $this->assertCurrentConnection($connection);
        $id = $connection instanceof \mysqli
            ? mysqli_insert_id($connection)
            : $connection->insertId();
        $this->assertCurrentConnection($connection);
        if ($id < 1) {
            throw new LogicException('The transaction has no successful auto-increment insert.');
        }
        return $id;
    }

    /** Execute a read-only SELECT on the same pinned connection as the active transaction. @return list<array<string,mixed>> */
    public function fetchAll(string $sql, array $args = []): array
    {
        $connection = $this->activeConnection;
        if ($connection === null || !isset(self::$activeConnections[$this->db])) {
            throw new LogicException('Database reads must run inside a transaction.');
        }
        if ($this->attemptFailure !== null) throw $this->attemptFailure;
        try {
            if (!preg_match('/\A\s*SELECT\b/i', $sql)) throw new LogicException('Only SELECT statements are allowed in transaction reads.');
            $this->assertCurrentConnection($connection);
            if ($args !== []) {
                try { $sql = $this->db->prepare($sql, $args); }
                catch (Throwable $failure) { $this->assertCurrentConnection($connection); throw new DatabaseException($this->exceptionCode($failure)); }
                $this->assertCurrentConnection($connection);
                if (!is_string($sql) || $sql === '') throw new DatabaseException();
            }
            $rows = $this->fetchRowsOnConnection($connection, $sql);
            $this->assertCurrentConnection($connection);
            return $rows;
        } catch (Throwable $failure) {
            $this->attemptFailure = $failure;
            throw $failure;
        }
    }

    private function query(string $sql, object $connection): int
    {
        $this->assertCurrentConnection($connection);
        try {
            $result = $this->executeOnConnection($connection, $sql);
        } catch (Throwable $failure) {
            $this->assertCurrentConnection($connection);
            throw $failure;
        }
        $this->assertCurrentConnection($connection);
        return $result;
    }

    private function rollbackBestEffort(object $connection): ?Throwable
    {
        try {
            $this->executeOnConnection($connection, 'ROLLBACK');
            return null;
        } catch (Throwable $failure) {
            return $failure;
        }
    }

    private function quarantineAfterIdentityFailure(object $pinnedConnection): void
    {
        self::$quarantinedConnections ??= new WeakMap();
        self::$quarantinedConnections[$pinnedConnection] = true;

        $currentConnection = $this->connection();
        if (is_object($currentConnection) && $currentConnection !== $pinnedConnection) {
            self::$quarantinedConnections[$currentConnection] = true;
        }
    }

    private function isQuarantined(object $connection): bool
    {
        self::$quarantinedConnections ??= new WeakMap();
        return isset(self::$quarantinedConnections[$connection]);
    }

    private function assertCurrentConnection(object $pinnedConnection): void
    {
        if ($this->connection() !== $pinnedConnection) {
            throw new DatabaseException(2006);
        }
    }

    private function connection(): mixed
    {
        try {
            return $this->connectionProperty->getValue($this->db);
        } catch (Throwable) {
            return null;
        }
    }

    private function isSupportedConnection(mixed $connection): bool
    {
        return $connection instanceof \mysqli || $connection instanceof TransactionConnection;
    }

    private function executeOnConnection(object $connection, string $sql): int
    {
        if ($connection instanceof \mysqli) {
            try {
                $result = mysqli_query($connection, $sql);
            } catch (Throwable $failure) {
                $code = $this->exceptionCode($failure);
                throw new DatabaseException($code !== 0 ? $code : $this->connectionErrorCode($connection));
            }

            if ($result === false) {
                throw new DatabaseException($this->connectionErrorCode($connection));
            }

            if ($result instanceof \mysqli_result) {
                $rowCount = $result->num_rows;
                $result->free();
                return $rowCount;
            }

            return max(0, mysqli_affected_rows($connection));
        }

        if ($connection instanceof TransactionConnection) {
            try {
                $result = $connection->execute($sql);
            } catch (Throwable $failure) {
                $code = $this->exceptionCode($failure);
                throw new DatabaseException($code !== 0 ? $code : $connection->errorCode());
            }

            if ($result === false) {
                throw new DatabaseException($connection->errorCode());
            }

            return is_int($result) ? $result : 1;
        }

        throw new DatabaseException(2006);
    }

    /** @return list<array<string,mixed>> */
    private function fetchRowsOnConnection(object $connection, string $sql): array
    {
        $this->assertCurrentConnection($connection);
        if ($connection instanceof \mysqli) {
            try { $result = mysqli_query($connection, $sql); }
            catch (Throwable $failure) {
                $code = $this->exceptionCode($failure);
                throw new DatabaseException($code !== 0 ? $code : $this->connectionErrorCode($connection));
            }
            if (!$result instanceof \mysqli_result) throw new DatabaseException($this->connectionErrorCode($connection));
            $rows = [];
            while (($row = $result->fetch_assoc()) !== null) $rows[] = $row;
            $result->free();
            return $rows;
        }
        if ($connection instanceof TransactionConnection) {
            try { return $connection->fetchAll($sql); }
            catch (Throwable $failure) {
                $code = $this->exceptionCode($failure);
                throw new DatabaseException($code !== 0 ? $code : $connection->errorCode());
            }
        }
        throw new DatabaseException(2006);
    }

    private function connectionErrorCode(object $connection): int
    {
        if ($connection instanceof \mysqli) {
            return mysqli_errno($connection);
        }

        return $connection instanceof TransactionConnection ? $connection->errorCode() : 0;
    }

    private function isConnectionFailure(Throwable $failure): bool
    {
        return $failure instanceof DatabaseException && in_array($failure->getCode(), [2002, 2006, 2013], true);
    }

    private function asDatabaseException(Throwable $failure): DatabaseException
    {
        return $failure instanceof DatabaseException
            ? $failure
            : new DatabaseException($this->exceptionCode($failure));
    }

    private function exceptionCode(Throwable $failure): int
    {
        return max(0, (int) $failure->getCode());
    }
}
