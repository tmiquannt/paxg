# POL-READ-1 draft — row scopes and readable projections

Status: Revised draft. Human decision accepted on 2026-10-20: a user can read only cash accounts explicitly assigned to that user. The broader read-policy contract remains draft and is not blanket acceptance of every proposed interface or channel. SPEC §3 and §8 remain baseline; deny by default and do not infer mutation permission from read permission.

Review disposition: initial hash 6fb8a90b7da4a3324a1b5cb5373bd9cbd4a5195f2bbfaa79d087760cf9a5774a was rejected as implementation input. The cash-account visibility question is now resolved: no role-wide account visibility and no creator-based exception for an unassigned DRAFT account. Other row/field/channel requirements still need implementation and evidence. Warehouse assignment itself is already required by SPEC. Source-code/plugin compromise remains a trust-root risk and cannot be fixed merely by relocating metadata into another table.

## Trusted identity and scope inputs

Identity comes from WordPress, never request DTOs. A disabled account, missing identity or conflicting multiple ERP roles is denied. Exactly one ERP business role is installed using WP APIs. Technical Administrator is a trust root, not a hidden routine business role. ERP SysAdmin gets users/settings/audit capability only, not finance or stock through admin status.

Trust-boundary clarification: every stored assignment is treated as untrusted data until schema/ID validation, target existence/active-state checks and actor binding pass. Generic user-profile/meta REST edits must not expose these keys. Only an authenticated SysAdmin action with users_manage + action nonce may update assignments; audit and transactional grant persistence are required before assignment UI is accepted. Metadata alone is not proof of authority. Final storage and atomic audit path belong to M1-ADM/schema contract; pure-policy tests receive a validated Actor from that boundary and cannot claim to have verified it. No mechanism claims protection from arbitrary malicious PHP/plugin execution with the site's DB privileges.

Actor context contains positive user ID, enabled flag, resolved capabilities, assigned warehouse IDs and assigned cash-account IDs. Scope lists are positive integer IDs from protected user metadata maintained by ERP SysAdmin with `erp_users_manage`, action nonce and audit. Missing/invalid lists mean empty, not all. No client-supplied `owner_id`, role, scope list or `can_*` flag is trusted. The existing assignment UI is in `Security/UserAccessController.php`; it does not make account creators implicitly assigned. Demo assignments must be explicit, not implicit wildcards.

Default role mapping for read lanes: Manager and Accountant have all-source-order/invoice read capabilities, but only assigned-account payment/cash/reconciliation detail; Sales has own-sales capability; Warehouse has assigned-warehouse queue capability. Capability checks are authoritative; domain services must not branch on role names. Read operations must reject an actor lacking the relevant resource capability even if a scope ID matches.

Cash-account decision consequences: the cash-account list, payment history, cash book, expenses, reconciliations, report aggregates and payment/expense vouchers must filter by the Actor's explicit `cashAccountIds` before reading or aggregating. A creator does not gain a hidden read exception for an unassigned DRAFT account. Manager may read expense rows/vouchers through `erp_reports_finance`; only `erp_expenses_manage` permits posting.

Assignment-screen exception: ERP System Admin may see the reference fields required to choose scopes: warehouse id/code/name/active state and cash-account id/code/name/type/status. This selector does not expose balances, movements, statements, external references or payment details and does not grant general business-data access. The form submits selected IDs; server-side existence, active-state, role, nonce, actor and audit checks remain authoritative.

## Row scope definitions

`own_sales` ALWAYS means persisted `sales_orders.created_by == actor.id`, not customer owner, invoice issuer, payment actor, URL parameter or current customer record. A purchase-source invoice never qualifies as own-sales. Relationships are resolved server-side through source foreign keys. Missing parent/source is denied, not an unscoped orphan.

| Resource | All-source read lane | Own Sales lane | Warehouse lane | Additional restrictions |
| --- | --- | --- | --- | --- |
| sales_orders + items | erp_sales_view_all: all orders | erp_sales_view_own: own orders | erp_stock_ship: assigned warehouse, status CONFIRMED or PARTIALLY_SHIPPED | Warehouse projection has no prices/totals/discount/customer contact |
| purchase_orders + items | erp_purchases_view: all | none | erp_stock_receive: assigned warehouse, status APPROVED or PARTIALLY_RECEIVED | Warehouse projection has no costs/totals/supplier contact |
| invoices + items | erp_invoices_view AND erp_sales_view_all: all sales/purchase sources | erp_invoices_view AND erp_sales_view_own: own-sales source only | none | Parent and source eligibility required on detail/print/PDF |
| payments | erp_payments_view: invoices readable AND cash_account_id assigned | no individual rows; invoice paid/outstanding aggregates only | none | Same rule on payment voucher and history AJAX |
| cash_accounts | erp_cash_accounts_view: assigned IDs only | none | none | No creator-based exception for an unassigned DRAFT account; no ledger until assigned |
| cash_movements | erp_cash_book_view: assigned account | none | none | Source links must be separately readable; do not leak source fields through joins |
| expenses | erp_expenses_manage or erp_reports_finance: assigned account | none | none | Manager may read via finance reports, not mutate |
| reconciliations + items | erp_reconciliations_view: assigned account | none | none | Each item parent and movement must have same account; orphan/cross-account denied |
| stock balances/movements/adjustments | Manager erp_stock_adjust_approve: all warehouses | erp_stock_view: active product quantity preview only | erp_stock_view plus warehouse-action cap: assigned warehouses | Sales cannot read stock ledger/audit or adjustments |
| audit_logs | erp_audit_view only | none | none | Finance role alone does not grant audit; access itself audited |

SysAdmin with no resource capability is denied in every business row above. Manager/Accountant broad invoice access does not grant broad cash-account access. All actors, including Manager/Accountant, need assigned account scope for cash detail, activation and reconciliation; mutation maker-checker/state guards remain separate.

Implementation alignment note (2026-10-20): warehouse order queues use only actionable states from the state machines. Purchase list and detail queries now apply the same assigned-warehouse and status boundary. This note corrects the draft's earlier status names; the broader contract remains unaccepted for blanket repository/route integration.

## Field groups: exhaustive allowlists, unknown fields dropped

No projection may serialize raw repository rows. These groups apply equally to HTML, JSON, CSV and print/PDF view-models. Aliases/derived fields must map explicitly to this list. All monetary fields retain string representation. Snapshot precision is consumed, not finalized by this contract.

- **Invoice header**: id, invoice_no, source_type, source_id, direction, party_id, seller_name, seller_address, seller_tax_id, buyer_name, buyer_address, buyer_tax_id, subtotal_exact_vnd, subtotal_vnd, rounding_adjustment_vnd, discount_vnd, tax_vnd, total_vnd, status, issued_at, due_at, template_version. Own Sales sees this same internal document header; no accounting note, issuing-user identity or internal approval metadata.
- **Sales invoice projection rationale**: explicitly the Invoice header + Invoice line + Invoice balance groups above and nothing else. Buyer/seller name/address/tax IDs are document identity already needed by Sales customer maintenance and own-invoice print in SPEC §3.3/5.4. This does not grant payment-row, bank/account, accounting-note, issuer or audit visibility. Finance may receive separate authorized payment detail projections; no role receives a raw combined invoice/payment repository row.
- **Invoice line**: id, invoice_id, source_item_id, sku_snapshot, name_snapshot, unit_snapshot, quantity, unit_price_vnd, line_subtotal_exact_vnd, discount_vnd, tax_vnd, line_total_vnd. Children inherit parent eligibility; `invoice_id` lookup alone never authorizes.
- **Invoice balance**: invoice_id, effective_paid_vnd, outstanding_vnd, payment_status, overdue, aging_bucket. Effective sum includes POSTED/VOID_PENDING/VOID_APPROVED. For an authorized invoice this sum covers all its effective payments even if some cash accounts are outside the reader's scope; it exposes no payment count, method, account, reference or actor. This follows Sales' expressly permitted aggregate balance, and preserves mathematically correct invoice outstanding.
- **Payment detail (account-scoped only)**: id, payment_no, invoice_id, direction, amount_vnd, cash_account_id, method, external_reference, paid_at, posted_at, status, created_by, void_reason, void_requested_by, void_approved_by, void_approved_at, void_rejected_by, void_rejected_at, void_rejection_reason, void_applied_by. Never request_key. No payment detail or voucher projection for Sales.
- **Cash account**: id, account_code, name, type, currency, opening_balance_vnd, status, created_by, activated_by, activated_at, created_at, computed_balance_vnd. No raw bank number, credentials or bank API data; these are not stored in P0. An assigned user may see an assigned DRAFT account without a computed balance; an unassigned creator receives no row.
- **Cash movement**: id, cash_account_id, source_type, source_id, direction, amount_vnd, occurred_at, actor_id, external_reference, created_at. No event_key. Source ID is internal document linkage only; loading source still requires its policy.
- **Expense**: id, expense_no, category, description, amount_vnd, occurred_at, recorded_by, cash_account_id, status. No request_key.
- **Reconciliation**: id, rec_no, cash_account_id, period_from, period_to, statement_opening_vnd, statement_closing_vnd, status, created_by, closed_by, closed_at, note, computed_opening_vnd, computed_closing_vnd, discrepancy_vnd, unmatched_count. Draft/CLOSED share read eligibility; CLOSED never implies edit permission.
- **Reconciliation item**: id, reconciliation_id, cash_movement_id, external_reference, external_amount_vnd, matched_by, matched_at, derived_direction. No source payload embedded.
- **Order commercial header**: id, order_no or po_no as appropriate, customer_id or supplier_id, warehouse_id, created_by, approved_by, status, discount_bps, subtotal_vnd, discount_vnd, tax_vnd, total_vnd, version, created_at, note. Only permitted persisted source snapshots; no customer/supplier contact join without a separate authorized projection.
- **Order commercial line**: id, order_id or po_id, product_id, sku_snapshot, name_snapshot, unit_snapshot, unit_price_vnd or unit_cost_vnd, quantity, line_subtotal_exact_vnd, line_total_vnd. Warehouse variant: id, order_id/po_id, product_id, sku_snapshot, name_snapshot, unit_snapshot, quantity only.
- **Order warehouse header**: id, order_no/po_no, warehouse_id, status, version, created_at. No creator, contact, note or commercial amount.
- **Stock preview**: warehouse_id, product_id, available_quantity. Preview is not a reservation. Warehouse stock record: warehouse_id, product_id, quantity, updated_at. Warehouse/Manager stock ledger: id, warehouse_id, product_id, direction, qty, before_qty, after_qty, source_type, source_id, actor_id, occurred_at. No movement_key.
- **Adjustment**: id, adjustment_no, warehouse_id, status, requested_by, approved_by, applied_by, reason, requested_at, approved_at, applied_at; child id, adjustment_id, product_id, expected_qty, counted_qty, delta_qty. No apply_request_key. No opening-count workflow.
- **Audit**: id, event_id, request_id, actor_user_id, action, entity_type, entity_id, result, before_json, after_json, created_at. Only server-allowlisted status/qty/amount/role values in before/after; no secrets/nonces/passwords/contact details. ip_hash not shown by default.

## Lists, derived data and request channels

The same scope must constrain repository queries BEFORE pagination, count, group-by, sum, search, join, export or autocomplete. Fetch-all-then-mask is not sufficient. Pure policy outputs a scope descriptor (`none`, `all`, `own_sales`, `warehouse_ids`, `account_ids`) and field projection; later repositories must implement it with prepared predicates and test real SQL. An empty ID list returns no rows, never omits the predicate.

Sales reports/receivables aggregate only own-source invoices; do not show the customer's unrelated invoices/overall balance. Manager/Accountant invoice revenue/aging can aggregate all readable invoices. Cash/expense/reconciliation totals aggregate assigned accounts only, clearly labeled scoped. Hidden-row counts, selector options, error messages and pagination totals must not disclose a wider dataset.

Filters are resource-allowlisted and must never widen scope. Sales invoice filters: invoice_no, source order number, own invoice party, status, issued/due date, payment_status/overdue; sort only invoice_no, issued_at, due_at. No account/actor/method/reference filter for Sales; reject unsupported/hidden filters with generic 422 before any data lookup. Finance payment filters: dates, invoice_no/party (readable), method, assigned account, actor, status, direction; external reference exact match if detail permitted. An explicitly requested unassigned account returns generic 404, not an empty-but-valid probe. Date/identifier values are validated independently of SQL. Page sizes 1..100, stable ID tiebreaker; bounded exports max 1000 rows per request with same scope and audit.

Known action without capability -> 403 before lookup. Authorized action with nonexistent OR out-of-scope object -> identical generic 404, no names/counts/references. Denied object must not be included in a redirect notice or SQL exception. Business-state errors (409/422) only after object authorization. Auth-denied audit contains attempted ID/action/result, no hidden field values. GET downloads require action nonce plus print/read scope and no-store/nosniff; missing/invalid nonce fails independently. Nonce is never scope authorization.

### Exact read-channel response groups

All AJAX endpoints require authenticated identity and their own action nonce; none has nopriv registration. JSON list envelope is `{items: [...], page: integer, page_size: integer, total: scoped integer}`. No hidden-row/global count or arbitrary `meta` object. AJAX errors use `{code: fixed_string, message: fixed_safe_text}`; HTTP status rules above. Search q max 100 Unicode characters. Only listed fields are returned; no accidental join fields.

| Channel | Capability and row scope | Item fields |
| --- | --- | --- |
| erp_search_products | erp_products_view; active products; stock quantity only for requested permitted warehouse | id, sku, name, unit, unit_step, sale_price_vnd, available_qty (omit if no warehouse requested) |
| erp_search_customers | erp_customers_manage; active customer lookup needed to draft sales | id, code, name (not phone/email/address/tax_id in lookup) |
| erp_stock_preview | erp_stock_view; Sales active warehouses/product availability; Warehouse assigned warehouses; Manager stock-read scope | warehouse_id, product_id, available_quantity; scalar response without unrelated counts |
| erp_invoice_lookup | erp_invoices_view and all-source/own-sales scope | id, invoice_no, direction, buyer_name, total_vnd, due_at, status, effective_paid_vnd, outstanding_vnd, payment_status, overdue |
| erp_payment_history | erp_payments_view plus invoice/account scope; deny Sales individual rows | Payment detail group, scoped total; invoice aggregate returned only via invoice lookup/detail |
| erp_cash_balance_preview | erp_cash_accounts_view plus permitted account | cash_account_id, computed_balance_vnd, currency; no other accounts |

Invoice HTML detail requires invoices_view plus source read scope. Invoice print/PDF require BOTH invoices_view AND invoices_print plus same source scope and action nonce; print cap alone never grants read. Payment/expense voucher additionally requires readable source payment/expense and permitted account; Sales cannot obtain it through invoices_print. Customer master edit/detail uses customers_manage; it does not grant all-customer invoice/history access. Supplier master edit uses suppliers_manage; source invoice identity is an independent immutable snapshot projection.

Export is an explicit bounded page of at most 1000 authorized rows with continuation and same stable sort; never silently return the first page as a complete export. BOM/formula escaping and export audit are later serializer/controller requirements, not proof from ReadPolicy. No hidden-field search/sort or filter can become an oracle even if returned columns are masked.

## Proposed pure-policy implementation boundary

Only after acceptance: `Security/Actor.php`, `Security/ReadPolicy.php`, `tests/Unit/ReadPolicyTest.php`. No roles installer, WordPress identity loader, SQL repository, controller, mutation service, schema, audit logger, or Docker edit in this slice. These other layers stay unimplemented, not implicitly covered by pure policy tests.

Actor immutable constructor with id, enabled, capabilities, warehouseIds, cashAccountIds. ReadPolicy methods `scope(Actor, string resource): array`, `allows(Actor, string resource, array record, array trustedContext = []): bool`, `project(Actor, string resource, array record, array trustedContext = []): array`; unauthorized project throws a safe domain exception. Resource/context naming and exact descriptor structure must be frozen in a follow-up accepted revision before writable dispatch. Trusted context contains actual source order/parent, never request-asserted ownership. Unknown resource/context missing required relation -> deny.

## Required evidence and reopening

POL01 Sales A vs B source and invoice-child IDOR; POL02 purchase invoice denied Sales; POL03 exact field projections (sentinel hidden fields dropped); POL04 no Sales payment/voucher/cash/audit; POL05 account/warehouse empty and cross-account/child mismatch denial; POL06 correct capability plus scope both required; POL07 disabled/default-deny; POL08 same descriptor scope for lists/counts/totals/export/AJAX; POL09 missing parent fails closed; POL10 paid aggregate permission is distinct from payment detail. Unit coverage is necessary but route/repository tests remain required downstream before any end-to-end authorization acceptance.

Independent challenge questions: Does this preserve SPEC intent without adding a business module? Are the explicit per-user account assignments implementable without circular prerequisites? Does any field, aggregate or child reference bypass scope? Identify any missing exact interface/schema dependency. Do not assume the pure policy slice proves SQL or HTTP authorization.
