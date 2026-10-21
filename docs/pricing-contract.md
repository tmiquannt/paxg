# PRICE-1a — exact document subtotal and transparent allocations

Status: PRICE-1a pure calculator is accepted. Invoice snapshot persistence, migration, detail, and HTML/PDF changes are an implementation candidate with isolated MySQL/PDF smoke evidence; production acceptance and authenticated browser review remain open. Preserves Human decision D3: sum exact line values first, round the document subtotal once. Replaces the earlier tentative decimal-net equation; integer allocated line totals sum directly to grand total, never plus rounding a second time.

## Why the original line contract is insufficient

Two lines, each price 1 VND × quantity 0.500, have exact subtotal 0.500 each and document subtotal 1 VND. Integer-only line snapshots cannot preserve the two exact values. Independently half-up rounding each line would incorrectly produce 2 VND. Simply allocating integer discount to decimal net lines can also produce negative lines, so that earlier tentative allocation needs refinement.

## Snapshot representation

Persist each original exact value in `line_subtotal_exact_vnd DECIMAL(22,3)`. Retain integer `line_subtotal_vnd`, `discount_vnd`, `tax_vnd`, `line_total_vnd` as **allocated document amounts**, not independently rounded raw subtotals. Store signed `rounding_allocation_vnd DECIMAL(4,3)` on each invoice line; persist document `subtotal_exact_vnd DECIMAL(22,3)` plus signed `rounding_adjustment_vnd DECIMAL(4,3)` on the header. Invoice headers also store pricing algorithm version and discount/tax basis points for new documents.

1. Validate nonnegative integer prices within signed BIGINT, positive DECIMAL(18,3) quantities and unit steps using exact string arithmetic. Reject malformed types/exponents/floats and final totals outside signed BIGINT. Stable tie order is ascending immutable positive source-line ID (canonical integer string within signed BIGINT), not product ID or request order. Duplicate source-line IDs are invalid; duplicate product lines are allowed. No calculator-specific line-count limit is introduced. Controllers may later bound request size explicitly.
2. `raw_i = price_i * qty_i` exactly at scale 3. `raw = sum(raw_i)`. `subtotal = half_up(raw)` ONCE. Intermediate arithmetic is BCMath, not native PHP multiplication/float. The exact subtotal can exceed BIGINT maximum by less than 0.500 only when its rounded integer is still in range; DECIMAL(22,3) can represent it.
3. Allocate subtotal using largest remainder: start with floor(raw_i). Remaining whole VND = subtotal − sum(floors). Add one VND to that many lines in descending fractional remainder, ties by source-line ID. This is a document-wide allocation, not independent line rounding. `rounding_allocation_i = allocated_subtotal_i − raw_i`; sums equal `subtotal − raw` and each lies in (-1,1). Keep zero allocated lines; do not erase the exact raw snapshot.
4. Document discount = half_up(subtotal × discount_bps / 10000). Validate discount_bps 0..10000. Allocate that integer discount across integer allocated subtotals proportionally using largest remainder, ties by source-line ID. A zero subtotal uses all-zero discount. Each line discount ≤ its allocated subtotal.
5. Document taxable = subtotal − discount. Tax = half_up(taxable × tax_bps / 10000). Tax bps is a nonnegative integer string within signed BIGINT; do not impose an unapproved business-rate ceiling. Reject tax/final-total overflow. Tax is simple internal simulation, not a tax compliance assertion. Allocate integer tax proportionally to each line's integer net-before-tax using largest remainder. Zero taxable -> zero tax.
6. `line_total_i = allocated_subtotal_i − discount_i + tax_i`; total = subtotal − discount + tax. All stored integer amounts nonnegative and ≤ BIGINT max. Invoice service must reject zero final invoice total under SPEC §6.2 (`total_vnd > 0`); pure calculation returns zero for drafts and 100% discounts, without claiming issue eligibility.

The proportional allocation primitive computes floor(amount × weight_i / sum(weights)), then distributes remaining VND by descending exact division remainder, ties by source-line ID. Compare integer remainders, not decimal approximations; never loop once per VND. Remaining allocation is less than line count. No arbitrary or request-dependent floating rounding.

## Display contract

Each line shows quantity/unit price, **Giá trị chính xác**, **Phân bổ làm tròn chứng từ**, **Tạm tính được phân bổ**, discount, tax and final allocated line total. Document footer shows exact subtotal, one rounding adjustment, rounded subtotal, discount, tax and grand total. Example two 0.500 lines: exact [0.500,0.500], allocation [+0.500,-0.500], allocated subtotals [1,0], subtotal 1. Explanation: distribution only, not two independent rounding calculations. The footer adjustment is the sum of line adjustments, not added a second time to allocated line totals.

Snapshots freeze product identity/tie order, raw values, allocations, discount/tax rates, sums, party data and algorithm version `PRICE-1`; HTML/PDF use them unchanged. Accounting notes/account fields remain governed by read-policy contract. Any UI that labels allocated subtotals as exact raw multiplication is incorrect.

Canonical identities: `SUM(raw_i) + SUM(rounding_allocation_i) = subtotal_vnd`; `SUM(line_total_vnd) = subtotal_vnd - discount_vnd + tax_vnd = total_vnd`. The signed allocation is a display/reconciliation bridge, NOT an extra charge. Example exact [0.499,0.499], raw .998, document bridge +.002, rounded subtotal 1; allocated lines [1,0] sum 1 directly. Adding +.002 to those line totals again is prohibited.

## Invoice snapshot integration extension (2026-10-20)

New invoice headers persist `pricing_algorithm_version`, `discount_bps`, and `tax_bps`; new invoice lines persist `rounding_allocation_vnd` and allocated `line_subtotal_vnd`. New writes use the calculator's `PRICE-1` output. Detail, protected HTML, and PDF show raw exact line value, signed allocation, allocated subtotal, discount, tax, line total, document bridge, and saved rates. The document projection validates each line equation and the aggregate header identities before rendering.

The previous schema did not save discount/tax rates or an algorithm marker. Upgrade labels those documents `LEGACY`, leaves rates `NULL`, and shows that the rates were not saved in their original snapshot. It reconstructs allocated subtotal as `line_total_vnd + discount_vnd - tax_vnd`; the rounding bridge is that integer subtotal minus the exact decimal subtotal. Before backfilling, upgrade verifies every reconstructed amount is nonnegative, each bridge is within `(-1, 1)`, and all line sums match the immutable header. If any invoice cannot be reconciled, migration stops rather than inventing an amount. The migration uses the existing bounded invoice-schema lock and is repeatable.

This remains an internal-document snapshot contract. It does not assert tax compliance, statutory invoice status, or general-ledger compliance.

## Verification state

- [0.500,0.500] -> subtotal 1 (not 2); [0.499,0.499] -> subtotal 1 (not 0).
- Many sub-VND lines, including 499 × 0.001, sum/allocate correctly; no arbitrary calculator line cap.
- Stable source-line tie rule independent of request order; duplicate products allowed but duplicate source-line IDs rejected.
- Discount/tax allocations sum exactly to document totals, no negative net lines, zero-weight lines get no allocation, 100% discount works without division by zero.
- Exact decimal boundaries, document accumulation and subtotal+tax overflow fail safely; no float conversion.
- Property checks across many deterministic generated integer-milliquantity inputs: sum allocated subtotal == rounded sum raw; sum adjustments == doc adjustment; sum discount/tax/net == document values; nonnegative line nets; permutation invariance by source-line ID.
- Pure-calculator arithmetic, allocation, property, boundary, and overflow tests are accepted separately from this integration.
- Local isolated-MySQL smoke verifies PRICE-1 invoice issuance persists algorithm/rates and the `[0.500,0.500] -> [1,0]` line allocation; HTML shows signed allocations.
- Local migration smoke simulates the prior invoice schema, reconstructs only derivable line allocations, leaves absent rates `NULL`, and reruns migration without changing the recovered snapshot.
- Local PDF generation is checked for a valid PDF page tree. Authenticated rendered browser review of invoice detail and multi-page layout remains open.

The integration extension preserves the same document-wide arithmetic contract. Legacy migration details and failure behavior are recorded in the section below; no rate is inferred from mutable source documents.

## Bounded calculator API and original ownership record

`MiniERP\Domain\PricingCalculator::calculate(array $lines, string $discountBps = '0', string $taxBps = '0'): array` is static and side-effect free. Each line requires `line_id`, `unit_price_vnd`, `quantity`, `unit_step`; all inputs canonical decimal/integer strings, optional product identity is outside calculator scope. Validate using existing ExactAmounts helpers without editing them. Invalid input/overflow throws safe InvalidArgumentException. Empty document rejected. Output sorted by numeric line_id ascending:

- Header: `algorithm_version` = PRICE-1, `subtotal_exact_vnd` (3 decimals), `rounding_adjustment_vnd` (signed 3 decimals), `subtotal_vnd`, `discount_bps`, `discount_vnd`, `tax_bps`, `tax_vnd`, `total_vnd` (integer strings), and `lines`.
- Each line: `line_id`, normalized `unit_price_vnd`, `quantity` (3 decimals), `line_subtotal_exact_vnd` (3 decimals), `rounding_allocation_vnd` (signed 3 decimals), `line_subtotal_vnd`, `discount_vnd`, `tax_vnd`, `line_total_vnd` (integer strings).

Exclusive writer scope when dispatched: NEW `wp-content/plugins/mini-erp/src/Domain/PricingCalculator.php` and NEW `tests/Unit/PricingCalculatorTest.php` only. No ExactAmounts edit, schema/UI, TX files, Docker/config/docs or DB mutation. Use TDD; run targeted Docker PHPUnit file while TX scope is moving; Lead runs combined suite at stable handoffs. Return exact contract/base and file hashes, RED/GREEN/property results, limits and ownership release. Tests cover pure surface; no external demo applicable, persistence/PDF remain unverified. Reopen for any incompatible input-helper behavior or need to alter shared files.
