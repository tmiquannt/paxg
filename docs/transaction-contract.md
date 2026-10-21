# TX-1b — accepted implementation input

Lead technical acceptance: 2026-10-19, revision TX-1b. This contract bounds only the transaction primitive, not business authorization/schema or completed workflows. Implements the infrastructure obligation of SPEC §7/INV-10/T-S12/T-S15. Candidate 2 against TX-1a is rejected; accepting this input does not accept its implementation.

### TX-1b terminal failure and reuse guarantees

- A failed COMMIT or post-callback connection-identity mismatch must attempt best-effort ROLLBACK on the original pinned handle, never on a replacement. Surface failure, never retry the callback or claim that an ambiguous COMMIT was undone. Conservatively quarantine the original handle after a COMMIT-phase failure even if cleanup returns success.
- Failed ROLLBACK means cleanup is unconfirmed: quarantine that handle. Quarantine must be shared across TransactionManager instances, keyed by actual connection object, and checked before any subsequent START TRANSACTION. Clearing the active/nesting marker must not clear quarantine. No public reset/unpoison shortcut. Future use requires an explicitly established fresh connection; this adapter must not automatically reconnect/replay. Reject uncertain failed-BEGIN sessions as well rather than allowing implicit commits on reuse.
- Latch the first checked execute() failure for the active attempt. Catching it inside callback code must not allow COMMIT or further DML; run() must rollback and surface failure. Reset the latch only for a new permitted attempt after confirmed rollback. Existing bounded retry policy remains limited to known 1205/1213 failures before COMMIT and after confirmed rollback, never connection uncertainty.
- Test terminal behavior, not merely error counts: failed COMMIT, post-callback replacement, failed ROLLBACK, and swallowed execute failure, each followed by attempted reuse through both the same and a new manager. Prove no COMMIT/START on quarantined handles, no writes to replacement handles, and no callback return value on failure. Stateful fake tests must model pending versus durable rows and must fail against candidate 2 before repair.
- Real local MySQL smoke must prove swallowed duplicate-key failure rolls back earlier successful DML, then a subsequent valid transaction cannot expose failed work; preserve real connection-loss/lock-timeout tests and cleanup. A disposable isolated session may demonstrate actual MySQL START TRANSACTION implicit-commit semantics for fake parity. COMMIT/ROLLBACK fault injection must be labeled synthetic unless it genuinely exercises a server failure; report untested ambiguous network outcomes rather than manufacture a real-DB claim. No production/network disruption or killing any session except the script's own verified disposable connection.
- Same four-file write scope below. Use PHPUnit --do-not-cache-result; shared caches are outside Peer ownership. Lead owns contract/status. RED/GREEN, subsequent-run state assertions, exact hashes, live smoke and remaining limits are required before Lead acceptance.

### TX-1a implementation clarification after rejected candidate

Lead accepts this clarification: the pinned WordPress `mysqli` handle must be held for the entire transaction, including every callback execution and COMMIT. Do not call reconnecting `$wpdb->query()` for transactional statements: WordPress may replay failed DML on a replacement autocommit connection before the adapter can detect it. Use non-reconnecting execution on the original handle, still using `$wpdb->prepare()` for values and verifying handle identity before/after preparation/execution. This is the same WP connection, not a separate business connection. No query filter or metrics dependency is promised by this narrow adapter. Callers must not use raw wpdb queries inside its callback. Verify no orphan DML after actual connection loss and no replacement-connection DML when handle changes between callback statements. Never retry ambiguous commit/connection-loss. Four-file ownership unchanged; request expansion if a necessary testable driver abstraction cannot fit cleanly.

## Interface and behavior

Namespace `MiniERP\Infrastructure\Database`:

- `TransactionManager::__construct(\wpdb $db)` uses the actual WordPress connection, not a second connection for business writes.
- `run(callable $work, int $maxAttempts = 3): mixed` invokes `$work($this)` within START TRANSACTION, returns its value only after successful COMMIT. Accept maxAttempts 1..3, reject other values. Nested run is rejected before another BEGIN; no implicit savepoints.
- `execute(string $sql, array $args = []): int` executes trusted server-authored SQL, preparing values with `$wpdb->prepare` when args exist; returns affected rows, throws `DatabaseException` on false/error. Never treat zero affected rows as SQL failure; domain services must check their own conditional-update guards. Prepared SQL strings must not be sourced from requests. Business repositories must use this checked operation for mutations, not unchecked `$wpdb->query`.
- `DatabaseException` exposes numeric database error code (standard exception `getCode()` is sufficient). Public exception message must not contain SQL, credentials, bound values or driver error text. Preserve underlying diagnostic cause privately if useful.
- On callback exception or checked SQL failure, rollback and propagate. Only a known MySQL deadlock 1213 or lock timeout 1205 may trigger retry, after rollback has succeeded, and within maxAttempts. No retry of validation/authorization/duplicate/unknown errors, failed BEGIN, failed/ambiguous COMMIT, or rollback failure. Callback may therefore execute up to three times and must have no external side effects; future services provide transactional idempotency keys.
- Connection loss or wpdb reconnect must fail closed; never accept a callback/commit on a replacement connection as committed work. Check connection identity around operations; do not automatically retry an ambiguous result. Do not log raw SQL to HTTP output. Preserve/restore wpdb error-display settings modified by this adapter.
- No schema install, business status updates, audit schema, roles, controllers, endpoints, exports, or changes to Docker in this milestone. DDL is forbidden inside the callback contract because MySQL implicitly commits it. The primitive is only for InnoDB DML; migrations run separately.

## Exclusive Peer write scope

Only these new files may be written:

- `wp-content/plugins/mini-erp/src/Infrastructure/Database/TransactionManager.php`
- `wp-content/plugins/mini-erp/src/Infrastructure/Database/DatabaseException.php`
- `tests/Unit/TransactionManagerTest.php`
- `scripts/test-transactions.php`

Lead owns all other files, especially this contract, docs/tasks.md, existing Docker/startup scripts, manifests, ExactAmounts and future policy/schema. Neither party edits the other's moving scope. Ask Lead before any new path or interface change.

## Acceptance evidence

1. Tests first, with recorded failing result; unit tests cover commit/value, rollback, finite retries, no retry for nontransient/commit failure, nested rejection, maxAttempts, zero affected rows, and no secret leakage. Any fake must be checked against real wpdb behavior for used operations; it is not a substitute for MySQL evidence.
2. Run existing Docker PHPUnit suite (auto-discovers tests/Unit). No root manifest or runner edits needed.
3. Real WP/MySQL smoke through `docker compose exec -T wordpress php /opt/erp-scripts/test-transactions.php`. Script requires CLI, environment `local`, DB name `mini_erp_local`, prefix `paxg_`; load WP then the two source files explicitly from mounted plugin directory. No dependency on unavailable Composer inside web container.
4. Script may own only a uniquely named temporary InnoDB fixture table `paxg_erp_test_tx_<random hex>`. Assert its name with a strict allowlist before CREATE/DROP. Create outside transaction, drop exactly that table in finally; never alter real ERP/WP records. Prove committed row, callback rollback, prepared-values safety, SQL-error rollback, and real lock timeout with bounded retry if feasible using a separate test connection. No business schema required. Explicitly report any unverified deadlock/reconnect cases.
5. Handoff: contract hash/base input hashes, all changed paths and per-file SHA-256, red/green commands/results, actual DB smoke result, fake parity, limitations, ownership release. No completion claims for business workflows. Lead inspects exact files and reruns evidence before ACCEPT/REJECT.

Reopen if wpdb reconnect semantics prevent reliable failure detection, environment permissions prevent fixture isolation, or any shared contract/path needs alteration. Do not silently weaken safety to make tests green.
