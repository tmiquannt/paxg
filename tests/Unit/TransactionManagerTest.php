<?php
declare(strict_types=1);

use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-content/plugins/mini-erp/src/Infrastructure/Database/TransactionManager.php';

/** Minimal wpdb double covering only the public surface used by this adapter. */
final class TransactionManagerFakeConnection implements \MiniERP\Infrastructure\Database\TransactionConnection
{
    public int $errno = 0;
    public int $lastInsertId = 0;
    public bool $inTransaction = false;
    public bool $commitBeforeFailure = false;
    /** @var list<string> */
    public array $pendingRows = [];
    /** @var list<string> */
    public array $durableRows = [];

    public function __construct(private readonly wpdb $owner)
    {
    }

    public function execute(string $sql): int|bool
    {
        return $this->owner->recordQuery($this, $sql);
    }

    public function fetchAll(string $sql): array
    {
        return $this->owner->recordFetch($this, $sql);
    }

    public function errorCode(): int
    {
        return $this->errno;
    }

    public function insertId(): int
    {
        return $this->lastInsertId;
    }
}

class wpdb
{
    public object $dbh;
    public bool $suppress_errors = false;
    public bool $show_errors = true;
    public string $last_error = '';
    public int $rows_affected = 0;
    /** @var list<string> */
    public array $queries = [];
    /** @var list<array{connection: TransactionManagerFakeConnection, sql: string}> */
    public array $queryLog = [];
    /** @var null|callable(string, self, TransactionManagerFakeConnection): (int|bool) */
    public $queryHandler = null;
    /** @var null|callable(string, self, TransactionManagerFakeConnection): list<array<string,mixed>> */
    public $fetchHandler = null;
    /** @var list<array{string, array}> */
    public array $prepared = [];

    public function __construct()
    {
        $this->dbh = $this->newConnection();
    }

    public function newConnection(): TransactionManagerFakeConnection
    {
        return new TransactionManagerFakeConnection($this);
    }

    public function prepare(string $query, array $args): string
    {
        $this->prepared[] = [$query, $args];
        return $query . ' /* prepared */';
    }

    public function query(string $query): int|bool
    {
        return $this->dbh->execute($query);
    }

    public function recordQuery(TransactionManagerFakeConnection $connection, string $query): int|bool
    {
        $this->queries[] = $query;
        $this->queryLog[] = ['connection' => $connection, 'sql' => $query];
        $this->last_error = '';
        $result = $this->queryHandler === null ? 1 : ($this->queryHandler)($query, $this, $connection);
        $successful = $result !== false;
        if ($query === 'START TRANSACTION' && $successful) {
            // MySQL implicitly commits a pending transaction before starting another.
            if ($connection->inTransaction) {
                array_push($connection->durableRows, ...$connection->pendingRows);
            }
            $connection->inTransaction = true;
            $connection->pendingRows = [];
        } elseif ($query === 'COMMIT' && ($successful || $connection->commitBeforeFailure)) {
            array_push($connection->durableRows, ...$connection->pendingRows);
            $connection->pendingRows = [];
            $connection->inTransaction = false;
        } elseif ($query === 'ROLLBACK' && $successful) {
            $connection->pendingRows = [];
            $connection->inTransaction = false;
        } elseif ($successful && preg_match('/\AINSERT\b/i', $query)) {
            ++$connection->lastInsertId;
            if ($connection->inTransaction) {
                $connection->pendingRows[] = $query;
            } else {
                $connection->durableRows[] = $query;
            }
        }
        $this->rows_affected = is_int($result) ? $result : 0;
        return $result;
    }

    public function recordFetch(TransactionManagerFakeConnection $connection, string $query): array
    {
        $this->queries[] = $query;
        $this->queryLog[] = ['connection' => $connection, 'sql' => $query];
        return $this->fetchHandler === null ? [] : ($this->fetchHandler)($query, $this, $connection);
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $old = $this->suppress_errors;
        $this->suppress_errors = $suppress;
        return $old;
    }
}

final class TransactionManagerTest extends TestCase
{
    private wpdb $db;
    private TransactionManager $transactions;

    protected function setUp(): void
    {
        $this->db = new wpdb();
        $this->transactions = new TransactionManager($this->db);
    }

    public function testReturnsCallbackValueOnlyAfterCommit(): void
    {
        $result = $this->transactions->run(function (TransactionManager $tx): string {
            $this->assertSame(1, $tx->execute('INSERT INTO fixture (value) VALUES (%s)', ['saved']));
            return 'result';
        });

        self::assertSame('result', $result);
        self::assertSame(['START TRANSACTION', 'INSERT INTO fixture (value) VALUES (%s) /* prepared */', 'COMMIT'], $this->db->queries);
        self::assertSame([['INSERT INTO fixture (value) VALUES (%s)', ['saved']]], $this->db->prepared);
    }

    public function testZeroAffectedRowsIsNotSqlFailure(): void
    {
        $this->db->queryHandler = static fn (string $sql): int|bool => $sql === 'UPDATE fixture SET value = 1' ? 0 : 1;

        self::assertSame(0, $this->transactions->run(static fn (TransactionManager $tx): int => $tx->execute('UPDATE fixture SET value = 1')));
        self::assertSame('COMMIT', end($this->db->queries));
    }

    public function testInsertIdIsReadFromThePinnedConnectionInsideTransaction(): void
    {
        $db = new wpdb();
        $manager = new TransactionManager($db);

        $id = $manager->run(static function (TransactionManager $transaction): int {
            $transaction->execute('INSERT INTO items (name) VALUES (%s)', ['product']);
            return $transaction->insertId();
        });

        self::assertSame(1, $id);
        self::assertSame(['START TRANSACTION', 'INSERT INTO items (name) VALUES (%s) /* prepared */', 'COMMIT'], $db->queries);
    }

    public function testInsertIdCannotBeReadOutsideATransaction(): void
    {
        $manager = new TransactionManager(new wpdb());
        $this->expectException(LogicException::class);
        $manager->insertId();
    }

    public function testFetchAllUsesThePinnedConnectionInsideTransaction(): void
    {
        $db = new wpdb();
        $db->fetchHandler = static fn (string $sql): array => [['id' => 17, 'status' => 'DRAFT']];
        $manager = new TransactionManager($db);
        $rows = $manager->run(static fn (TransactionManager $tx): array => $tx->fetchAll('SELECT id, status FROM orders WHERE id = %d', [17]));

        self::assertSame([['id' => 17, 'status' => 'DRAFT']], $rows);
        self::assertSame($db->dbh, $db->queryLog[1]['connection']);
        self::assertSame('COMMIT', end($db->queries));
    }

    public function testFetchAllRejectsNonSelectAndOutsideTransaction(): void
    {
        try {
            $this->transactions->run(static fn (TransactionManager $tx): array => $tx->fetchAll('UPDATE orders SET status = \'SHIPPED\''));
            self::fail('Expected read-only SQL rejection.');
        } catch (LogicException) {
            self::assertSame(['START TRANSACTION', 'ROLLBACK'], $this->db->queries);
        }
        $this->expectException(LogicException::class);
        $this->transactions->fetchAll('SELECT 1');
    }

    public function testDdlAndTransactionControlCannotRunInsideCallback(): void
    {
        try {
            $this->transactions->run(static fn (TransactionManager $tx): int => $tx->execute('/*!80000 DROP TABLE fixture */'));
            self::fail('Expected non-DML statement rejection.');
        } catch (LogicException) {
            self::assertSame(['START TRANSACTION', 'ROLLBACK'], $this->db->queries);
        }
    }

    public function testExecuteRequiresAnActiveTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->transactions->execute('UPDATE fixture SET value = 1');
    }

    public function testCallbackExceptionRollsBackAndPropagates(): void
    {
        $failure = new LogicException('callback failed');

        try {
            $this->transactions->run(static function () use ($failure): void {
                throw $failure;
            });
            self::fail('Expected callback exception.');
        } catch (LogicException $actual) {
            self::assertSame($failure, $actual);
        }

        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $this->db->queries);
    }

    public function testRetriesDeadlockOnlyAfterSuccessfulRollback(): void
    {
        $insertAttempts = 0;
        $this->db->queryHandler = static function (string $sql, wpdb $db) use (&$insertAttempts): int|bool {
            if (str_starts_with($sql, 'INSERT')) {
                ++$insertAttempts;
                if ($insertAttempts === 1) {
                    $db->dbh->errno = 1213;
                    $db->last_error = 'driver diagnostic must remain private';
                    return false;
                }
            }
            $db->dbh->errno = 0;
            return 1;
        };

        self::assertSame('ok', $this->transactions->run(static function (TransactionManager $tx): string {
            $tx->execute('INSERT INTO fixture VALUES (1)');
            return 'ok';
        }, 2));
        self::assertSame(2, $insertAttempts);
        self::assertSame(2, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'START TRANSACTION')));
        self::assertSame(1, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'ROLLBACK')));
    }

    public function testLockTimeoutUsesBoundedRetryLimit(): void
    {
        $attempts = 0;
        $this->db->queryHandler = static function (string $sql, wpdb $db) use (&$attempts): int|bool {
            if ($sql === 'UPDATE fixture SET value = 1') {
                ++$attempts;
                $db->dbh->errno = 1205;
                return false;
            }
            return 1;
        };

        try {
            $this->transactions->run(static fn (TransactionManager $tx): int => $tx->execute('UPDATE fixture SET value = 1'), 3);
            self::fail('Expected bounded lock-timeout failure.');
        } catch (DatabaseException $exception) {
            self::assertSame(1205, $exception->getCode());
        }

        self::assertSame(3, $attempts);
        self::assertSame(3, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'START TRANSACTION')));
        self::assertSame(3, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'ROLLBACK')));
    }

    public function testNonTransientSqlFailureDoesNotRetryOrLeakDiagnostics(): void
    {
        $this->db->queryHandler = static function (string $sql, wpdb $db): int|bool {
            if (str_starts_with($sql, 'INSERT')) {
                $db->dbh->errno = 1062;
                $db->last_error = 'duplicate secret-value SQL=INSERT password=hunter2';
                return false;
            }
            return 1;
        };

        try {
            $this->transactions->run(static fn (TransactionManager $tx): int => $tx->execute('INSERT INTO fixture VALUES (%s)', ['secret-value']), 3);
            self::fail('Expected SQL failure.');
        } catch (DatabaseException $exception) {
            self::assertSame(1062, $exception->getCode());
            self::assertStringNotContainsString('secret-value', $exception->getMessage());
            self::assertStringNotContainsString('hunter2', $exception->getMessage());
            self::assertStringNotContainsString('INSERT', $exception->getMessage());
        }

        self::assertSame(1, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'START TRANSACTION')));
        self::assertSame('ROLLBACK', end($this->db->queries));
    }

    public function testBeginFailureIsNotRetried(): void
    {
        $this->db->queryHandler = static function (string $sql, wpdb $db): int|bool {
            if ($sql === 'START TRANSACTION') {
                $db->dbh->errno = 1213;
                return false;
            }
            return 1;
        };

        try {
            $this->transactions->run(static fn (): string => 'never', 3);
            self::fail('Expected BEGIN failure.');
        } catch (DatabaseException $exception) {
            self::assertSame(1213, $exception->getCode());
        }

        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $this->db->queries);
        $this->assertReuseRejectedOnHandle($this->db->dbh, $this->transactions);
    }

    public function testCommitFailureIsNotRetriedAndCallbackIsNotRepeated(): void
    {
        $callbacks = 0;
        $original = $this->db->dbh;
        $original->commitBeforeFailure = true;
        $this->db->queryHandler = static function (string $sql, wpdb $db, TransactionManagerFakeConnection $connection): int|bool {
            if ($sql === 'COMMIT') {
                $connection->errno = 1213;
                return false;
            }
            return 1;
        };

        $returned = null;
        try {
            $returned = $this->transactions->run(static function (TransactionManager $tx) use (&$callbacks): string {
                ++$callbacks;
                $tx->execute('INSERT INTO fixture VALUES (maybe-committed)');
                return 'maybe committed';
            }, 3);
            self::fail('Expected ambiguous commit failure.');
        } catch (DatabaseException $exception) {
            self::assertSame(1213, $exception->getCode());
        }

        self::assertSame(1, $callbacks);
        self::assertNull($returned);
        $originalStatements = array_map(
            static fn (array $entry): string => $entry['sql'],
            array_filter($this->db->queryLog, static fn (array $entry): bool => $entry['connection'] === $original)
        );
        self::assertSame(['START TRANSACTION', 'INSERT INTO fixture VALUES (maybe-committed)', 'COMMIT', 'ROLLBACK'], $originalStatements);
        self::assertSame(['INSERT INTO fixture VALUES (maybe-committed)'], $original->durableRows, 'A reported rollback must not imply an ambiguous COMMIT was undone.');
        $this->assertReuseRejectedOnHandle($original, $this->transactions);
    }

    public function testRollbackFailurePreventsRetry(): void
    {
        $updates = 0;
        $this->db->queryHandler = static function (string $sql, wpdb $db) use (&$updates): int|bool {
            if ($sql === 'UPDATE fixture SET value = 1') {
                ++$updates;
                $db->dbh->errno = 1213;
                return false;
            }
            if ($sql === 'ROLLBACK') {
                $db->dbh->errno = 2013;
                return false;
            }
            return 1;
        };

        try {
            $this->transactions->run(static function (TransactionManager $tx): void {
                $tx->execute('INSERT INTO fixture VALUES (pending-before-rollback-failure)');
                $tx->execute('UPDATE fixture SET value = 1');
            }, 3);
            self::fail('Expected rollback failure.');
        } catch (DatabaseException $exception) {
            self::assertSame(2013, $exception->getCode());
        }

        self::assertSame(1, $updates);
        self::assertSame(1, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'START TRANSACTION')));
        self::assertSame(['INSERT INTO fixture VALUES (pending-before-rollback-failure)'], $this->db->dbh->pendingRows);
        self::assertSame([], $this->db->dbh->durableRows);
        $this->assertReuseRejectedOnHandle($this->db->dbh, $this->transactions);
    }

    public function testFailedCommitWithPendingWorkCleansUpAndQuarantines(): void
    {
        $connection = $this->db->dbh;
        $this->db->queryHandler = static function (string $sql, wpdb $db, TransactionManagerFakeConnection $handle): int|bool {
            if ($sql === 'COMMIT') {
                $handle->errno = 2013;
                return false;
            }
            return 1;
        };
        try {
            $this->transactions->run(static fn (TransactionManager $tx): int => $tx->execute('INSERT INTO fixture VALUES (pending-at-commit)'));
            self::fail('Expected failed COMMIT.');
        } catch (DatabaseException) {
            self::assertSame([], $connection->pendingRows);
            self::assertSame([], $connection->durableRows);
        }
        $this->assertReuseRejectedOnHandle($connection, $this->transactions);
    }

    public function testNestedRunOnSameConnectionIsRejectedBeforeBegin(): void
    {
        try {
            $this->transactions->run(fn (): mixed => (new TransactionManager($this->db))->run(static fn (): string => 'nested'));
            self::fail('Expected nested transaction rejection.');
        } catch (LogicException) {
            self::assertSame(['START TRANSACTION', 'ROLLBACK'], $this->db->queries);
        }
    }

    public function testRejectsAttemptCountsOutsideOneToThree(): void
    {
        foreach ([0, 4] as $attempts) {
            try {
                $this->transactions->run(static fn (): string => 'never', $attempts);
                self::fail('Expected invalid max-attempt rejection.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->db->queries);
            }
        }
    }

    public function testWpdbErrorDisplayStateIsRestored(): void
    {
        $this->db->suppress_errors = false;
        $this->db->show_errors = true;
        $this->transactions->run(fn (TransactionManager $tx): int => $tx->execute('UPDATE fixture SET value = 1'));
        self::assertFalse($this->db->suppress_errors);
        self::assertTrue($this->db->show_errors);
    }

    public function testConnectionReplacementFailsClosedWithoutRetry(): void
    {
        $callbacks = 0;
        $this->db->queryHandler = static function (string $sql, wpdb $db): int|bool {
            if ($sql === 'INSERT INTO fixture VALUES (1)') {
                $db->dbh = $db->newConnection();
                return 1;
            }
            return 1;
        };

        try {
            $this->transactions->run(function (TransactionManager $tx) use (&$callbacks): void {
                ++$callbacks;
                $tx->execute('INSERT INTO fixture VALUES (1)');
            }, 3);
            self::fail('Expected replaced-connection failure.');
        } catch (DatabaseException) {
            self::assertSame(1, $callbacks);
        }

        self::assertSame(1, count(array_filter($this->db->queries, static fn (string $sql): bool => $sql === 'START TRANSACTION')));
        self::assertNotContains('COMMIT', $this->db->queries);
    }

    public function testReplacementBetweenCallbackStatementsReceivesNoDml(): void
    {
        $original = $this->db->dbh;
        $replacement = null;

        try {
            $this->transactions->run(function (TransactionManager $tx) use ($original, &$replacement): void {
                $tx->execute('INSERT INTO fixture VALUES (1)');
                $replacement = $this->db->newConnection();
                $this->db->dbh = $replacement;
                $tx->execute('INSERT INTO fixture VALUES (2)');
            });
            self::fail('Expected connection-identity failure.');
        } catch (DatabaseException) {
            self::assertNotNull($replacement);
        }

        $replacementStatements = array_filter(
            $this->db->queryLog,
            static fn (array $entry): bool => $entry['connection'] === $replacement
        );
        $originalStatements = array_map(
            static fn (array $entry): string => $entry['sql'],
            array_filter(
                $this->db->queryLog,
                static fn (array $entry): bool => $entry['connection'] === $original
            )
        );
        self::assertSame([], array_values($replacementStatements));
        self::assertContains('ROLLBACK', $originalStatements);
        self::assertNotContains('COMMIT', $originalStatements);
        $this->assertReuseRejectedOnHandle($original);
        $this->assertReuseRejectedOnHandle($replacement, $this->transactions);
    }

    public function testCallbackCaughtExecuteFailureRollsBackAndBlocksFurtherDml(): void
    {
        $connection = $this->db->dbh;
        $this->db->queryHandler = static function (string $sql, wpdb $db, TransactionManagerFakeConnection $handle): int|bool {
            if (str_contains($sql, 'duplicate')) {
                $handle->errno = 1062;
                return false;
            }
            return 1;
        };

        try {
            $this->transactions->run(static function (TransactionManager $tx): string {
                $tx->execute('INSERT INTO fixture VALUES (first-pending)');
                try {
                    $tx->execute('INSERT INTO fixture VALUES (duplicate)');
                } catch (DatabaseException) {
                    // Deliberately swallowed to prove run() retains the attempt failure.
                }
                try {
                    $tx->execute('INSERT INTO fixture VALUES (must-not-run)');
                } catch (DatabaseException) {
                    // Further calls must not reach the handle after the first checked error.
                }
                return 'must not be returned';
            });
            self::fail('Expected latched execute failure to escape run().');
        } catch (DatabaseException $exception) {
            self::assertSame(1062, $exception->getCode());
        }

        self::assertSame([], $connection->pendingRows);
        self::assertSame([], $connection->durableRows);
        self::assertNotContains('INSERT INTO fixture VALUES (must-not-run)', $this->db->queries);
        self::assertSame('ROLLBACK', end($this->db->queries));

        $this->db->queryHandler = null;
        self::assertSame('later success', (new TransactionManager($this->db))->run(static function (TransactionManager $tx): string {
            $tx->execute('INSERT INTO fixture VALUES (later-valid)');
            return 'later success';
        }));
        self::assertSame(['INSERT INTO fixture VALUES (later-valid)'], $connection->durableRows);
    }

    public function testLatchedExecuteFailureRemainsPrimaryWhenCallbackThrows(): void
    {
        $this->db->queryHandler = static function (string $sql, wpdb $db, TransactionManagerFakeConnection $handle): int|bool {
            if (str_contains($sql, 'duplicate')) {
                $handle->errno = 1062;
                return false;
            }
            return 1;
        };

        try {
            $this->transactions->run(static function (TransactionManager $tx): never {
                try {
                    $tx->execute('INSERT INTO fixture VALUES (duplicate)');
                } catch (DatabaseException) {
                    throw new LogicException('callback changed the failure');
                }
            });
            self::fail('Expected the first checked execute failure to remain primary.');
        } catch (DatabaseException $exception) {
            self::assertSame(1062, $exception->getCode());
        }

        self::assertSame('ROLLBACK', end($this->db->queries));
        self::assertSame([], $this->db->dbh->durableRows);
    }

    public function testPostCallbackReplacementIsRolledBackAndQuarantined(): void
    {
        $original = $this->db->dbh;
        $replacement = null;
        $returned = null;

        try {
            $returned = $this->transactions->run(function (TransactionManager $tx) use ($original, &$replacement): string {
                $tx->execute('INSERT INTO fixture VALUES (pending-before-replacement)');
                $replacement = $this->db->newConnection();
                $this->db->dbh = $replacement;
                return 'must not be returned';
            });
            self::fail('Expected post-callback connection replacement failure.');
        } catch (DatabaseException) {
            self::assertNull($returned);
        }

        self::assertSame([], $original->pendingRows);
        self::assertSame([], $original->durableRows);
        self::assertSame([], $replacement->durableRows);
        $this->assertReuseRejectedOnHandle($original);
        $this->assertReuseRejectedOnHandle($replacement, $this->transactions);
    }

    private function assertReuseRejectedOnHandle(
        TransactionManagerFakeConnection $connection,
        ?TransactionManager $sameManager = null
    ): void
    {
        $before = count($this->db->queries);
        $pendingBefore = $connection->pendingRows;
        $durableBefore = $connection->durableRows;
        $view = $this->db->dbh === $connection ? $this->db : new wpdb();
        $view->dbh = $connection;
        $managers = $sameManager === null ? [new TransactionManager($view)] : [$sameManager];
        $managers[] = new TransactionManager($view);
        foreach ($managers as $manager) {
            try {
                $manager->run(static fn (TransactionManager $tx): int => $tx->execute('INSERT INTO fixture VALUES (forbidden-reuse)'));
                self::fail('Expected quarantined handle reuse to be rejected.');
            } catch (DatabaseException) {
                self::assertSame($before, count($this->db->queries), 'Quarantined handle must not receive START or DML.');
            }
        }
        self::assertSame($pendingBefore, $connection->pendingRows);
        self::assertSame($durableBefore, $connection->durableRows);
        self::assertNotContains('INSERT INTO fixture VALUES (forbidden-reuse)', $connection->durableRows);
    }
}
