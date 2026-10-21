# Mini ERP v1.1 — implementation status

## Baseline and authority

Project: `/home/tmi_pc_2025_quan/Desktop/paxg`. Initial input was only SPEC.md, SHA-256 `27908f439d270df9b91e98a032b4334d7e529e390553f696fc541526b5d1ea58`. No existing code or tests. Parent Desktop is a dirty repository; never stage, commit, clean, or modify outside this project. No production data, deployment, banking integration, real payment execution, or legal/accounting compliance claim is authorized.

Lead owns technical design, integration, verification, acceptance, and all writes except active exclusive scopes listed below. Source specification is design, not evidence. This file is the single work-status and acceptance record.

### Policy design boundary (2026-10-19; updated 2026-10-20)

No accepted row/field contract currently bounds a writable permissions assignment. The separate read-policy draft and accepted risk finding are NOT blanket implementation inputs. At the time of the original note no permissions work had been dispatched. The user has since authorized end-to-end ERP implementation and selected explicit per-user cash-account assignment. Lead retains policy design and implements the role/object boundaries stated directly in SPEC; additional draft-only row/field rules remain unaccepted until reconciled with SPEC. Cash account and payment/cash details continue to require an explicit assigned account scope.

Proposed future write boundary (not an active assignment): Peer owns only `wp-content/plugins/mini-erp/src/Security/` and `tests/Security/`; Lead owns bootstrap, `src/Infrastructure/`, `src/Domain/`, controllers/templates, root manifests, runtime, scripts and docs. Contract must fix the policy interface and identity/scope inputs before this split is usable. No Peer may infer extra fields/capabilities or change schema/controller contracts. Acceptance must include denied-field/query/IDOR tests, not merely capability unit tests. Reopen for a missing row rule or unclear field, with no speculative permission grant.

Independent ready work: Lead-only local WordPress/MySQL environment setup. It has no dependency on the unresolved policy or invoice-line schema and does not accept either.

## Settled product decisions

- D1: reconciliation CLOSE requires exact zero delta; explanations never override.
- D2: opening inventory is outside P0. Zero initial balances; approved PO receipts supply demo/MVP stock. No opening-count feature, direct edits, or production imports.
- D3: retain fractional-VND line precision; round document subtotal once. No independent line-subtotal rounding.
- D4: integer-only line snapshots cannot represent D3. Lead reported mismatch; the earlier decimal-net proposal is superseded by PRICE-1a: preserve exact raw subtotal and explicit line allocation bridge, allocate document subtotal/discount/tax globally, retain integer allocated net lines. SUM allocated net lines = document total, with no second rounding adjustment. Pure-calculator contract accepted; persistence/UI remain unverified and no monetary migration accepted yet.

## Technical interpretations

- Effective payments are POSTED, VOID_PENDING, VOID_APPROVED; only an atomic VOID+reversal removes their contribution to paid totals. Use one definition in guards, reports and UI. A void request cannot free invoice capacity.
- Negative-cash reversal remains rejected under existing P0 policy. No refund or correction module is added.
- Reconciliation must recheck complete matching, exact opening/closing, period overlap and immutability under account serialization. Lock hierarchy and date rules must be accepted before payment/reconciliation implementation.

## Milestones / traceability

For each milestone: failing tests first, implementation, full relevant tests, real boundary demo, explicit acceptance. Passing unit tests alone do not establish deployable UI.

| Milestone | Requirements / cases | State |
| --- | --- | --- |
| M1 bootstrap, schema, policies, exact arithmetic, audit | FR-ADM-01, FR-AUD-01; T-S01/02/05/08/16; numeric bounds | Docker, pure PRICE-1 and TX candidate 4 accepted; role/access UI now has labeled scope selectors and authenticated save-flow evidence; broader row/field/query policy and full security matrix remain unaccepted |
| M2 master data and sales/purchase workflows | FR-PRO/CRM/SUP/SAL/PUR-01; T-F01..03 | Master-data and sales/purchase UI/service candidates exist; selected local MySQL workflows pass; full permission and visual acceptance remain open |
| M3 receipts, shipments, stock adjustment | FR-INV-01; T-F04..06/19; T-S09/10/12/15; INV-01..03/06..10 | Receipt/shipment/adjustment candidates and local workflow smoke exist; concurrency and complete invariant matrix remain unaccepted |
| M4 immutable invoices and protected documents | FR-ACC-01..03/06; T-F07/08/10/12/17; T-S13/17 | Invoice HTML/PDF and payment/expense voucher candidates exist; selected isolated MySQL/PDF checks pass; rendered role review and full snapshot/IDOR evidence remain open |
| M5 payments, expenses, cash, void | FR-ACC-04/05/07/08/10; T-F09/11..15; T-S11/18..21 | Payment, expense, assigned-account cash, void and reconciliation candidates exist; voucher/ledger and 102-batch/103-unmatched-row reconciliation UI smokes pass; concurrency/maker-checker acceptance remains open |
| M6 reconciliation/reports/security/restore | FR-ACC-09, FR-REP-01; T-F16/18; T-S03/04/06/07/14/22..24; INV-11..18 | Dashboard/reports and selected smoke checks exist; authenticated Manager dashboard and stock-report checks now confirm company-wide stock visibility; full route/object/field matrix, NFR benchmarks, backup/restore, and review across all roles remain open |

All NFR benchmarks, PDF/browser checks and restore drills remain unverified. No business workflow is accepted.

## Implementation dispatch sequence and ownership

Selection rule: choose the smallest observable outcome whose actual inputs are present and accepted; do not dispatch unresolved policy/schema as if the spec risk review settled them. Reuse the flexible Peer where context fits. Add a second writable Peer only for an independently ready, disjoint scope. Shared files and interfaces are sequenced. Database-mutating test runs are coordinated by Lead; no concurrent fixture/schema resets.

| Bounded outcome | Prerequisite acceptance | Exclusive implementation/test scope | Lead integration/evidence |
| --- | --- | --- | --- |
| M1-TX checked transactions | TX-1 contract; actual WP/MySQL healthy; PHPUnit/autoload verified | TransactionManager.php, DatabaseException.php, tests/Unit/TransactionManagerTest.php, scripts/test-transactions.php ONLY (full paths in contract) | Inspect snapshot; rerun unit + real rollback/retry smoke; explicit disposition |
| M1-POL row/field policy | Exact policy contract accepted first; no assignment yet | Proposed src/Security and tests/Security; finalized in brief, not blanket ownership now | Negative projections, object/child scope, filters/counts/errors, real route checks after wiring |
| M1-SCHEMA bootstrap/migrations/audit | TX explicitly accepted and TX fixture cleanup confirmed before implementation or DB smoke. Five-master-table phase additionally needs MASTER-SCHEMA-1; monetary phases additionally need finalized snapshot contract | Lead-only active scope: MasterSchema.php and scripts/test-master-schema.php; implementation is not runtime accepted. No DB smoke is active. Later phases require separate scopes excluding TX | Actual InnoDB constraints, repeat migration/preservation, incompatible-table fail-closed behavior, bounded lock and exact fixture cleanup; plugin wiring Lead-owned |
| M2 master data + orders | Policy, schema, pricing/snapshot APIs accepted | Bounded service/repository/controller/template files per vertical slice + matching tests, exact list assigned later | Real scoped save/reopen/approval UI, master-data uniqueness and negative actions |
| M3 stock fulfillment | Accepted order and warehouse-scope inputs | Stock/fulfillment service files + concurrency/invariant tests | Real MySQL races/rollback and UI receive/ship; no opening stock |
| M4 invoices/documents | Fulfilled sources, snapshots and read projections accepted | Invoice/document service/templates + PDF/authorization tests | Inspect actual Vietnamese PDF/multipage and IDOR/immutable snapshots |
| M5 cash/payment/void | Accepted invoice, lock/date rules and account policy | Cash/payment/expense service files + ledger/void tests | Double-submit/concurrency, maker-checker, effective pending voids, voucher UI |
| M6 reconciliation/reports | Accepted cash ledger/period contract | Reconciliation/report files + matching tests | Zero delta/full match/overlap guards, reports from sources, role UI and restore drill |

Future rows are a dependency sequence, not active delegation or accepted contracts. Each brief must fix observable outcome, exact base/contracts, exclusive paths, no-go boundaries, tests/demo evidence and reopen conditions. Lead owns root config/Docker, integration glue, shared contracts and status unless a later explicit transfer records otherwise. Lead will not edit an active Peer scope.

### M1-TX history and current review state

Peer `b5f6102e-e4b8-410c-acd0-4592ebf78739` is reused from its completed read-only assessment as a separate writable assignment. Its earlier review did NOT implement Docker. Lead verified Docker executable hashes unchanged, healthy core/DB, existing PSR-4 mapping and test discovery before dispatch. TX-1 in `docs/transaction-contract.md` is explicitly ACCEPTED as the narrow implementation contract; this does not accept unfinished code or policy/schema.

Original TX ownership was released for candidate-2 review. Review is now closed and candidate 2 REJECTED; a new TX-1b repair assignment grants the same four paths to Peer b5f6102e only. Lead owns acceptance and documentation. No TX code accepted; policy not dispatched; schema remains ON HOLD.

Historical TX-1 input hash: `a33f10ae7ba910bb9f3c6f39689aed3faef8b375b6d983ce61a85441a92e8177`; superseded by TX-1a hash `99e67303bd2944b141f3697b41628415985cc6c9c615c0e3b698c6d2b66f3253` for candidate 2. Writer completion is not acceptance.

M1-TX candidate 1 received and all four files inspected. Peer hashes: TransactionManager `1aa15e3c6a976233d7e64c6e276090a2303998ec00b768a2c293f0d9c7185591`; DatabaseException `8fb6e8b086d7fdd693b21779f9f3fc2699e78cd52ad794d8b1a6ab89f0c3f162`; unit test `e9db94872125f23f9bd188ff2afbe246c1a52682b3d225e4c30e2db7e742b9f2`; smoke `9f20c37895e9f97f2b9ce38a2d0b4f78b16acfd7202df6f326a95198fe1d8469`. Peer base parent HEAD `0e99b9477c26f410225c2527b297eeaed1cb100e` is identity only, not a clean project base. Lead reran 42 tests/78 assertions, passing. Nevertheless **REJECT** candidate 1: wpdb's source at class-wpdb.php 2279ff reconnects and replays a failed query; post-query connection identity detection does not prevent orphan autocommitted DML. execute() also captures the current handle instead of the original transaction handle. No business dependency is released. TX-1a clarifies non-reconnecting execution on the original WP handle; same Peer receives exclusive four-file scope for repair, RED reproduction including persisted-state assertions, and real connection-loss evidence. Original ownership release is superseded only by that new repair assignment.

### Independent read-policy challenge

Lead wrote `docs/read-policy-contract.md`, draft hash `6fb8a90b7da4a3324a1b5cb5373bd9cbd4a5195f2bbfaa79d087760cf9a5774a`. It explicitly remains unaccepted for implementation. New flexible read-only Peer requested because existing Peer owns a separate active TX outcome. Brief: inspect exact draft against SPEC §3/§8 for row/field/child/filter/count/export/AJAX/error leaks, unsupported policy changes (including account assignment), circular prerequisites and missing exact interfaces. No writes, DB mutations, TX interference or delegation. Return referenced findings/hash/limits and ownership release. Lead will disposition actual response before relying on findings or dispatching policy implementation; no policy-code owner assigned yet.

Read-only Peer ID: `e5023371-5aea-4bc9-8fb5-2f8154dbbafd`; no write scope. Lead will keep reviewed draft bytes stable until response disposition.

Actual response inspected in full: DEPENDENCY_REQUEST on POL-READ-1 hash above, not a code review. Disposition: REJECT draft as implementation input; ACCEPT gaps in scope bootstrap, exact relation/descriptors, HTTP consistency and endpoint projections. Lead owns revision; no dependent policy dispatch. Metadata reads need validation and controlled writes, but changing storage cannot defend against malicious server-side PHP (trust-root limit). Sales own-invoice buyer/seller identity fields remain justified by existing customer management + own print authority; additional accounting/bank fields remain denied, not silently broadened. Lead selects generic 404 for missing/out-of-scope objects (T-S03/13), 403 for missing global capability; spec text will be reconciled. Bounded CSV batches are technical, must be labeled/paged and never silently truncated. Cash-account visibility was resolved by the user on 2026-10-20: only explicitly assigned accounts, with no unassigned-DRAFT creator exception; warehouse assignments are already required. Findings do not prove implementation. Disposition sent to affected Peer; no write ownership retained.

Lead-only parallel work: `docs/pricing-contract.md` captures a refinement of D4 for evaluation: preserve exact raw lines, allocate the once-rounded document subtotal by largest remainder, show explicit line adjustments and retain integer allocated net lines. This avoids misleading independent rounding and negative decimal nets from naive integer-discount allocation. Draft only; no migration input accepted, no implementation assignment yet. D3 remains unchanged.

Peer e5023371 reused for read-only PRICE-1 challenge, exact draft hash `39db1cee65bf841d38e4bff2fb0438ef38183b9bf650c790b40afc9d40a05916`; no writes/DB mutations, inspect coherence/nonnegative allocations/bounds/display and distinguish arithmetic from persistence/UI. Await actual response before relying on it.

PRICE-1 response inspected in full. ACCEPT mathematical coherence finding with limits (review ran no implementation tests); ACCEPT equation/display mismatch and reconcile SPEC to allocated integer line totals summing directly to document total. ACCEPT removal of unrelated 100-line/duplicate-product restriction; stable source-line ID replaces product tie. Remove arbitrary tax-rate ceiling; guard integer rates and final overflow instead. REJECT suggestion zero-total invoice rejection lacks baseline authority: SPEC §6.2 explicitly requires total_vnd > 0; calculator may return zero for draft, invoice issue rejects. Lead accepts revised PRICE-1a as pure-calculator implementation input, not accepted persistence. Peer may be reused for exactly calculator+test files once brief is sent, independent of TX four-file scope. No shared-file edits or DB mutation authorized.

M1-PRICE completed assignment: accepted contract hash `51fc92a63061ba625ee1d4c193cbc99026b127085c9dd3f39864c7057a324851`; helper base `6a7afd92579845392d2fc5c9b8cc31afcb8808f213e369360a4972e1b3c33047`. Candidate scope is exactly `wp-content/plugins/mini-erp/src/Domain/PricingCalculator.php` and `tests/Unit/PricingCalculatorTest.php`. Ownership released; pure calculator code **ACCEPTED**, with exact hashes and combined verification below. Persistence/UI/PDF remain unaccepted.

PRICE-1 generated-artifact disposition (2026-10-19): the initial RED run wrote the default `.phpunit.cache/test-results` outside the two assigned source/test paths. This is a disclosed scope exception, not retroactive expansion of Peer ownership. Lead inspected the artifact: ignored by `.gitignore:4`, not tracked, and shared (27 ExactAmounts, 16 TransactionManager, 14 PricingCalculator cached timings). Lead's TX verification also updated it; no isolated PRICE-only original cache snapshot exists to restore safely. **Retain the shared disposable cache under Lead runtime ownership**, excluded from the candidate; no deletion or speculative restoration. Peer must use `--do-not-cache-result` and must not modify the cache. Corrected two-file manifest and side-effect disclosure received; ownership released. Lead subsequently verified and **ACCEPTED the pure calculator**, as recorded below; persistence/UI/PDF remain unaccepted. Ignored status is not evidence that the artifact was never written.

Prepared technical input: `docs/master-schema-contract.md` MASTER-SCHEMA-1 for five master tables only. Narrow design input accepted; implementation and DB smoke **ON HOLD until explicit TX acceptance and fixture cleanup confirmation**. Independence from money-line/cash-policy choices does not waive the TX milestone prerequisite. The premature dispatch below is withdrawn, not treated as accepted progress.

### PRICE-1 acceptance and next scopes (2026-10-19)

Lead **ACCEPTS the pure PRICE-1a calculator candidate only** after reading both complete files, checking the exact contract, matching both hashes, and rerunning the combined Docker suite: 57 tests / 4,100 assertions, PHP 8.3.28 / PHPUnit 11.5.57. Command: `docker compose run --rm --no-deps tools php vendor/bin/phpunit --do-not-cache-result`. Cache SHA-256 before/after was identical (`26da05a0e77a38a291875844ed434c47f5902fb8113442c0d0ac516a4d1fcc75`). Project-scoped `git diff --check` passed. Reason: exact BCMath aggregation, stable numeric source-line ordering, document-wide remainder allocation and bounded totals match PRICE-1a and D3; integer line totals do not double-count the rounding bridge. Accepted manifest: `wp-content/plugins/mini-erp/src/Domain/PricingCalculator.php` SHA-256 `36394cb8fe18e09ddac305ac1c2f2cbd190808714cb4ed9fc3d51c76d1a7b2db`; `tests/Unit/PricingCalculatorTest.php` SHA-256 `101f7ff429ee07b64829bc3094ee7e4fb9519fa8f4e4d8fff05ea6746157cdd1`. Base inputs are the contract/helper hashes above; parent HEAD is identity only, not a clean project snapshot. Ownership released. Persistence, display, PDF, invoice issuance and monetary migrations remain unaccepted.

M1-TX repair candidate 2 hashes match its handoff. Lead reran targeted tests (16 tests / 44 assertions) and real MySQL smoke: commit, rollback, prepared literal, duplicate rollback, real lock-timeout bounded exhaustion, killed disposable connection with zero orphan DML all pass. Fixture removed. These passes did not cover terminal failure plus subsequent reuse. The bounded read-only review has returned; candidate 2 is **REJECTED**, as dispositioned below. Its recorded hashes identify the rejected base, not accepted software.

MASTER-SCHEMA-1 original accepted design hash `d07467d282e97d3e967f3eddfe69f4ff74808a66719f76478c4f5a50911b9a3c`: the subsequent implementation dispatch was premature against the recorded TX prerequisite. **WITHDRAWN / ON HOLD**. Peer b5f6102e has been instructed to stop implementation and new DB tests, finish only exact-fixture cleanup if already running, preserve partial files, and report paths/hashes/results/remaining fixtures before releasing ownership. Await that response; do not claim the hold is acknowledged or cleanup complete yet. No other writer may touch its two paths while stopping.

Next assignment boundary: Lead first dispositions the frozen TX review, requests a new exact candidate if repair is needed, reruns relevant unit/MySQL checks, records explicit TX ACCEPT and confirms fixture cleanup. Only then may Lead redispatch master schema to one Peer for exactly `wp-content/plugins/mini-erp/src/Infrastructure/Database/MasterSchema.php` and `scripts/test-master-schema.php`, using the revised contract hash and any preserved partial-file hashes as base. That Peer gets an exclusive, serialized DB-smoke window, never concurrent with TX repair/acceptance smoke. No TX, pricing, Docker, bootstrap, policy or docs edits; no complete-schema version stamp. Monetary schema needs its own later snapshot acceptance. Lead retains integration and acceptance.

Completed COMMIT review identity (rechecked after schema hold): TX-1a hash above; TransactionManager.php `2d4e14abf7cdec59f4defe6788b76b5e80fff3addf454bfb77bfe06252a93d2f`; DatabaseException.php `8fb6e8b086d7fdd693b21779f9f3fc2699e78cd52ad794d8b1a6ab89f0c3f162`; tests/Unit/TransactionManagerTest.php `9d3d3026ff726acf3b034ad46fee1ab5ec48105a22386b0f5550933a64ba9a7b`; scripts/test-transactions.php `b12a462c7249775a981d3e14ec73fdf48de67411e210740f11b616d6a5706b66`. Peer e5023371 released read-only review ownership, with no files/cache/DB changed. Review applies only to these frozen bytes; new repair requires a new hashed candidate and verification.

### TX candidate-2 disposition / TX-1b repair

Lead inspected the full REOPEN_REQUEST and the affected code/tests. **ACCEPT findings 1–3 as code-backed failure paths; REJECT exact candidate 2 above for TX acceptance.** COMMIT-phase failure bypasses pinned rollback, rollback failure permits later reuse, and caught execute failure can reach COMMIT. The Peer reproduced these with a stateful in-memory model only, NOT MySQL. Prior live smoke does not validate them. MySQL's documented implicit-commit-on-START behavior supports the consequence; no real ambiguous-COMMIT reproduction is claimed.

Accepted repair input: `docs/transaction-contract.md` TX-1b SHA-256 `3f528402d0b2dbd835b87db5cd69213938d67ff398a02455ec05b4c135e32a89`. Owner b5f6102e, exactly TransactionManager.php, DatabaseException.php, tests/Unit/TransactionManagerTest.php, scripts/test-transactions.php at the paths in that contract. Lead owns docs/shared inputs; reviewer has no writes. Required fix: pinned best-effort cleanup without retrying ambiguous COMMIT, cross-manager quarantine of uncertain handles before future START, and latched checked failures that cannot be swallowed into a commit. No schema work. Peer has the sole isolated TX DB-fixture window; use --do-not-cache-result.

Closure checkpoint: Peer must deliver regression RED against candidate 2 and GREEN with stateful subsequent-run assertions, including a fresh manager on the same handle; real MySQL swallowed-duplicate rollback and subsequent successful-run durable-state proof; preserved loss/lock tests; exact fixture cleanup; new four-file hashes and explicit synthetic/unverified limits. Lead inspects new bytes, reruns tests and live smoke, dispositions the repair evidence (and requests focused read-only recheck if uncertainty remains), then records ACCEPT or REJECT. Only explicit TX ACCEPT plus confirmed fixture cleanup releases the schema prerequisite. Passing tests, repair completion, or this contract acceptance alone do not release it.

### TX-1b returned candidate and Lead evidence supplement

Peer returned and released all four paths/DB window. Candidate 3: manager `3ccfe4117e731c949b57d0732753d332e123f58362ef2b0776e4382264141991`, exception unchanged `8fb6e8b086d7fdd693b21779f9f3fc2699e78cd52ad794d8b1a6ab89f0c3f162`, unit `d10db75a76fe8154267dbaebea95bfc5b77eaed7ce0252efc07eb86d052f9220`, smoke `2d12d0737db8784e64c461e1e03b0012917432a3576d518ab46f6b413e9bb181`. Lead inspected all four complete files and matched hashes. **REJECT that manifest for final acceptance on evidence completeness**, not a new demonstrated adapter defect: fake START discarded pending rows instead of committing them, and rollback-failure test had no prior pending INSERT. Peer's reported RED (18 tests/5 failures), GREEN (60 tests/4,144 assertions) and real smoke are retained as reported evidence; they do not resolve that modeling gap.

After ownership release Lead changed only the unit and smoke files: model implicit START commit, retain actual pending rows on failed rollback and prove same/new-manager reuse performs no SQL or durable changes, add failed-COMMIT-before-durability cleanup case, and verify START semantics on a disposable real session/fixture. No manager/exception edits. Integrated candidate 4 retains manager/exception hashes above; unit `c3b7942683435cc21afc33843a6f5078e34014cc3142f573bbe2eb54d5fb4684`; smoke `e4e5496dfaffbe6508b7e2ff0a3b40b1da33b29e6d79ceddc76ec8f64ad126a2`. Same TX-1b input and parent-base identity. Lead reran Docker full suite with --do-not-cache-result: **61 tests / 4,160 assertions pass**. Real WP/MySQL smoke passed all prior cases plus swallowed-error/subsequent-run safety and real START parity; exact fixture removal succeeded. Project diff whitespace check passed. Real ambiguous COMMIT/ROLLBACK server failures remain uninduced; those paths are synthetic unit evidence, not real fault claims.

### TX-1b candidate 4 acceptance (2026-10-20)

Lead inspected the complete candidate 4 implementation, unit test and MySQL smoke script and rechecked the exact four-file manifest against the frozen bytes. The manifest matches the recorded candidate: TransactionManager.php `3ccfe4117e731c949b57d0732753d332e123f58362ef2b0776e4382264141991`; DatabaseException.php `8fb6e8b086d7fdd693b21779f9f3fc2699e78cd52ad794d8b1a6ab89f0c3f162`; tests/Unit/TransactionManagerTest.php `c3b7942683435cc21afc33843a6f5078e34014cc3142f573bbe2eb54d5fb4684`; scripts/test-transactions.php `e4e5496dfaffbe6508b7e2ff0a3b40b1da33b29e6d79ceddc76ec8f64ad126a2`. TX-1b input hash `3f528402d0b2dbd835b87db5cd69213938d67ff398a02455ec05b4c135e32a89`.

**ACCEPT TX candidate 4 for the narrow transaction primitive.** The checked code pins raw mysqli execution to the original handle, quarantines terminal handles across manager instances, best-effort rolls back on that handle after ambiguous commit/identity failures, and latches checked SQL failures. The stateful fake now models pending and durable rows, implicit START commit, failed rollback residue and subsequent same/new-manager reuse. The recorded hash-bound evidence is 61 tests / 4,160 assertions plus real local WP/MySQL smoke for committed/rolled-back DML, swallowed duplicate rollback and subsequent valid transaction, lock timeout retry exhaustion, killed disposable connection with no orphan DML, MySQL START parity and exact fixture cleanup. Ambiguous server-side COMMIT/ROLLBACK faults remain synthetic and are not claimed as reproduced. The DB fixture window is released and cleanup was recorded successful. This acceptance does not accept schema, policies or business workflows.

Schema hold response received and inspected: Peer confirms no schema file, test or DB fixture operation started and releases write/DB-window ownership. Lead independently verified both assigned paths absent. **ACCEPT the hold/ownership-release report**, not schema implementation. No schema cleanup is needed; prior TX fixture cleanup evidence remains separate. The revised MASTER-SCHEMA-1 hash is `314a1289f1d212d13278dc1c648a1e2ab390db7281c39ffe40b3ac0e17b4a147`.

### M1-SCHEMA implementation started (2026-10-20)

After TX acceptance and confirmation of its fixture cleanup, Lead assigned the two exact MASTER-SCHEMA-1 paths to the Lead: `wp-content/plugins/mini-erp/src/Infrastructure/Database/MasterSchema.php` SHA-256 `52c0bd96812ae34008f00ae8e6f501d7e2eed13b4364a71d3bf5d5eb60e7446c` and `scripts/test-master-schema.php` SHA-256 `05371170356da1b52d808d052f25df82e556cbb9a5ca01066aba8f7081c4197e`. No other writer or DB-test window is active. Runtime tests and DB smoke have not been run in this turn; schema acceptance remains pending, including exact-prefix fixture cleanup. The implementation pins migration metadata and DDL to the mysqli session holding the bounded advisory lock so a wpdb reconnect cannot continue outside the lock. The implementation must be reviewed against the accepted contract and any defects repaired before claiming M1 schema completion. No full version stamp, plugin activation wiring, seed records, monetary schema or policy code is included.

Lead revised read-policy draft to label proposed account-assignment rules explicitly unapproved, added validation/write-boundary requirements, explicit Sales projection rationale and exact AJAX/download projection groups. SPEC §8.2/SEC-02 now consistently selects generic object-scope 404. Pure-policy relation/descriptor interface and cash-account decision still pending; no policy-code dispatch. Human asked only cash-account scope choice asynchronously; no repeat question about opening stock or document rounding.

### ERP runtime/authentication foundation started (2026-10-20)

User explicitly expanded scope from the standalone UI prototype to WordPress authentication, ERP authorization and business workflows. Added plugin entrypoint/activation wiring, five capability roles from SPEC §3, immutable request Actor resolution and a deny-by-default role capability gate: `wp-content/plugins/mini-erp/mini-erp.php` `c811470012660ad43e2e0bc04003e71dde1c5b50793e5561c69111c2dc541fe3`; `src/Bootstrap.php` `a3d90cbe525ccba8e596a07f4fa8214dc6450a8bbe0c3c64d2cbb876c48ac8f7`; `src/Security/RolesInstaller.php` `3af6b07cdef56cc9a365de50e4735620e3b5c3affe6569fc13501311a3357364`; `src/Security/Actor.php` `ab20d18690ae95af0dc294dcc47fbc42bc3274fc01930c50a8421e28140b18f8`; `src/Security/CapabilityPolicy.php` `d2123a5d0c574918f27f7e4640a4db6160d66573274042c8e2d536261376bfe7`. This is not accepted auth: no user provisioning/disable UI, row/field object policy, protected scopes, audit path, route guards, or business services are wired. No tests or runtime activation were run. Cash-account visibility is still unresolved; implementation defaults to deny absent explicit scope and does not grant cash/payment access via this capability foundation alone.

### ERP authentication, scoped access and audit candidate (2026-10-20)

Continued the runtime foundation with a System Admin user-access screen for existing WordPress accounts, role/enable/warehouse/cash-account assignments, nonce and capability guards, atomic WordPress user-meta/role updates through the accepted transaction boundary, and a same-transaction audit event. Added explicit `Actor.cashAccountIds`, default-deny account-scoped read helpers, authz-denied event recording for authenticated WordPress users, read-only audit-log screen with access-event logging, and append-only `erp_audit_logs` migration/compatibility checks wired into plugin activation. Enabled state is required for an ERP Actor. Cash IDs cannot yet be assigned because `erp_cash_accounts` is not implemented; the assignment path intentionally rejects nonempty missing-table scopes. Existing-account provisioning still uses WordPress core account creation/login/reset flows; the ERP screen only assigns ERP access.

Candidate SHA-256 after these edits: `src/Bootstrap.php` `8f4d85f1f14fa8b0e64e904357396a57067f455c52874e193e06738c1d345cba`; `src/Security/Actor.php` `78436a4fcfc887efbc276701bf7648150be475d7fa9f0e8beeb49b1e1e2cf80c`; `src/Security/CapabilityPolicy.php` `2cfa508664e6f21419bb693a49a9d3b0afaa18a8470335294c8148add94ea3c1`; `src/Security/ReadPolicy.php` `d06fe1eab95d941c0dccac4b53da2a1166e62f54d10f0aa870177d82ac59fd32`; `src/Security/UserAccessController.php` `bd3a9e5c2ac44441e647b785d7d6f0d184c0af1ea2e32137d8fcf91ad589f95b`; `src/Security/AuditLogController.php` `1b4eeb06275b852e8e9d24edce26f45f703dc299350f3512060331d0a45e1918`; `src/Infrastructure/Database/AuditSchema.php` `b6dbc2e497bacb2e476f7d0f468c16f97ffa4221884774e1a8d8673c25da49a6`; `src/Infrastructure/Audit/AuditLogger.php` `9ba3cf008dacb61baa11a5931417cac38ec1ee0ce2159d5a7d244d7282561f6c`.

Not accepted: no tests, plugin activation, runtime smoke, or database migration were run for this candidate. The accepted TX primitive is reused, but use of core WordPress role/usermeta APIs inside its transaction still needs runtime validation. User UI currently shows at most 100 accounts without pagination; audit UI shows 100 rows. Audit events currently cover role/scope-count changes, authenticated authorization denials and audit screen access; business event coverage is not wired. No sales/purchase persistence, inventory ledger, invoice, payment, cash movement, reconciliation, reports, or working order UI has been delivered. The earlier read-policy draft remains unaccepted for end-to-end repository use; helpers alone do not enforce list/detail/SQL scope.

## Peer registry and original brief

Peer `b5f6102e-e4b8-410c-acd0-4592ebf78739`, flexible codex-peer profile; read-only assignment, no write scope, now released. Original brief is recoverable through agent activity and reproduced here in substance: read shared workspace contract and complete original SPEC; assess transaction locks, reconciliation period integrity, pending void arithmetic, object/field authorization, exact money and opening stock. Treat SPEC as baseline only; return exact references, recommendations, product decisions, SPEC hash, limits and ownership release. No edits, delegation, commits, deployment or outside-project writes. Lead owns foundation/contracts. Reopen for failed premises or unsafe ambiguities.

Response inspected in full via activity on 2026-10-19, candidate was the original SPEC hash above; no runtime tested. Disposition: ACCEPT findings 1–5 as design risks requiring explicit contracts, not software acceptance. Effective payments preserve pending-void effect; zero delta is settled. REJECT finding 6's proposed opening-stock implementation because D2 defers it. Decline further question about negative reversal: baseline already rejects it. Rounding/opening questions are settled, not pending. Disposition sent to Peer. Further write delegation waits for accepted shared inputs.

## Environment evidence

Initial assessment observed host PHP 8.4.24 with BCMath, mysqli, DOM, mbstring; Composer, Docker daemon, MySQL client, Node 22 and Chromium available. At that checkpoint no WordPress instance or harness existed. This is superseded by the local Docker evidence below; no ERP workflow acceptance is implied.

## Local Docker candidate — 2026-10-19

Requirement: local development, testing and demo platform only; no external deployment. Lead owns all files, no policy implementation dispatched. Base: initial SPEC-only snapshot plus Lead's arithmetic/helper and preliminary Compose setup from this session; no commit created.

Changed/added runtime/config files: `compose.yaml`, `.dockerignore`, `docker/Dockerfile.tools`, `scripts/local.sh`, `scripts/install-local.php`, `scripts/health-local.php`, `scripts/smoke-local.php`. Docs: `README.md`, `docs/local-environment.md`, this status file and SPEC §12.1. Existing `.gitignore` excludes `.runtime`, `.cache`, vendor and test artifacts; existing composer.json/lock and phpunit.xml are used unchanged by Docker tooling. Generated WordPress/config/DB files are confined to project `.runtime`; Docker manages its own images/container metadata.

Exact executable candidate SHA-256:

| Path | SHA-256 |
| --- | --- |
| compose.yaml | cf125a15992a60f4921601773cf7d011db0f990314072da5f53704f421cd39ff |
| docker/Dockerfile.tools | 28c38607fab00f1f7d9665b4eb7918060cf4968d90d8cee1b04cc3f4b4e1851c |
| scripts/local.sh | 78165c416b211cb25295c56fb5dc1eda18facb52f8656c4db7b6128e13606541 |
| scripts/install-local.php | 8964895542a894dc77213a941e82f2c5210f020a28cb6878ffe938a1a56e4627 |
| scripts/health-local.php | e4ca5787714f0a5f08b8eace9e588000a62de4e426990d1b34d2cf109de61916 |
| scripts/smoke-local.php | 3eed87723a8aa57b46ee66d5470e01111fde220ee4b0aeeac25ae2fe921b23bd |
| .dockerignore | ad889c2170a9e87f577a843755aed482c364a184202115191a7c7603e920235f |

Verified:

- `docker compose config --quiet`, tools build and `bash scripts/local.sh start`: success, pinned images, loopback ports only. Tools image `sha256:b9db6ddfdbd1358914c90125dec086b31ad2fd172b68cdd1fbe489d312123ef6`.
- `health`: DB/WordPress healthy; WordPress 6.8.3, PHP 8.3.28, MySQL 8.0.45, BCMath true, prefix paxg_, local environment, Asia/Ho_Chi_Minh.
- Docker `test`: 27 tests / 38 assertions pass. Earlier RED was 7 missing-class errors and 20 failures before ExactAmounts implementation; host PHP also passed after implementation. Pure arithmetic demo: no external boundary applicable; tests cover the declared helper surface. No coverage percentage measured.
- Docker `smoke`: real HTTP login page → authenticated WP dashboard → logout → unauthenticated redirect, pass. No business data seeded.
- `stop` then `start`: success; same admin ID 1/site URL/timezone; login still succeeds. Persisted wp-config SHA-256 unchanged: `86b5868e7e16d841c13f3224ef568d6c8f6283894a7133574be2f529de158db8`.

Failed attempts retained: first install raced official image core-copy (missing compat.php); readiness now waits for core+DB+HTTP before installer. First HTTP smoke parser only accepted double-quoted links, whereas WP emits single quotes; parser fixed and smoke rerun successfully. Initial install emitted missing-sendmail warning; local installer now suppresses fixture email. These were failures, not accepted successes.

Technical decision: ACCEPT this candidate as the local Docker core/test foundation, not the internal v1.1 MVP. Usable downstream inputs: isolated persistent WP/MySQL, Docker PHP/Composer/PHPUnit, HTTP login smoke, reproducible start/stop/health commands. Limits: no activatable ERP bootstrap, migrations, role policies, business UI/workflows, invoice/PDF, concurrency/restore benchmarks or production security acceptance yet. Images are pinned historical test fixtures; no current-support claim. Fresh-machine image downloads, Docker Desktop, full browser visual QA and destructive reset/restore are unverified. Policy and invoice snapshot contracts remain unresolved as recorded above. Services are left running locally; no commit or deployment.

### Sales-order UI design prototype (2026-10-20)

Produced `docs/design/erp-orders-interface.md` SHA-256 `e9bca7c81281e1f3b875c40bf4936c90b0a3e64729c11eb2de41c943df19863d` and standalone `docs/prototype/orders.html` SHA-256 `3ecbf58267b135d421c1686268e160d93c16c9032a2f795ea51bb84449aafd91`. The prototype covers the sales-order list/detail workflow, sample draft creation/editing, client-side totals, status actions, filters, keyboard focus, and loading/empty/error/forbidden/success states. The updated detail pane displays ordered/shipped/remaining quantities and lets a user record repeated partial shipment documents with per-line quantities and history; data remains in browser memory and does not affect real stock. It is still a UI-only design artifact: no WordPress routes, persistence, capability checks, audit trail, or transaction integration; other module links are context only. No automated tests were run. Rendered browser review could not be performed because the browser tool rejected the local-file URL under its security policy; this is not visual acceptance. No broader ERP workflow milestone is accepted by this artifact.

### Practical ERP research and partial-fulfillment candidate (2026-10-20)

Reviewed official Odoo, ERPNext, SAP Business One, and Microsoft Dynamics 365 Business Central documentation; source-by-source mapping is in `docs/research/practical-erp-workflows.md` (SHA-256 `fcb74a87dfdc307fa8f5e5c3a8f07b12377cf24979e09bc5f074cb0cc537b4b5`). SPEC candidate SHA-256 is `d56e1a87786349adc3769c987f836986f7715bef5f7148b1782ae10ef13e1742`. Common patterns adopted in SPEC v1.1: sales order → one or more shipments → invoice each posted shipment; purchase order → one or more receipts → supplier-side internal bill tracking per receipt; ordered, fulfilled, and invoiced quantities remain distinct; posted facts are corrected through traceable reversal; permissions combine role capabilities with object/row/field scope. Returns/refunds, legal tax invoices, general ledger, and advance allocations remain out of MVP scope.

User-directed code candidate: `src/Domain/SalesOrderStateMachine.php` SHA-256 `a8229c66b984ddbbc1b61e02347a0229ef681d72e42f86df420967e538f290ba` represents partial shipment and Manager closure of a remainder; `src/Domain/PurchaseOrderStateMachine.php` SHA-256 `ea8823ff86a5f42a5f22489c9217663f6c011a87a142c887ff834e80ee9cb993` represents partial receipt, remainder closure, and rejected-order revision. No repository/service/schema/controller integration is present for these transitions, so they are not an accepted business flow. SPEC relations now model multiple receipts and shipments and enforce one invoice per posted source document.

Product rule chosen for v1.1: confirming a sales order does not reserve stock. UI must show on-hand as informational, never as available/free-to-use; shipment posting locks the balance and is the only hard inventory gate. Concurrent confirmed orders can therefore compete for stock. This constraint is recorded in SPEC §1.1 and §6.2 and should be revisited if reservation allocation is added.

This continuation updated SPEC, research/design notes, and the standalone order prototype besides the already-present state-machine candidates. No tests, browser rendering, plugin activation, schema migration, or runtime checks were run. The WordPress plugin still has no end-to-end order workflow. No milestone or candidate is accepted by this entry.

### Master data UI and audit slice (2026-10-20)

Following the practical ERP research patterns in `docs/research/practical-erp-workflows.md`, added real WordPress admin screens for customer, supplier, and warehouse master records. `PartyService` performs transactional create/update/deactivate operations and writes privacy-limited audit events in the same transaction. Existing records are deactivated instead of deleted; edits carry `updated_at` optimistic concurrency checks. `PartyController` adds server-side search/pagination, nonce-protected writes, duplicate-code feedback, form validation, and role-based screens. Sales can manage customers; Manager can manage suppliers and warehouses; Accountant can read suppliers; Warehouse users see only warehouses explicitly assigned to their Actor. Customer and supplier contacts are not copied into audit JSON. Shared admin styling now applies to ERP screens.

Changed paths and SHA-256: `src/Application/Services/PartyService.php` `62a79ceabdbfc83b4467b229400bb46095ce1f3f2de5f611189a7547c0365f16`; `src/Admin/Controllers/PartyController.php` `bca437b6c075f9639b8809150bf144bcbf8a112cad976b55bd804b5325d3285d`; `src/Bootstrap.php` `35f0298af9965ed69d865fab1714a8aa72a4defa073fdb995a3a048369fc068f`; `assets/admin.css` `80f366d271c0cca9dd844b000c4e87aa333ebe3326bbcacde7d40ac6f9ef8d43`; `docs/master-schema-contract.md` `3e7b3fa31f4097ff5ed125153a0e5f0756666e41c7de4f36ac37ec8c98e6e16b`. PHP syntax checks passed for the three PHP files. No automated, database, permission-projection, or browser-rendered visual checks were run for this candidate. The migration contract's stale ON HOLD/activation text was corrected: activation runs the first five master tables and append-only audit migration but does not stamp full schema v1.1.

This delivers master data maintenance only. The current UI lists customer and supplier records; it does not yet connect them to actual sales/purchase documents or enforce per-customer ownership beyond role capability. Warehouse scoped visibility is implemented for its master list but transaction-level scope is not yet exercised. No sales order persistence, shipment posting, inventory ledger, purchase receipt, invoice, payment, cash, reconciliation or end-to-end workflow is accepted. Next implementation target is persisted sales orders with partial shipment documents and atomic stock ledger updates, using the established product/customer/warehouse masters.

### Invoice workflow candidate — 2026-10-20

Following the documented ERPNext/Odoo/Business Central pattern of commitment → posted fulfillment → invoice → payment, added the next vertical slice to the existing sales/purchase implementation. `InvoiceSchema` creates immutable invoice header and line snapshots with one-invoice-per-shipment/receipt uniqueness. `InvoiceService` issues only from a posted shipment or goods receipt, derives all line amounts from server-side source snapshots, applies the sales order's discount/tax basis points, snapshots customer/supplier and company identity, validates the due date in Asia/Ho_Chi_Minh then stores UTC, and audits issuance in the same transaction. `InvoiceController` adds a role-scoped invoice list/detail, Accountant-only issuance, and nonce-protected HTML print generated from the stored snapshot. A separate ERP System Admin screen configures company name/address/tax ID; updates are transactional with audit, and affect only future invoices.

Changed: `wp-content/plugins/mini-erp/src/Infrastructure/Database/InvoiceSchema.php`, `src/Application/Services/InvoiceService.php`, `src/Admin/Controllers/InvoiceController.php`, new `src/Admin/Controllers/CompanyProfileController.php`, `src/Bootstrap.php`, and `assets/admin.css`. PHP syntax checks and scoped `git diff --check` passed. No database migration/activation, PHPUnit, MySQL workflow smoke, browser visual review, or PDF generation was run for this candidate. The invoice UI is HTML print only; protected PDF, invoice void, payment/cash ledger, and cash-account scope resolution remain open. This entry records a candidate, not a completed or accepted invoice milestone.

Continued with the ERPNext-style separation of invoice balances from money movement and bank reconciliation. Added `CashSchema`, `PaymentService`, `ReconciliationService`, and `CashController` plus a reconciliation screen. This candidate supports cash/bank account drafts with Manager maker-checker activation, account-scoped partial/full invoice payments, one atomic cash movement per posted payment or expense, effective pending-void amounts, three-step payment void with reversal (blocked after reconciliation), expense posting with a non-negative balance guard, and manual reconciliation that requires every in-period movement plus exact opening/closing balances before Manager close. Draft cash accounts are now assignable to a Manager so activation can occur; this fixed a workflow dead end caused by requiring ACTIVE accounts for all scope assignment. Invoice detail shows the all-account paid/outstanding aggregate, while payment detail and write controls remain restricted to assigned accounts. `ReconciliationService` interprets statement dates in Asia/Ho_Chi_Minh and stores/compares movement bounds in UTC.

Also added protected print/payment/expense/reconciliation routes, scoped admin pages, user cash-account assignment of DRAFT and ACTIVE accounts, and invoice VOID with reason/actor/time fields when no effective payment remains. Updated SPEC's invoice data dictionary. Added a schema-version gate so an authenticated WordPress administrator or ERP System Admin runs the idempotent migrations after a plugin update; this migration path itself has not been exercised. PHP syntax checks passed for the changed PHP files. No MySQL migration/transaction smoke, PHPUnit, HTTP/browser route test, PDF generation, visual acceptance, or end-to-end permission matrix was run. Remaining material gaps include protected PDF output, report/dashboard and stock adjustment UI, broader object-policy centralization, and actual rendered browser acceptance. Treat invoice/cash as implementation candidates pending integration checks, not completed milestones.

### Frontend ERP routing candidate — 2026-10-20

User decision: `/` opens the ERP app after login; all implemented ERP modules are available outside `/wp-admin/`; WordPress administration and initial ERP access setup remain under `/wp-admin/`. Added a frontend shell/router for dashboard, products, customers, suppliers, warehouses, sales, purchases, inventory, invoices, company profile, cash accounts, payments, expenses, reconciliation, ERP users, audit log, and reports. Existing controller links/redirects now return to the frontend root/module query instead of operational `admin.php` pages. Mutation forms continue to POST to `admin-post.php`; page access checks remain capability-gated, and technical WP Administrators are still not ERP Actors. Added responsive app-shell CSS, denial audit logging for router-level blocks, local installer activation of the plugin, and setup/SPEC documentation.

Changed paths include `src/Frontend/AppRouter.php`, `src/Bootstrap.php`, `src/Security/CapabilityPolicy.php`, the existing module controllers under `src/Admin/Controllers/`, `src/Security/UserAccessController.php`, `assets/frontend.css`, `scripts/install-local.php`, `README.md`, `SPEC.md`, and `docs/local-environment.md`. This entry is a routing/UI candidate only; do not infer that every listed business workflow is complete. No ERP account role was added to an existing user.

Verification: `bash scripts/local.sh start` and `health` pass; Docker WordPress/MySQL are healthy and the plugin reports active. PHP lint passed for every PHP file in `src/` and `scripts/`; Docker PHPUnit passed 65 tests / 4,168 assertions. Rendered browser check: `/` redirects an unauthenticated visitor to WordPress login; after login as the separate technical administrator, root and `/?erp_page=mini-erp-products` show the branded ERP no-role screen (403), without the WordPress admin toolbar, and provide the ERP access setup link. Following it opens `/wp-admin/users.php?page=mini-erp-bootstrap-users`; the WordPress bootstrap UI remains available. The local database already contains an enabled ERP System Admin test account, but its credentials are not documented. We did not sign in as, modify, or reset that account, so the authenticated ERP dashboard/module shell has not yet been browser-verified. Route/login/denial boundary is accepted for this candidate; business workflow, object-policy, and authenticated visual acceptance remain separate.


### Role-aware reports and protected invoice PDF continuation (2026-10-20)

Applied the documented ERP separation between booked sales, invoice receivables, stock-on-hand, and actual money movements. Added dashboard cards plus receivables aging, sales-invoice revenue, assigned-account cash book, and assigned-warehouse stock reports. Finance reporting defaults to active cash accounts explicitly assigned to the Actor; warehouse output is restricted to assigned warehouse IDs. Sales revenue uses issued shipment invoices and is labeled separately from collections. Report periods use Asia/Ho_Chi_Minh dates converted to UTC for ledger queries. Mixed stock units are not summed into a misleading quantity KPI.

Added shared invoice snapshot projection for HTML and PDF, a nonce-protected per-invoice PDF endpoint using Dompdf with remote assets, PHP, and JavaScript disabled, and the documented DejaVu Sans font for Vietnamese output. Invoice HTML and PDF use immutable stored snapshots; source-side display estimates use rounded SQL values and integer VND formatting. Plugin dependency installation is wired into the local start workflow; the packaged-plugin README documents Composer installation. Also clarified cash-account labels as cash drawer/bank account scopes and added responsive report/dashboard styling.

This continuation also fixed invoice read failures that could otherwise look like empty lists or surface an uncaught service error: invoice list/source/payment reads now fail visibly with a generic retry message, and document HTML/PDF endpoints return safe generic errors. Authorization and source ownership checks remain in the service layer.

Changed paths include `src/Application/Services/InvoiceDocumentService.php`, `src/Application/Services/ReportService.php`, `src/Admin/Controllers/InvoiceController.php`, `src/Admin/Controllers/ReportController.php`, `src/Frontend/AppRouter.php`, `src/Bootstrap.php`, `src/Admin/Controllers/CashController.php`, `src/Security/UserAccessController.php`, `mini-erp.php`, `assets/frontend.css`, `scripts/local.sh`, `scripts/test-reports-documents.php`, and `README.md`.

Verification: `bash scripts/local.sh reports-smoke` passes against isolated MySQL fixtures for revenue, Ho Chi Minh City aging, effective payment balances, assigned cash books, assigned warehouse stock, protected Vietnamese HTML/PDF output, ownership/role boundaries, and exact fixture cleanup. The existing suite passed 65 tests / 4,168 assertions; sales and inventory MySQL smoke workflows also passed. PHP lint and `bash -n scripts/local.sh` pass. Browser inspection confirms the branded frontend logged-in no-role denial screen and the WordPress access-assignment UI; the authenticated dashboard itself remains unverified because the available frontend session has no ERP Actor. No role was changed to force a visual pass.

Status: report/PDF workflows are an implementation candidate with isolated database smoke evidence, not production acceptance. Remaining work includes authenticated rendered review across real ERP roles, a complete role/object/field authorization matrix, backup/restore verification, and any deferred accounting, returns/refunds, or statement-import integrations. This build does not claim legal invoice or general-ledger compliance.


### Assigned-account cash vouchers (2026-10-20)

Human policy decision: users can access only cash/bank accounts explicitly assigned to their ERP identity. There is no role-wide account access or exception for an unassigned DRAFT account created by the current user. The cash-account list now applies that exact scope, reports read failures visibly, and explains that a draft is not auto-assigned. Manager can read expense rows and vouchers through `erp_reports_finance`; the page marks this view read-only and posting remains limited to `erp_expenses_manage`.

Added `CashDocumentService` as a shared immutable projection for payment and expense HTML/PDF vouchers. Payment vouchers require `erp_payments_view`, account assignment, and an invoice source that passes the existing invoice read policy. Expense vouchers require `erp_expenses_manage` or `erp_reports_finance` and the same account assignment. Both services require exactly one matching source movement and validate account, direction, amount, date, reference, and source identity before rendering. No separate editable voucher table or copied transaction payload was added. Void payment reprints show **ĐÃ HỦY**, approver, approval time, and reversal time only after the reversal movement and void fields agree. PDF generation uses Dompdf with remote assets, PHP, and JavaScript disabled. HTML and PDF links use authenticated `admin-post.php` routes, per-record nonces, no-store headers, safe filenames, and generic out-of-scope 404 responses.

The expense page is read-only for Manager and remains writable only for Accountant. Payment and expense lists now expose HTML/PDF actions for the current assigned-account rows. The invoice read-policy helper's source types were corrected to match persisted `SHIPMENT` and `GOODS_RECEIPT` records. Added pure `ReadPolicy` coverage for source type, Sales ownership, cash assignment and required capabilities; this does not claim SQL or HTTP-route coverage.

Changed: `wp-content/plugins/mini-erp/src/Application/Services/CashDocumentService.php`, `src/Admin/Controllers/CashController.php`, `src/Frontend/AppRouter.php`, `src/Bootstrap.php`, `src/Security/ReadPolicy.php`, `tests/Unit/ReadPolicyTest.php`, `scripts/test-reports-documents.php`, `scripts/test-cash-pages.php`, `scripts/local.sh`, `docs/read-policy-contract.md`, `docs/research/practical-erp-workflows.md`, and this status ledger.

Verification: `rtk bash scripts/local.sh reports-smoke` passes for payment and expense HTML/PDF, exact source-ledger matches, assigned/unassigned account boundaries, void reprint metadata, existing report scope, and exact fixture cleanup. `rtk bash scripts/local.sh cash-page-smoke` passes rendered CashController projections with temporary local Manager/Accountant actors: assigned-only account/payment/expense rows, computed balance, read-only Manager expenses, Accountant posting control, voucher actions, and no creator exception for an unassigned draft; temporary users and rows are removed. The broader local smoke sequence also passes `catalog-smoke`, `catalog-page-smoke`, `sales-smoke`, `inventory-smoke`, and `schema-smoke`; each reports isolated fixture cleanup. `rtk bash scripts/local.sh test` passes: 70 tests / 4,187 assertions. PHP syntax checks pass for every PHP file in plugin `src/` and `scripts/`; `rtk bash -n scripts/local.sh` passes. Browser check of `/?erp_page=mini-erp-expenses` reached the branded 403 because the available browser session has no ERP Actor. No browser account or assignment was changed; browser login, rendered visual review, and actual protected route downloads remain open.

Candidate source SHA-256:

| Path | SHA-256 |
| --- | --- |
| `src/Application/Services/CashDocumentService.php` | `04ad22050cacfbafdd12cefc1964a48dc987958614658febce9b4da84b4cdf02` |
| `src/Admin/Controllers/CashController.php` | `50db6a370ef775a6c0e555a137e37680aa52c478904fbd03f50ca3312051a682` |
| `src/Frontend/AppRouter.php` | `461f33b1f98e0b1476f76ff519d7d8aff3387395adcf2144c83da73093048ba0` |
| `src/Bootstrap.php` | `7c024e52706ad442f95cf6a14eb20e85b9dcf1f20778ebb66166c70bd8f6339f` |
| `src/Security/ReadPolicy.php` | `6c82a5c6d274bd9be8a319ae2699b8f6dd85d1f67175ba18f7ea2db3875c8c64` |
| `tests/Unit/ReadPolicyTest.php` | `e7e7807768948de2776cdd3fe5db56fbcefcbe6879f1ab471ee91c1b42b4a02b` |
| `scripts/test-reports-documents.php` | `676c02cb8a92a26628cf2aa4d6a5f00c89afc73563976b814f420002d6d95b06` |

| `scripts/test-cash-pages.php` | `e7a063c7639d18acd24ea144be53a22acebd37a4780374bff1926cd33b8a8747` |
| `scripts/local.sh` | `7adcf02388d2f6c0c97a6872fa471d46557cba1c8f22cabcdcc5cd6002e556a0` |

Status: candidate implementation with isolated MySQL/PDF evidence, not production acceptance. Remaining gates include full object/field/route policy tests, actual Manager/Accountant rendered review, payment/concurrency race evidence, backup/restore drill, and full business demo. Existing parent Desktop changes remain untouched; no commit or deployment was made.


### Warehouse actionable order queues (2026-10-20)

Aligned warehouse read policy with the workflow state machines and `SPEC.md` role matrix. Shipment queues now admit only assigned-warehouse `CONFIRMED` and `PARTIALLY_SHIPPED` sales orders. Receipt queues admit only assigned-warehouse `APPROVED` and `PARTIALLY_RECEIVED` purchase orders. Purchase list counts, rows, and detail queries apply the same boundary; draft, completed, and unassigned-warehouse orders return the generic unavailable detail response. Warehouse receipt detail omits supplier and unit-cost fields. No roles or capabilities were broadened.

Added policy tests for actionable states, assigned warehouse IDs, and all-orders Manager scope. Extended the rendered controller smoke with temporary Warehouse users and fixtures for every relevant state, hidden cross-warehouse records, detail IDOR attempts, and commercial-field projections. A first smoke attempt found the test fixture order number exceeded the schema's `VARCHAR(32)` bound; shortened fixture IDs and reran successfully, with temporary users and rows removed.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Security/ReadPolicy.php` | `0fbadceed96894505785c0d94c61c4615b09fabcc895bdfff8f7b17125b4d28c` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `f950e8f5c01bb628b45d41d7dc2de034e582eb1109170f5cd4cb65a4d07d009e` |
| `tests/Unit/ReadPolicyTest.php` | `d20ba1e8a885b163060132de919f8250c86d790a821c02d5028b3032ba13609d` |
| `scripts/test-cash-pages.php` | `467f6652f8bb66734017716008dd14df3c0f91be75872146beb5bcffee986947` |
| `docs/read-policy-contract.md` | `129eae5f0c5ab80288d63335e74a3bf20c4fc042a0036e9dfeabf47d9200c7a1` |
| `SPEC.md` | `1023d0fc2c6c73ecb812c698c5067f651e4aedfbc7baef81f45933105799333f` |

Verification: `rtk bash scripts/local.sh test` passes 77 tests / 4,221 assertions; `sales-smoke` and `cash-page-smoke` pass with exact fixture cleanup; PHP lint passes for the changed PHP files and `bash -n scripts/local.sh` passes. Browser review under real ERP roles remains unavailable in the current session. The broader read-policy contract is still unaccepted for blanket repository/route integration; M6 permission, restore, and authenticated visual acceptance remain open. This is a scoped authorization fix, not acceptance of all ERP flows.


### Authenticated local ERP UI check (2026-10-20)

Created a separate temporary ERP Manager user in the local WordPress site, enabled it, and logged into a new isolated browser context without changing existing users. The live `/` dashboard rendered the ERP shell, role navigation, invoice/receivables summary and explicit “not assigned” cash/warehouse states; it displayed no balance or stock total. The live Sales page rendered the empty-state prerequisite (“create customer, warehouse and product”) and all status filters. The temporary user was deleted by exact ID/login check, deletion was confirmed, and its isolated browser page was closed.

Evidence limits: these were read-only Manager screens with an empty business database, not a business transaction walkthrough. Browser resize requested at 390 px resulted in `window.innerWidth=500`, so the 390 px layout is not verified. Lighthouse and device emulation could not start because the available Chrome DevTools bridge returned a protocol error requiring a missing `offline` parameter. Authenticated Warehouse, Sales, Accountant and ERP System Admin visual flows, populated data views, and the rest of the responsive breakpoints remain open.

### Manager all-warehouse stock visibility correction (2026-10-20)

The authenticated Manager page above exposed a policy/UI mismatch: the dashboard said “Chưa được gán kho” even though the read-scope contract gives the Manager with erp_stock_adjust_approve all-warehouse stock visibility. Updated ReportService::stockOnHand() to apply the assigned-warehouse filter only when the Actor lacks that capability. The dashboard and stock report now label the Manager’s scope as all warehouses; actors without the global capability still require explicit assigned warehouse IDs. The report remains on-hand quantity and does not claim reserved/free-to-use availability.

Added a regression to the isolated reports smoke for an unassigned Manager across two warehouses and a Warehouse actor with no assigned warehouse. The rendered-page smoke now checks the Manager dashboard and stock-report scope, plus the Warehouse inventory screen’s assigned-only rows. A fixture cleanup issue found during that smoke was corrected to delete stock balances by their composite warehouse_id,product_id key. Two exact smoke fixture sets from interrupted runs were removed after checking their unique suffixes, and the temporary authenticated review user was deleted by ID/login guard.

Authenticated browser check after the fix: a temporary Manager account with no warehouse or cash assignments saw the dashboard card “Mã hàng trong tồn kho” with “Tổng hợp từ tất cả kho.”, not “Chưa được gán kho”; its stock report displayed “Tổng hợp tồn kho tại tất cả kho.” The local business database had no stock rows at this time, so the page displayed 0 rows; multi-warehouse row contents are covered by the isolated rendered MySQL smoke. The temporary user and isolated browser page were removed.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Application/Services/ReportService.php` | `45e22d55aea304352341461f09cf8bc131ca6f64b1bc12e4c8042773bcbd6ec1` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReportController.php` | `0d941d496b3a53d0f3171c89d345ac88b5630819847e95e581f823365a53bbaf` |
| `scripts/test-reports-documents.php` | `1d68f19eb4878e1088174994808ade27618ad6f3c95d898a00fcb66ff8b3ddaf` |
| `scripts/test-cash-pages.php` | `e531a11cde1b1aaa1686413db7cd0d2482871856c75acae3a02a7951181edf76` |

Verification: reports-smoke and cash-page-smoke pass with exact cleanup; PHPUnit passes 77 tests / 4,221 assertions; PHP lint passes for all four changed PHP files. The full permission matrix, restore drill, mobile viewport and authenticated review of other roles remain open. This closes the Manager stock-scope mismatch only and does not accept M6 as a whole.

### Per-user role and scope assignment UI (2026-10-20)

Replaced manual comma-separated numeric scope IDs in the ERP System Admin page with native multi-selects labeled by warehouse code/name/active state and cash-account code/name/type/status. The options show no balances or ledger data. Server-side assignment still validates the role, target, nonce, scope IDs, account state and audited transaction. ScopeAssignmentIds now accepts native form arrays as well as the existing comma-separated string format so older callers fail neither open nor closed unexpectedly.

The authenticated save redirect now returns to the AppRouter parameter erp_page=mini-erp-users, allowing the success/error notice to render on the correct page. A live browser POST as a temporary ERP System Admin assigned one active warehouse and one DRAFT bank account to a separate Manager, enabled the user and reached “Đã cập nhật quyền truy cập.” Database readback confirmed the exact role, enabled flag and both scope IDs; the temporary users, warehouse, account and all audit rows created by that test access were then removed by exact identity/action checks. Existing accounts were not touched.

The rendered cash-page smoke checks readable options, selected existing assignments, DRAFT account availability and absence of balance data. Unit tests cover multi-select arrays, duplicate removal, empty scopes and malformed/oversized input. PHPUnit passes 78 tests / 4,229 assertions; reports-smoke and cash-page-smoke pass; PHP lint passes for all changed PHP files.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Security/UserAccessController.php` | `d89771d65b6eb20fab277f564824dca2551df85c87590b7eb7e9f9c041740a53` |
| `wp-content/plugins/mini-erp/src/Security/ScopeAssignmentIds.php` | `374b42ad3d568052a52e186cf3325902d0f605f1504ccd7c4b9d30251d862cde` |
| `tests/Unit/ScopeAssignmentIdsTest.php` | `dc3e4bcf14316cd58c0fe724439f27c003f65f6ed6219785a0f9a731d0068d28` |
| `scripts/test-cash-pages.php` | `d08d432e6ac5d42beebe9f1984f11fc7f85c6ba4083a7150d14de0598605c92a` |
| `docs/read-policy-contract.md` | `16f6322233d5f3a5111d8c32e610f5f6c9fea7d89f314acdbfaff036fc56abcb` |

Limits at the time: the user-access page still showed at most the first 100 WordPress users; the follow-up below resolves that cap. A complete route/object/field matrix, restore drill, all-role visual walkthrough and verified mobile viewport remain open. The assignment evidence does not establish blanket authorization acceptance.

### Follow-up on the operational review findings (2026-10-20)

The audit at docs/audits/2026-10-20-system-review.md is historical. Later code and targeted evidence cover its eight bounded findings:

| Finding | Current evidence |
| --- | --- |
| F01 — closed-period cash mutation | reports-smoke rejects backdated payment, expense and reversal postings after a closed reconciliation |
| F02 — non-empty assignment persistence | authenticated System Admin form POST saved role, enabled state, warehouse ID and DRAFT bank-account ID; rendered smoke preserves existing selections |
| F03 — expense screen fatal | cash-page-smoke renders Manager read-only expenses and Accountant posting controls |
| F04 — integer PHP form keys for counts | InventoryCountInput unit tests accept integer keys and zero counts; inventory-smoke passes count/adjustment invariants |
| F05 — uppercase state filters | cash-page-smoke renders Sales and purchase results narrowed to the selected uppercase status |
| F06 — records beyond the first 100 | cash-page-smoke exercises older payment, invoice, shipment and receipt pagination/action routes |
| F07 — migration error handling/readiness | Bootstrap imports global Throwable and registers schema upgrade/readiness guards; schema-smoke passes idempotency and incompatible-schema checks. Forced Bootstrap migration failure remains untested |
| F08 — invoice pricing snapshots | reports-smoke verifies allocated line subtotals/rounding, rate snapshots and repeatable legacy migration |

Current verification also passes sales-smoke, inventory-smoke, schema-smoke, catalog-smoke, catalog-page-smoke and HTTP login/dashboard/logout smoke. A real two-worker MySQL race between payment posting and reconciliation close now passes in both lock-winner orders; this covers only that pair of operations. Sales and purchase draft forms now render all saved rows up to the 20-line business limit and support adding/removing rows. These results close the individual reproduced defects within tested paths; they do not establish a complete ERP acceptance. Remaining review work includes expense/void/match race permutations, full route/object/field authorization matrix, backup/restore drill, real-browser review for every role and populated workflow, preserving field input for other validation errors, and a verified mobile viewport. The broad read-policy contract and every M1–M6 milestone remain unaccepted.

### Payment posting versus reconciliation close race (2026-10-20)

Ran the actual `PaymentService::post()` and `ReconciliationService::close()` on two worker connections against MySQL 8.0 `REPEATABLE-READ`, with a third connection holding the assigned cash-account row lock until both workers were observed waiting. Each run used a unique `paxg_test_race_<10 hex>_` schema prefix, then dropped and verified all fixture tables.

- Close acquired the account lock first: the batch closed, the payment was rejected by the closed-period guard, and no payment or movement was written.
- Payment acquired the account lock first: the payment and movement committed, close rejected the incomplete/mismatched ledger, and the batch stayed DRAFT.

Both serialized outcomes passed. No business or transaction code changed. This does not verify `postExpense()`, `applyVoid()`, reconciliation matching, deadlock retry, or unrelated account-level workflows. The temporary harness lived only in the disposable WordPress container and was removed; retain these as targeted runtime evidence, not a maintained regression script.

### Multi-line sales and purchase draft editors (2026-10-20)

Updated both draft forms to show at least five rows and every saved row up to the service limit of 20. Added accessible add/remove controls, a live row counter, and a responsive grid. The editor keeps a selected existing product visible even if it is inactive or falls outside the first 500 active-product choices; inactive products are labeled so the user can replace or remove them. A corrupted draft with more than 20 rows fails closed instead of rendering an incomplete edit form. Without JavaScript, existing rows remain visible and submittable; add/remove controls stay hidden.

The frontend router loads the small row-editor script only on Sales and Purchase pages. PHP and JavaScript syntax checks pass. At the time of the initial change, automated tests and browser interaction had not yet been run; the follow-up below supersedes that verification status. Mobile visual behavior and end-to-end saves of a 20-line draft remain open.

### Multi-line editor verification follow-up (2026-10-20)

Added `scripts/test-order-editors.php` and the `order-editor-smoke` command. Against the isolated local WordPress/MySQL site, the smoke creates temporary master records and a Manager, renders both Sales and Purchase editors for saved 20-line drafts (including a selected inactive product), verifies all rows and the explanatory inactive label remain present, then verifies a corrupt 21-line draft fails closed in each editor. The script removes its temporary records and user in `finally` cleanup.

Authenticated browser interaction on the local frontend exercised the real editor JavaScript on both pages: each starts with five lines; Add reaches exactly 20 and disables; Remove reduces to 19 and renumbers; Add restores 20 with a blank product and price/quantity inputs. The temporary browser context was closed. Exact-ID cleanup was verified: the temporary Manager and all five master fixture rows are absent. This checks the editor controls and rendered row boundary; it does not submit a 20-line order or establish the full sales/purchase business workflow.

Verification: `rtk bash scripts/local.sh order-editor-smoke` passes; `rtk bash scripts/local.sh test` passes 78 tests / 4,229 assertions; PHP lint, `rtk bash -n scripts/local.sh`, and `node --check wp-content/plugins/mini-erp/assets/order-lines.js` pass. The available browser resize/emulation controls did not establish a mobile viewport, so mobile visual review remains open.

Follow-up hashes:

| Path | SHA-256 |
| --- | --- |
| `scripts/test-order-editors.php` | `11dbb007644c188af657e82fc59a4c94219cd16364f943f9fc564d61ef150181` |
| `scripts/local.sh` | `16d53bee9b4c042a55c27ef10bd8179b9cb1323a93bb946cf071bc73b45d12ff` |

### User-access pagination and self-row protection (2026-10-20)

Replaced the fixed first-100 user query in both WordPress bootstrap and ERP System Admin access screens with 50-user pages, a stable display-name/ID order, bounded page input, and accessible previous/next links. Queries exclude WordPress Administrators and the current account; the bootstrap screen retains its existing administrator-only gate, and the ERP screen no longer offers a self-edit form that its save handler already rejects.

The new `user-access-smoke` creates 101 temporary WordPress users and an ERP System Admin, then renders page 1 and page 3 of both screens. It verifies the final fixture user is absent from page 1 and available on page 3, page indicators/links are present, and the ERP actor does not appear as an editable row. Cleanup deletes every temporary account and checks that the fixture login prefix is absent. The smoke passes.

Verification: `rtk bash scripts/local.sh user-access-smoke` passes; `rtk bash scripts/local.sh cash-page-smoke` passes including assignment display/save coverage; `rtk bash scripts/local.sh test` passes 78 tests / 4,229 assertions. PHP lint and `rtk bash -n scripts/local.sh` pass. This closes the user-list pagination gap only; role/object/field route policy and all-role visual review remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Security/UserAccessController.php` | `bf3681424bb99ce75642c9d0413d73827cc60a02f129ce152918dae579250a47` |
| `scripts/test-user-access-pagination.php` | `47bd28bc3afa387782de77b53aa93835e125f9fb8c8dac5044f476ebba90a648` |
| `scripts/local.sh` | `0820c583b887ae4121a6d63c8308f114f72d948fffa581d7dcc75abfa2285bf8` |

### Sales and purchase form recovery after validation errors (2026-10-20)

Sales and purchase draft editors now retain entered controls in the current browser tab when the server rejects a submission for invalid, duplicate, or unavailable data. Recovery is scoped to the form type and current WordPress user, expires after 15 minutes, restores the expanded editor and line count, and does not place values in the URL or persist them on the server. Successful saves clear the recovery state. Failed edits return to the same draft editor when the saved order remains editable.

Authenticated desktop browser evidence as a temporary Manager: a real invalid Sales submission restored customer, warehouse, product, quantity, discount and note; a real invalid Purchase submission restored supplier, warehouse, product, unit cost, quantity and note. Correcting the Purchase quantity and saving created one draft; opening a fresh form showed empty controls and the recovery key was gone. The PO, item, audit event, temporary user and all five master fixtures were removed by exact IDs/codes and verified absent. No draft remained from the invalid Sales submission.

Verification: PHPUnit passes 78 tests / 4,229 assertions; `order-editor-smoke`, `sales-smoke`, and `cash-page-smoke` pass with fixture cleanup; PHP lint, `node --check` and `bash -n` pass. The browser evidence covers Sales/Purchase recovery on this desktop browser only. Browser storage disabled or full can prevent recovery, other ERP forms still need equivalent recovery behavior, and mobile visual acceptance remains open. This is a bounded form-recovery improvement; all M1–M6 acceptance gates remain open.

Current SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `16298c624ad2a2e45a4f43308cc1e2ba2574a53a14e82d9b91da0db19bbcc80f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `014348c8b185022eac795840f062a01a275446a853f9bab1318e3e9da6dae658` |
| `wp-content/plugins/mini-erp/assets/order-lines.js` | `b17095301a1d4eaaec1955e4ecffcd8455b3a72a3f171aa4089a218d7528ae29` |
| `scripts/test-order-editors.php` | `b40e21b751a0a2ff78c9608dc62deaf7bdfc35cac9cbbf5636443aad4939f534` |

### Reconciliation UI pagination and assigned-account detail scope (2026-10-20)

Removed the silent 100-row ceiling from the core reconciliation workspace. Assigned batch lists now count and page 50 rows at a time with stable period/ID ordering. A batch detail separately pages unmatched ledger rows by occurrence time/ID, so an account with more than 100 movements can still reach every row required to close an exact reconciliation. Both navigations preserve the current batch/list page, and queries remain constrained to the Actor's assigned cash-account IDs. Query failures stop with a service error instead of presenting an incomplete list as complete.

Extended the local cash-page smoke with 102 assigned reconciliation batches, 103 unmatched ledger rows, and a batch attached to an unassigned account. It verifies page 3 contains old assigned data, excludes the unassigned batch, renders the final unmatched movement and adjacent-page links, and denies direct detail access to a Manager with no account assignment. Fixture cleanup verifies both reconciliation rows and children are gone before removing accounts.

Verification: `cash-page-smoke`, `reports-smoke`, and PHPUnit (78 tests / 4,229 assertions) pass; PHP lint passes for the controller and smoke. This verifies pagination and account scope in rendered local WordPress pages, not simultaneous posting/close races or full role policy. The M5 milestone remains open, as do the broader route/field authorization review, restore drill, and all-role/mobile visual acceptance.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReconciliationController.php` | `cefdeca48cc94f9153674a6fc3fe0aa95317354fdd6fbbc5b6627514dd0aaa26` |
| `scripts/test-cash-pages.php` | `5fa1babb8989c9301f8d438c353a536a4530b647a459593499483e19acdb6977` |

### Reconciliation matched-movement history (2026-10-20)

Added an assigned-account-scoped table to reconciliation detail for movements already matched to statement references. Each row shows ledger date/source/direction/amount, external statement reference/amount, match time, and the matching actor ID. Both the count and rows require the movement to belong to the batch account and period. The matched-history list has its own stable `matched_at,id` ordering and 50-row pagination; navigating it preserves the unmatched-movement page, and vice versa. Query failures return a service error instead of silently presenting partial history.

The detail also previews current ledger opening/closing balances, statement-minus-ledger differences, and the count of movements still unmatched in this batch. The close action is offered only when the preview is exact, all movements are matched, and a different authorized actor is viewing; the POST service still rechecks under account lock. Unmatched rows are now defined relative to this batch, in line with the service close predicate. The batch, matched-history, and unmatched-movement tables use the responsive horizontal-scroll wrapper. Ledger and match timestamps render in the business timezone Asia/Ho_Chi_Minh and are labeled accordingly. No schema or reconciliation lifecycle behavior changed.

Verification: `cash-page-smoke` now passes against the actual local WordPress/MySQL fixtures for 102 matched rows, 103 unmatched rows, page-3 history, local-time conversion, balance differences, unmatched count, close-action gating, and assigned-account denial. PHPUnit passes 78 tests / 4,229 assertions; PHP lint passes for the controller and smoke. Browser visual review of this populated reconciliation detail has not been run, so the UI remains a candidate and M5/M6 are open.

Changed path and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReconciliationController.php` | `55e221e4800acabb5b0d1573d6c5ce25000b5dbf45901c45fed72861207e48d3` |
| `scripts/test-cash-pages.php` | `35994b4c6ede627ddedf2fbbf5607c9b4d1b0bf67b89b0ce75b9175bb0b8f6cb` |

### Invoice status and settlement controls (2026-10-20)

The invoice list and detail now label issued and void documents explicitly. A void invoice no longer renders a payment-posting form or a repeat void form; the payment service still rejects non-issued invoices as the authoritative guard. Added local controller-smoke cases for issued/void status, payment controls and the row's detail link. The smoke uses a temporary fixture and removes it with the rest of the isolated test data.

Verification: PHPUnit passes 78 tests / 4,229 assertions. HTTP login/dashboard/logout, schema, catalog, catalog-page, sales, inventory, reports, cash-page, order-editor, and user-access local smokes pass; every fixture-owning smoke reports its cleanup complete. Cash-page coverage includes issued/void controls and exact temporary-data cleanup; reports coverage includes assigned-account scope, closed-period rules, protected vouchers/PDF and invoice ownership/pricing. PHP lint and targeted whitespace checks pass for the changed PHP files. This is a targeted workflow correction, not acceptance of all roles or modules. Full route/object/field authorization evidence, backup/restore drill and real-browser review at desktop/mobile breakpoints remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `3a745c6cd9adee8e50a66a639712ced0cdf5e212e56cd50b9621733322a7fa01` |
| `scripts/test-cash-pages.php` | `c6f1ae93c302c81a8f0f60afe14d9fac94eceb09d4c9d0c2171ac133f32a6b7d` |

### Invoice and payment read scopes (2026-10-20)

Connected the invoice scope already stated in SPEC to the list, detail, print/PDF and payment-history reads: Manager/Accountant can read supported shipment and goods-receipt invoice sources; Sales can read only shipment invoices whose persisted sales-order creator is that actor; a missing scope resolves to no rows/denial. Payment history now applies both that invoice scope and the Actor's assigned cash-account IDs to rows and pagination counts. This implements the SPEC boundary and the user's selected account-assignment rule; it does not accept the wider draft contract as a blanket policy.

Verification in this continuation: PHP syntax lint passes for the four changed PHP files. Unit, database-smoke and authenticated-browser checks were not run in this continuation; route/query behavior still needs runtime coverage. All-role and mobile visual acceptance, broader route/field authorization, and backup/restore remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Security/ReadPolicy.php` | `e5c231e880370aeef36a6ef1884d85b21d9df03f13e560553c09b42c8aa13252` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `aad0b037cecd5d8582bdb3c7d92857c123ad374befff3ba5c8530fc470d72fae` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php` | `a7159edbe116e30064db530990523f59d9816b49ea176c6dba0b2aa0d2e46954` |
| `wp-content/plugins/mini-erp/src/Application/Services/InvoiceDocumentService.php` | `8884ced01a0640093341671fb20f27c619cd0c8d49fba445b6c4d715df8d555a` |

### Finance table controls and design alignment (2026-10-20)

Added scoped search, state/method filters, sortable columns and filter-preserving pagination to invoice, payment and expense lists. The count and row queries share the same validated filters and existing Actor invoice/cash-account scopes. Monetary amounts in these lists are right-aligned with tabular numerals. Updated the shared ERP surface to the supplied restrained design direction: neutral surfaces, 20px page titles, compact controls/tables, a sparing indigo accent, flatter borders and visible keyboard focus.

Verification in this continuation: PHP syntax lint passes for InvoiceController and CashController. No database smoke or authenticated browser review was run after these query/UI changes. Mobile layout and WCAG contrast still need rendered visual review; report cards and other business lists still need a module-by-module design pass.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `221bfaeb492d13e3809058d4ace6073009a69f728fb082cd211c366f675e8f2e` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php` | `3b64846fcf53294f2206790ae4e2d397c0bccae2d2bf77a8fc342d991b92d857` |
| `wp-content/plugins/mini-erp/assets/admin.css` | `7209b3be190538c9560d6c680570f49196c7f79d570ee297e787af8c9c2f3fc2` |
| `wp-content/plugins/mini-erp/assets/frontend.css` | `9d69ebf8f487d1af704b683a936c2b882c18adea0f8aa392397e871d3e06b213` |

### Finance form recovery (2026-10-20)

The user reconfirmed the finance visibility boundary: each user sees only cash/bank accounts explicitly assigned to that user, including balances and transaction history. Existing invoice/payment/expense/reconciliation/report queries retain that scope.

Payment posting now returns to the same invoice detail after success or a recoverable validation failure. Expense posting distinguishes its success notice from payment success. Payment, expense, invoice-void, reconciliation-create and reconciliation-match forms retain only an explicit field allowlist in the current browser tab for up to 15 minutes. Recovery is keyed by ERP actor plus the invoice, batch/movement or form; request keys are format-checked; select values are restored only when a matching rendered option exists. A live status message tells the user when data was restored. Account choices still come only from the actor's assigned active accounts. The saved values are browser-session data; no extra database persistence was added.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php` | `e910c918bdaca7ce0695e424e6d152f5a70ce70013d64f782c0eb32417715085` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `b06e9df22a1e33d6e957673109d13914a4134e184bdb74a4f1686e18f536000f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReconciliationController.php` | `a578f05b77336bd3abd2739a946cc0136bceda09936160dde92c7d31e0566efb` |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `56ce711a83698b030f07d17715f1f68bdd058283040aa4009d7d350ed1cf07b8` |
| `wp-content/plugins/mini-erp/assets/finance-form-recovery.js` | `a9880520cd0b0f75d7842fdcc4b1008eac4f885c1e2a16fb48690d04a25536c5` |
| `scripts/test-cash-pages.php` | `78884be1c2f7a7a9bd41b24dbb7028e9cfbbf8dcf3294cb0c6cfca49af293ff1` |
| `scripts/test-finance-form-recovery.js` | `d464fb8bd4fd495e46694e1ab3e2bb5697b6d5a0bf204d0622c449e890061648` |
| `scripts/local.sh` | `63a808935427443a6669e168ef89652ba6c97b7f367e620fabc2f5ed8df4662b` |

Verification: PHP syntax lint passes for all changed PHP files; `node --check` and `bash -n scripts/local.sh` pass. The finance form-recovery checks pass for actor/document scoping, field allowlists, request-key validation, account-option constraints, expiry and success cleanup. The isolated WordPress/MySQL cash-page smoke passes and confirms assigned-account visibility plus the payment, expense, void and reconciliation recovery markers; temporary fixtures were removed. No authenticated browser interaction was run after this change, so actual sessionStorage behavior and rendered visual quality remain unverified. If browser storage is blocked or full, submission behavior remains available but automatic recovery is unavailable. Inventory and master-data forms still lack equivalent recovery. M1–M6 acceptance, full route/object/field authorization, restore drill and all-role/mobile visual review remain open.

### Shared ERP application design layer (2026-10-20)

Applied a shared visual layer to the real frontend ERP shell and module pages: neutral canvas and surfaces, restrained indigo actions, consistent navigation and spacing, compact form controls, readable table headers/rows, clearer success/warning/error notices, visible keyboard focus, and smaller-screen horizontal table scrolling. Existing module markup, server authorization, and business workflows were left intact. Updated the sales-order design note to distinguish its in-memory prototype from the implemented WordPress routes and shared application stylesheet.

Verification: scoped `git diff --check` passes. No rendered desktop/mobile browser review or automated visual test was run in this continuation; actual breakpoints, contrast, and theme interaction remain unverified. This is a shared styling candidate, not visual acceptance or M1–M6 completion.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/assets/frontend.css` | `2dd2e905e0b470a347fe0ffc70751f30bb9d10034898bde70b6c9d406c04a3ff` |
| `docs/design/erp-orders-interface.md` | `aef08de76f4c1ea81b4d13dc6d4f63cf6b217b0d901f1d93bf612dbe8a491373` |

### Inventory form recovery (2026-10-20)

The stock-count start, count-edit, and approved-adjustment apply forms now preserve only their allowlisted inputs in the current browser tab for up to 15 minutes after a recoverable response. Keys are scoped by ERP actor and by the start form or adjustment ID; warehouse selectors restore only values still present in the server-rendered assigned options; dynamic count fields accept only `counts[positive_product_id]`; apply request keys are format-checked. Success clears state, including a successful count request where the editable form disappears. The UI restores values with an announcement and requires the user to review and submit again.

Verification: PHP lint passes for InventoryController and AppRouter; JavaScript syntax and the inventory form-recovery checks pass; `cash-page-smoke` passes with the inventory form identity markers and reports exact cleanup of its temporary users and rows; scoped `git diff --check` passes. The JavaScript test uses a DOM/sessionStorage mock, so real browser storage behavior and mobile rendering are still unverified. Inventory recovery is a candidate addition and does not establish the full workflow/security or visual acceptance.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InventoryController.php` | `4fe54da3bdbdd8d4417f56975a02ce1fd24e8db1622df31aa3979a174753212c` |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `bcd83ce6c663993a38c6a5c202f1af528e270e0d09a06efadeb5768a123b4e71` |
| `wp-content/plugins/mini-erp/assets/inventory-form-recovery.js` | `78ffe6de0e0bb079a32cf3fda257213340d19dc577d61459f37a13603e7c4469` |
| `scripts/test-inventory-form-recovery.js` | `e1c420b9be9b5c77653e6f7d36be88cc34de430ee522d2776cdcfaf3e4a33867` |
| `scripts/test-cash-pages.php` | `ad5b34ec4518ff7ea960e4b2579c6cdeddd60bd529bd31ccb8d7f2d7f61eea9d` |
| `scripts/local.sh` | `b922b1fbe173d470052c43c043090bf7748fec9abfc8c09f8fba8012b5bc7b2f` |

### Sales shipment and purchase receipt history (2026-10-20)

Sales order and purchase order details now expose the individual shipment/receipt documents behind their cumulative fulfilled quantities. Each history is paginated at 50 documents, groups lines by document, and shows the document number, Vietnam-local timestamp, actor ID, SKU, quantity, and unit. The queries are anchored to the already-authorized parent order; Warehouse receipt history intentionally excludes supplier and cost fields.

Verification: `cash-page-smoke` passes against isolated local WordPress/MySQL data, including shipment and receipt history pagination, line/document grouping, and the Warehouse no-supplier/no-cost projection. All temporary users and rows were removed. PHP lint passes for both controllers and the smoke script; scoped `git diff --check` passes. This verifies the rendered server pages and scoped data projection but not a rendered browser review, all-role policy acceptance, or concurrent fulfillment races. M1–M6 remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `db5464bc205635ae6a77ba69371ea01891b7389a0c775f00872e54a98a77815f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `e7f3f1b642c54fc8b70d62ca1e1e12ae7ee6f526ce13640c72801b63f45ee0a0` |
| `scripts/test-cash-pages.php` | `4cbd9a302748cd62536f811ea38e755198831204c4a36eb64012c6df7f22a5b7` |

### Master-data form recovery (2026-10-20)

Product, category, customer, supplier, and warehouse forms now retain allowlisted entered fields in the current tab for up to 15 minutes after validation, duplicate, conflict, or temporary database errors. Recovery keys are scoped by ERP actor, form type, and create/edit identity. The client restores only fields on the allowlist, accepts a product category only when it remains in the server-rendered options, consumes recovery once, and clears it after a successful save. Nonces, actions, record versions, and IDs are not stored. The form asks the user to review and submit again.

Verification: PHP syntax checks, JavaScript syntax, shell syntax, and scoped `git diff --check` pass. The Node storage mock passes allowlist, actor/form-scope, current-select-option, expiry, and success-cleanup checks. `master-form-smoke` renders real Product, Category, Customer, Supplier, and Warehouse forms on isolated local WordPress/MySQL; `catalog-page-smoke`, `cash-page-smoke`, and PHPUnit (78 tests / 4,229 assertions) pass. Temporary users and category/order/cash fixtures were removed. This does not replace a real-browser storage test or visual review; browser storage may be disabled/full, in which case submission still works without automatic recovery. All M1–M6 gates remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CatalogController.php` | `c74d9f8663ca6176a21debdbf6ee4d9f18c6c308c142a45904c89b45264c5779` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PartyController.php` | `60ad603360a21976638eb1eacb34ca1147ce63deab3843ec434f27ca3ef04347` |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `9c8450f3abf1c9053fbdb2f35f45263b11d7c0ac5364f08482da44714e3fcd40` |
| `wp-content/plugins/mini-erp/assets/master-form-recovery.js` | `62c26d7208b2d1a82208555120d523fc61dff759952a3bf7a44bd3b718f34ee2` |
| `scripts/test-master-form-pages.php` | `1862f60a45741ed98906e6538db0dcdaeb05cd6ff33577189821afa4675cf34e` |
| `scripts/test-master-form-recovery.js` | `db7dbe17cbae8aa8b0823b85349d59088cc9e92aa0d1ce6fc1f3c84f83d36a33` |
| `scripts/local.sh` | `dcfc92b8dd944466a3ce72e61f5b07e86442685c40a47176cbf1992f25d0bbf6` |

### ERP user-access form recovery and page preservation (2026-10-20)

The System Admin user-access form now keeps its submitted pagination page when a role/scope update returns an error, and carries actor, target user, and page identity on each row form. Allowlisted role, active checkbox, and selected warehouse/cash-account IDs are held in the current tab for at most 15 minutes, restored only to the same actor/user/page and only for options currently assignable on that page, then consumed once. Successful saves clear the stored values. Nonces and target IDs are not stored as form values; the cash-account selection preserves the explicit per-user account boundary.

Verification: PHP/JavaScript/shell syntax checks and scoped `git diff --check` pass. The Node storage mock passes actor/user/page isolation, role and ID allowlists, disabled-option filtering, expiry, and success cleanup. The isolated user-access pagination smoke passes with a 101-user dataset and confirms form identity plus page 3 preservation; temporary users were removed. PHPUnit passes 78 tests / 4,229 assertions. Real browser interaction/storage and full RBAC/IDOR policy review remain open; M1–M6 remain unaccepted.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Security/UserAccessController.php` | `ac1ebc6f7569158955893e0a5c8897c68d455eebddfe42fcf5d4c87977ec6d3d` |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `218e5c66200fd5790ab1ba4d5dfcc62d396514a2c32182339199fdbf68f28bee` |
| `wp-content/plugins/mini-erp/assets/user-access-form-recovery.js` | `5d682c4ec3c0261aeacbc93b800b39501abfaf2376eeb2e9dc209bfff2f8e037` |
| `scripts/test-user-access-form-recovery.js` | `8c6c0a38f6bf810689c182835d7c19835d67e897b829a7725d528e72d5307e6a` |
| `scripts/test-user-access-pagination.php` | `b4feba87d3ba2fcc9fbb3fd08661cf7fd9649740be669045335334e5147b2964` |
| `scripts/local.sh` | `c28640677fee5b9751ebdfbe5a33e370ed44324bb989810ee61366cc5cd0ad5f` |

### Company profile form recovery (2026-10-20)

The ERP System Admin company-profile form now participates in the same tab-scoped recovery flow. Company name, address, and tax ID are allowlisted; invalid and temporary save failures restore them for 15 minutes, and successful saves clear the saved values. The profile form remains restricted to `erp_settings_manage`; the real-page smoke uses a System Admin fixture to preserve that role boundary.

Verification: the isolated master-form page smoke passes for Product, Category, Company Profile, Customer, Supplier, and Warehouse forms with their correct actor identities, and removes its temporary actors/category. The Node recovery mock and PHP/JavaScript syntax plus scoped whitespace checks pass. This does not verify real browser storage or visual behavior. M1–M6 remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CompanyProfileController.php` | `17472f68c92cc0947cdf600ae9b177546e706f796dc05f8327ff9aa7bc08d4c8` |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `2ce8a0855059ed1dc188e100047ef3ab73d9b283382850da29ea96dd14be55e4` |
| `wp-content/plugins/mini-erp/assets/master-form-recovery.js` | `5d13d8451583acb6b4fc18c4d2a540a8a4b3adf4191b6443f22e3da07bbd6735` |
| `scripts/test-master-form-pages.php` | `27e9d01f53e8b46da3dc09500be5edafdf6bb7047311bba6e66b1ca0780a3852` |
| `scripts/test-master-form-recovery.js` | `e9619e4e18ab9ef38b6489eeff8997c4267b5513716d75d3f63bc22cf19e4f1d` |

### Role-scoped report CSV export (2026-10-21)

Added UTF-8 CSV downloads for receivables, assigned-account cash movements, issued-invoice revenue, and stock on hand. Each export uses an explicit field projection, preserves the report's owner/account/warehouse scope, caps output at 5,000 rows without silent truncation, neutralizes spreadsheet formula cells, and writes an audit event before sending a private no-store download. The report screens expose the export action and retain the selected cash/revenue date range.

Verification from the preceding implementation continuation: PHPUnit passed (81 tests / 4,236 assertions); `reports-smoke` passed isolated MySQL checks for export projections, Sales owner filtering, assigned cash accounts, warehouse scope, and BOM; `cash-page-smoke` passed the stock-report export action check. This does not prove an authenticated HTTP download/nonce round trip or rendered browser behavior. M1–M6 and the full route/object/field authorization matrix remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Application/Services/ReportExportService.php` | `640d468570517cffcb78de716d554db1430f6c87f1a76164f22d6be72ddfce03` |
| `wp-content/plugins/mini-erp/src/Infrastructure/CsvRenderer.php` | `45f86a393f78c524332d3e03c2de80b5be8b7c4e9aaec44cf085aaf1582998a0` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReportController.php` | `3d066504c4c309c21df4f16018dcd835f740b81be8a857b5849560cf858014a4` |
| `wp-content/plugins/mini-erp/src/Bootstrap.php` | `0d93b63d30de5f64421078e4ff5d17bb3a39d7d1ddf02b66f688842689f3415c` |
| `tests/Unit/CsvRendererTest.php` | `1e05a6735f8c2da4ed36cecf9c920ac53d90522e5e319e240f99a20c16619fe8` |
| `scripts/test-reports-documents.php` | `d2911e2044862d3007ae6e5258ac4fdc99867fc9e45a619595c4782c01100c2e` |
| `scripts/test-cash-pages.php` | `aceace9192c4b265235d899c1c4b0d071d52eb70ba3aec70c6038d2671748edf` |

### POST/Redirect/GET response consistency (2026-10-21)

Updated the ERP mutation, bootstrap-access, and user-access form redirect helpers to return HTTP 303 after POST, including validation and recoverable failure redirects. Login and admin-navigation redirects remain their existing GET behavior. A source scan confirms the remaining default-status redirects are only the login and GET navigation paths; no runtime or browser verification was run in this continuation. The broader HTTP matrix (403/404/409/422), all-role route coverage, and M1–M6 acceptance remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Security/UserAccessController.php` | `bb992e3976fd6620db6255b0eadbc3a6141747c335f221ba14a99c19b5851ef9` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CompanyProfileController.php` | `3a993bcd2930f1a9b53ac1c6bdd5814be64a002d609eef3d6acabda390c1a0ba` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CatalogController.php` | `af2f35a6188b3b6427b335e4c90e4ab7e6af0189d6e1fa0d493b3ab20154d9ef` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InventoryController.php` | `81e18c75e97ddf6396ae74af10c4cf91592f659e273da65207e746f62702b5cc` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `6b95445d5d6ea828a2588f6f643f09326e5379fcb75342594c5b4070a1666119` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PartyController.php` | `e169909c35e52369ebc581fdc72056dd13063e0fad711b87ec7c653f60a0e28c` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `19c4b22b9062fb0275198ff97b2eed4ad4c417a5b68958894f03099df8e72d9e` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReconciliationController.php` | `beb158acfef5e5c527c8a6674adf0d835e9758666554f251209504899444d49c` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `e73ac937de302e52bd80e4364d1609b94296fd43e9b6ffd4131ca9a4a5d1ca7f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php` | `56a191248233cd28acac63ee349203dbb2cce8201278aadf20d142f0f2804e34` |

### Authenticated order lookups and warehouse-aware stock preview (2026-10-21)

Added authenticated WordPress AJAX lookups for active products, customers, and suppliers. The endpoints require POST, action nonce and ERP capability, use bounded search/pagination, and return allowlisted projections. Product lookup exposes sale price only to Sales/Manager actors; an optional active warehouse returns current on-hand quantity only after warehouse-scope checks. Customer and supplier lookup return only ID, code and name. Sales/Purchase forms expose server-backed search while retaining the current selection. The Sales order editor passes the selected warehouse with product searches and refreshes rows with a selected product or active search when the warehouse changes. The label is “Tồn hiện tại”; order confirmation still does not reserve stock, so this preview is informational.

Verification: the isolated authenticated WordPress/MySQL AJAX smoke passes for active-product filtering, pagination envelope, warehouse stock projection, assigned-warehouse access, hidden Warehouse sale price, and customer PII exclusion; exact fixture rows and temporary users were removed. Supplier lookup and the order-editor wiring received PHP/JavaScript syntax checks only; the AJAX smoke does not cover the supplier endpoint. No authenticated browser interaction or order-save round trip was run, so supplier selection, warehouse-change interaction, and rendered UI remain unverified. The broader route/object/field matrix and M1–M6 acceptance remain open.

Research alignment: the order → shipment/receipt → invoice chain, partial fulfillment, and permission plus record-scope model follow the documented patterns captured in [`docs/research/practical-erp-workflows.md`](research/practical-erp-workflows.md). Current source references include [Odoo invoicing delivered quantities](https://www.odoo.com/documentation/17.0/applications/sales/sales/invoicing/invoicing_policy.html), [Business Central partial shipments](https://learn.microsoft.com/en-au/dynamics365/business-central/sales-how-send-partial-shipments), and [ERPNext role and user permissions](https://docs.frappe.io/erpnext/permissions). These are product-documentation findings, not a claim of runtime equivalence.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/LookupController.php` | `bcf3f5b256c707ff2899f57e35bb88ae0e703548fe34d3a27b46fb0725128dab` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `d919583077f9c8b4478171a71c5c4e70f7775f88315058514519bec4f3ec4c7f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `11e1369c972a32fbc5f36378c7660c437aa7614c8e2ae68ab8311f91b036936f` |
| `wp-content/plugins/mini-erp/src/Bootstrap.php` | `9d0dd4cd0e475b183c2ed0ce053f5ff848f28ef29b7c2e8de3d6a36971823831` |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `18c0c9bdafe9c8c3e64fab0e85ca41e95d41997377756f0db4eb41d14cd44304` |
| `wp-content/plugins/mini-erp/assets/order-lines.js` | `8c7040c0690ef0808685ba7f77a03ff8ba6ece774255d353a7c9c1a84f688a21` |
| `scripts/test-ajax-lookups.php` | `310100228a293647c2eb3cd3f647867e492b16e3befbb87e4435bec66ee6c138` |
| `scripts/local.sh` | `5b843a0744e9e240ca7d796648ef52109d8ef2b2db800bf5efe47cc90f5928f5` |

### Local database backup and guarded restore helper (2026-10-21)

Added `backup` and `restore <backup-name>` to the local Compose helper. Backups contain only `mini_erp_local`, are written through a private staging directory, and include a manifest, SHA-256 checksum, and gzip integrity check. Restore validates those artifacts, requires an interactive exact-phrase confirmation, stops WordPress, creates a fresh pre-restore backup, replaces only the local database, imports the selected dump, and runs the WordPress health check. Failure paths preserve the pre-restore dump and attempt to restart WordPress. The local environment guide documents usage and the InnoDB/DDL caveat.

Verification: `bash -n scripts/local.sh` passes. No backup or restore was executed, so database runtime behavior, import compatibility, and recovery from an interrupted import are unverified. No automated/offsite/encrypted schedule, WordPress file backup, retention policy, measured RPO/RTO, or monthly restore drill was added; SEC-12 and the go-live backup gate remain open. This does not accept M6 or any M1–M6 milestone.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `scripts/local.sh` | `8cf00b75a2dd1e2572ad5bc2c84b33cb0d8db391b0330f6a17444914b212743c` |
| `docs/local-environment.md` | `9a1030855fdcdc84fa21032934b3c6cb71d7ad0c08485d2d8e4a90b4d0a7fb5e` |

### Order, invoice, inventory, and reconciliation object-scope preflight (2026-10-21)

Sales and Purchase detail requests now run an object-scope preflight before the ERP shell is emitted. Missing and out-of-scope order IDs return the same generic HTTP 404 page; a database verification failure returns 503. The authorized minimal header projection is carried into the detail renderer so it does not issue a second unrestricted order-header query. Warehouse detail line queries omit sale prices, purchase costs, and line totals at SQL projection time; commercial roles retain those fields. Purchase workflow actions remain hidden for Warehouse and Accountant actors. Invoice detail requests check `ReadPolicy::invoiceScope` before shell output and return the same generic 404 for a missing or out-of-scope invoice; the protected print/PDF endpoints continue to enforce scope in `InvoiceDocumentService`. Reconciliation batch details verify assigned cash-account scope, and inventory count details verify assigned warehouse scope, before shell output. Sales/Purchase `edit` links now preflight draft state and editor scope: Sales users edit their own drafts, Managers retain the existing Sales draft-edit workflow, and Purchase drafts remain editable only by their creator.

Verification: PHP syntax checks pass for all six changed controllers and both smoke scripts. `order-editor-smoke` passes the 20/21-line editor cases and cross-owner draft preflight cases. `cash-page-smoke` passes the role-projection and assigned-account cases, including 404 preflight decisions for unassigned reconciliation batches and non-actionable Warehouse order details; both scripts clean up their temporary rows/users. These are local WordPress/MySQL controller smokes, not authenticated HTTP or browser tests. The full route/object/field authorization matrix, visual role review, and M1–M6 remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Frontend/AppRouter.php` | `02ded231b597192576fd5183e3d057bcb76c1c90cabd4c33677f54eed04f7303` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `ed67756f63e06fadd5523f924ae3a13651f067881021ed835682831f10ec885f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `aa120e19a37a541627f780f0c96595cba1001f28b742212c96c291073935f3e1` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `10cbd6fbb2a6c0e118775a24c38a42f23a4f9d45c92e403bca6fb05d9fa44a5a` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/ReconciliationController.php` | `7452fd0b2c63f8be24954b304fd4b557e5bc2ed9f87e3d0a8972a31703cf4d14` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InventoryController.php` | `3529f33513e2122be69fad6dab0bf296239c764af7220932c4b2923866268f2c` |
| `scripts/test-order-editors.php` | `7b55234064f24eab93fa357e188567cd5766b7c857ec053bc728a2926699a24a` |
| `scripts/test-cash-pages.php` | `537b200cfb2e215002408065e79fac71ff5cc505ee020bcb4096625feaf91839` |

### Sales/Purchase POST authorization and response contract (2026-10-21)

Sales draft save/update, workflow transitions and shipment posting, plus Purchase draft save/update, workflow transitions and receipt posting, now check the action capability before object lookup and perform a scoped target preflight before reading the remaining business fields. Missing and out-of-scope targets use the same generic 404; edit requests require a visible DRAFT and report stale draft versions as 409. Service transactions repeat the owner, warehouse, state, remaining-quantity and stock checks while holding their existing locks. `BusinessConflictException` and `RecordNotFoundException` preserve state-conflict versus hidden-object meaning through the HTTP layer. Form-field/domain validation returns 422, duplicate idempotency keys return 409, infrastructure failures return 503, and successful form posts retain 303. Order-editor recovery is bound to an explicit Sales or Purchase `recover_form` query marker so a failed shipment or receipt cannot restore stale fields into a new-order form.

Verification: PHP syntax checks pass for both controllers, both order services and the new exception classes; `node --check` and `git diff --check` pass. The full PHPUnit suite passed 81 tests / 4,236 assertions immediately before this change, not after it. No post-change database smoke, authenticated HTTP status check, or browser recovery interaction was run, so runtime response behavior remains unverified. This is a bounded Sales/Purchase response slice; all other ERP action families, the complete route/object/field matrix, browser acceptance, and M1–M6 remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `8318160865b30b58eb1327663fb6d292442386a852453aaa321b5ef03c8d5a0e` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `1b21aed86d202404cbc2e3a4adca9cf2f273366e80f3a01d5ebea88dce7dc7d0` |
| `wp-content/plugins/mini-erp/src/Application/Services/SalesOrderService.php` | `12fd0d3a45d6a1701231c87761fe17f14a966bf441b29f862d01d784fcd51dc6` |
| `wp-content/plugins/mini-erp/src/Application/Services/PurchaseOrderService.php` | `c877561796181d1d6832a31a430d6fafa642be74afddc140117f746be9b5266d` |
| `wp-content/plugins/mini-erp/src/Domain/BusinessConflictException.php` | `9bfe97242ddb6ca1c70c1af962670b75d95b11eb220cd7b61b81389cdcd0c086` |
| `wp-content/plugins/mini-erp/src/Domain/RecordNotFoundException.php` | `4f98154929c2fb3937f1fe1ba908969921c1c79809f998b5368a08ff76f2b69c` |
| `wp-content/plugins/mini-erp/assets/order-lines.js` | `845dc52247fd6926d7dfc3b5a790a73d7599d79e7fcde6ef189ef515c521f33c` |

### Assigned cash-account POST scopes, responses, and recovery (2026-10-21)

Cash-account activation, invoice payment, expense posting, and payment-void actions now check the action capability before object lookup. Account, invoice, and payment targets are preflighted through `ReadPolicy`; missing and unassigned targets share a generic 404. `PaymentService` repeats assignment, state, outstanding-balance, closed-period, and cash-balance checks inside its transaction. Correctable input returns 422, state or balance conflicts return 409, duplicate keys return 409, database failures return 503, and successful POSTs retain 303. Failure responses use a branded Mini ERP page with a safe return link; payment and expense forms restore their entered data after invalid, conflict, or service errors. The invoice detail screen displays the corresponding conflict/service notice. Finance entry forms now use a responsive field grid.

User choice recorded: a cash account is visible only when its ID is explicitly assigned to that user. This keeps role capability and record scope separate, consistent with the documented ERPNext role plus User Permission pattern. Research notes were updated in `docs/research/practical-erp-workflows.md`; advance payments, multi-invoice allocation, and other out-of-scope ERPNext features remain out of v1.1.

Verification: PHPUnit passed 81 tests / 4,236 assertions; `cash-page-smoke` passed assigned-account and role-projection checks; `order-editor-smoke` passed full-line, owner-scope, and fail-closed editor checks. Authenticated HTTP checks returned 403 for Manager expense denial, 404 for an unassigned cash account, 422 for invalid amount, 409 for insufficient balance, and 303 for successful draft-account creation. After the branded response change, a further authenticated request confirmed HTTP 409, the Mini ERP layout and stylesheet, and the recoverable return link. In the browser, the 409 reason rendered accessibly and returning restored category, amount, and description; desktop and 390×844 mobile form layouts were reviewed. Temporary users and account rows were removed and the local database count check returned zero. M1–M6, the complete route/object/field matrix, all payment/void/browser workflows, and full product acceptance remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php` | `f2b0398bcc4c525cec947393bb5b335d25e47962a31ea8dc29e3e2b7ff58517f` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `a9efe9c5f1be764c5e69dfb37f01c69cda94939e5aaa88dbae9ca3884e2ab538` |
| `wp-content/plugins/mini-erp/src/Application/Services/PaymentService.php` | `0600b704bc478094b8a7447bcf3c5ebcf78f8acb3711d3cf50a56fd4ac5fbe8e` |
| `wp-content/plugins/mini-erp/assets/finance-form-recovery.js` | `e6b75b509330e5837c4021820163935bf4aec047aa68a0e374076a419abff985` |
| `wp-content/plugins/mini-erp/assets/frontend.css` | `64fcb0231198170b0ec4572e3a9a19a1ccee3259bb2f9658c1df015c21b8ab3c` |
| `docs/research/practical-erp-workflows.md` | `49a37932d88c6e35d1eabd2d8c61c3ada716599d2197861de9ab9d1d2d801da2` |

### Schema readiness gate for ERP AJAX lookups (2026-10-21)

The bootstrap already blocked ERP frontend pages and `mini_erp_*` admin-post actions until the schema version is ready. Three authenticated lookup endpoints use the separate `erp_search_*` action namespace, so they were not included by that prefix check. `Bootstrap::isSchemaGatedAction()` now includes only the three registered ERP AJAX actions plus `mini_erp_*`, and `enforceSchemaForErpAction()` returns the same 503 readiness response for them. Unrelated WordPress actions remain outside this gate.

Verification: PHPUnit passes 83 tests / 4,246 assertions. `ajax-smoke` passes authenticated product/customer lookup, role projections and assigned-warehouse filtering. In the WordPress process, the smoke temporarily set the schema version to a pending marker and verified the ERP AJAX action hit the configured 503 handler while `profile_update` did not; it restored the original schema option and request action. The actual authenticated AJAX endpoints also passed with the schema ready and all fixture users/rows were removed. This does not reproduce simultaneous schema upgrades, missing-table faults or the complete admin action/REST route matrix; M1 and M1–M6 acceptance remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Bootstrap.php` | `11a520942e27eff672f93fafe3ec30dc39b08b5b6c641158fa511c698727d9e4` |
| `tests/Unit/BootstrapSchemaGateTest.php` | `e84c2bdfbe6c4bb9eccd2c1c1204e0a44c35ff718cc4afdf358abced5655a419` |
| `scripts/test-ajax-lookups.php` | `7570ad55c2853a760bde6e456c55fb53f3a0ac621630d42e1125b191563a7299` |

### Order detail links for invoices and scoped payments (2026-10-21)

Sales-order detail now lists each shipment and purchase-order detail lists each receipt, including sources that have not yet been invoiced. Existing invoice rows link to invoice detail; an eligible unbilled source gets a direct link to the existing issue form filtered to that exact shipment/receipt. `InvoiceController` keeps the filter within its already authorized all-source issue workflow. A payment-list shortcut appears only when the Actor has `erp_payments_view` and at least one explicitly assigned cash account; the destination re-applies its existing invoice and account scope. The invoice lookup on the sales side additionally applies the `own_sales` creator boundary from `ReadPolicy`; purchase invoices are shown only for the `all` invoice scope. Fulfillment rows share the existing 50-record page with shipment/receipt history. The UI uses the existing frontend table-scroll pattern and a scoped separator style, without adding a nested card.

Implementation candidate only: no tests, PHP lint, runtime smoke, or browser review were run for this slice. Recheck UI rendering, query failure behavior, own-sales visibility, purchase invoice scope, and payment filtering before accepting it. M1–M6 and end-to-end workflow acceptance remain open.

Changed paths and SHA-256:

| Path | SHA-256 |
| --- | --- |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php` | `41af4c3a60a3b6118c6b592b92f69588a7c766b213b2c4d5412b795ae21da468` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php` | `c19fbbd6f3236d9fac4d5876ff8e29142041d28a25f49b16c316d1b24aba66d2` |
| `wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php` | `8cacb9c4508a400df50cbb6df1c4a15aa73de8dc514e9aedcc839e16471dc338` |
| `wp-content/plugins/mini-erp/assets/admin.css` | `9e86d204b1cf70ac8ffe4f91a307b0d9a8429c8d09471096e263fc20fbe8b9d0` |

### One ERP System Admin and role-scoped screen access (2026-10-21)

The role policy now treats `erp_system_admin` as a singleton assignment. Both the WordPress bootstrap screen and the logged-in ERP user-access screen serialize role changes with a site-specific MySQL advisory lock and validate the assigned capability rows inside the existing transaction before granting that role. A second assignment is rejected with a specific message. The user-access screen keeps role, enabled-state, warehouse and cash-account changes available for other users; it disables a second Super Admin choice when one already exists. The WordPress core role dropdown hides all ERP roles so account changes go through the guarded ERP access screen with enabled-state, scope and audit handling. If old data already has duplicates, the screen warns the administrator and permits a duplicate account to be changed to a business role or no ERP role.

No existing account was modified automatically. The local site currently reports more than one System Admin, so one account still must be chosen and the other assignment removed through the user-access screen. WordPress technical Administrators remain the documented break-glass boundary and can bypass plugin policy through direct code or database administration.

The frontend router currently has renderers for the dashboard, products/categories, customers, suppliers, warehouses, sales, purchases, inventory, invoices, company profile, cash accounts, payments, expenses, reconciliation, ERP users, audit log and reports. The active System Admin sees only dashboard, company profile, ERP users and audit because business routes are capability-filtered; assign a business role to a separate account to use those modules. A route and renderer do not mean the module has passed full workflow acceptance: M1–M6, complete role/object/field coverage and cross-role rendered review remain open. A capability-protected ERP UI Studio is now available in `wp-admin`; it controls the application name, plain-text dashboard help, menu labels/order and supported dashboard cards while retaining permission checks for routes and data. It does not edit role capabilities, account scopes, required fields or business rules.

Verification for this slice: `php -l` passes on the new assignment guard and updated user-access controller. The actual user-access screen rendered the duplicate-assignment warning; browser checks at 320, 390, 768, 1024 and 1440 px found no page-level horizontal overflow. No automated tests were added or run, and no existing role assignments or business data were changed.

Follow-up ERP UI Studio (2026-10-21): Added `erp_ui_studio_manage` to the single ERP System Admin role and bumped the role-capability version to `2026.10.21.2` so active sites can receive the new capability. The `wp-admin` Studio edits the application name, plain-text dashboard help, navigation labels/order, and supported dashboard cards; route access and dashboard data continue to use existing capabilities and scopes. Malformed dashboard-card input is filtered to supported string keys. The Studio screen rendered in the authenticated local WordPress session; the ERP Users screen still rendered its save form and fixed business roles for another account after role synchronization. Browser checks at 320, 390, 768, 1024 and 1440 px found no page-level horizontal overflow. PHP syntax lint passes on the eight changed PHP files. No account form was submitted, no test suite was run, and the existing duplicate System Admin assignments remain unchanged pending the account-retention choice.

### Full responsive UI implementation (2026-10-21)

Applied the approved Framed Sheet system across the ERP app renderers. Every wide data table now has a bounded scroll wrapper; order, invoice, audit, report, reconciliation, and receipt/fulfillment detail tables remain horizontally scrollable, while short operational lists become labeled cards on phone layouts. A shared frontend enhancement adds table column scopes and labels, focuses keyboard scrolling regions, and renders text-backed status pills. Removed inline styles from list-row action forms, normalized their shared layout, and made master-data and finance-entry form controls fill narrow screens. The audit screen now uses the shared ERP sheet. Added token-based styling for WordPress login and password-recovery pages while retaining WordPress authentication and recovery handlers.

Changed paths include `assets/design-tokens.css`, `assets/frontend.css`, `assets/admin.css`, new `assets/login.css` and `assets/responsive-tables.js`, `src/Bootstrap.php`, `src/Frontend/AppRouter.php`, the table-rendering controllers under `src/Admin/Controllers/`, `src/Security/AuditLogController.php`, `src/Security/UserAccessController.php`, `docs/design/system-design.md`, and this status record.

Verification: PHP syntax lint passed for all changed PHP files; `node --check` passed for the shared responsive table script; `git diff --check` passed; no inline `style` attributes remain in ERP source. The authenticated ERP Users and company-profile pages were rendered and measured at 320, 390, 768, 1024, and 1440 px with no document-level horizontal overflow. Full business-role pages, the protected bootstrap-access screen, and the logged-out auth/recovery screen could not be browser-reviewed from this System Admin session without changing account state or logging out. No automated tests were added or run. M1–M6, workflow acceptance, and cross-role rendered review remain open.
