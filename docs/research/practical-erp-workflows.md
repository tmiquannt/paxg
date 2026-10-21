# Research: practical ERP workflows for Mini ERP

**Date:** 2026-10-20  
**Purpose:** Compare documented workflows in Odoo 18 and ERPNext with this repository's Mini ERP v1.1 SPEC, then recommend concrete implementation and SPEC changes.  
**Evidence boundary:** This is documentation research. It does not inspect or verify vendor runtime behavior, and it does not establish that the local Mini ERP implementation works.

## Executive summary

The official documentation for both Odoo and ERPNext describes ERP flows as linked business documents, rather than a single form that changes several unrelated facts at once. The recurring chain is order/commitment → physical delivery or receipt → invoice → payment. Odoo describes a quotation becoming a sales order before delivery, invoice, and payment; ERPNext documents Sales Order → Delivery Note → Sales Invoice → Payment Entry and Purchase Order → Purchase Receipt → Purchase Invoice → Payment Entry ([Odoo sales flow](https://www.odoo.com/documentation/18.0/applications/sales/sales/sales_quotations.html), [ERPNext selling](https://docs.frappe.io/erpnext/selling), [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry)).

Mini ERP already follows much of this separation in its SPEC: approved orders precede stock movements, invoices snapshot completed source documents, payments create cash movements, and corrections use reversals. The user requested adopting practical ERP workflows, so this follow-up promotes partial shipments and receipts into v1.1. Returns and general-ledger accounting remain out of scope.

## Systems and source scope

The comparison uses official Odoo 18 documentation and current ERPNext documentation published by Frappe. The sources below describe product behavior and data concepts, not independent audits, legal accounting advice, or a claim that every deployment uses the same configuration. ERPNext documentation includes configurable alternate flows (for example, combining stock receipt and invoice); the recommendation is to adopt the control boundaries that fit Mini ERP, not to copy every feature.

## Recurring workflow patterns

### Sales: commitment, fulfillment, billing, settlement

- Odoo describes the typical sale as quotation → accepted sales order → delivery → invoice → payment. It notes that businesses can choose whether invoicing is triggered by the sales order or by delivered quantities; invoicing delivered quantities is useful where an order may not be fulfilled all at once ([Odoo quotations](https://www.odoo.com/documentation/18.0/applications/sales/sales/sales_quotations.html), [Odoo invoicing policy](https://www.odoo.com/documentation/18.0/applications/sales/sales/invoicing/invoicing_policy.html)).
- ERPNext calls Sales Order the commitment, Delivery Note the stock delivery, Sales Invoice the receivable, and Payment Entry the receipt; its standard sequence is Sales Order → Delivery Note → Sales Invoice → Payment Entry ([ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [ERPNext Sales Order](https://docs.frappe.io/erpnext/sales-order)).
- ERPNext states that a submitted Delivery Note creates stock ledger entries and updates stock; the Delivery Note is normally made against a Sales Order ([ERPNext Delivery Note](https://docs.frappe.io/erpnext/delivery-note)).

**Application to Mini ERP:** SPEC §5.1 and §5.4 separate sales order, shipment, and invoice. Invoice only quantities from each posted shipment; a subsequent shipment has its own invoice eligibility. Adopt the established distinction in naming and UI: the order is the commercial commitment; shipment is proof of goods leaving; invoice is the amount due. The current SPEC omits a separate quotation stage. Draft order remains an internal pre-confirmation; do not imply that a customer-facing quote lifecycle exists today.

### Purchasing: authorization, receipt, supplier bill, payment

- ERPNext distinguishes a Purchase Order as a commitment, Purchase Receipt as evidence that stock physically arrived, Purchase Invoice as the supplier payable, and Payment Entry as settlement. A stock receipt can be recorded before the supplier bill arrives; its documented accounting model uses a temporary “received but not billed” account until the invoice is recorded ([ERPNext Purchase Cycle Ledger Impact](https://docs.frappe.io/erpnext/purchase-cycle-ledger-impact), [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry)).
- ERPNext's Purchase Receipt documentation links receipt quantities to the source Purchase Order; its Purchase Order page describes receipt as the document that records delivered items ([ERPNext Purchase Receipt](https://docs.frappe.io/erpnext/purchase-receipt), [ERPNext Purchase Order](https://docs.frappe.io/erpnext/purchase-order)).
- ERPNext documents alternate cases: a supplier invoice can update stock directly when goods and bill arrive together, but combining them removes a distinct receipt document from the audit trail ([ERPNext Purchase Cycle Ledger Impact](https://docs.frappe.io/erpnext/purchase-cycle-ledger-impact)).

**Application to Mini ERP:** SPEC §5.2/§5.4 now supports multiple goods receipts and billing only from received quantities not billed before. Label purchase-side invoices as internal supplier-bill tracking, distinct from goods receipt and not a legal invoice. Preserve receipt and bill as separate events; leave vendor legal-invoice compliance outside the MVP.

### Inventory: movement ledger and available stock

- Odoo's stock report distinguishes on-hand quantity from “Free to Use,” defined there as on-hand quantity not reserved for delivery or manufacturing orders; it also reports outgoing and incoming/ordered stock separately ([Odoo stock report](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/warehouses_storage/reporting/stock.html)).
- ERPNext describes Stock Ledger Entry as the unified record updated by stock-affecting documents including Stock Entry, Delivery Note, Purchase Receipt, and POS Sales Invoice ([ERPNext concepts and terms](https://docs.frappe.io/erpnext/concepts-and-terms)).
- ERPNext documents an immutable-ledger approach: cancellation retains the original accounting/stock entries and creates corresponding reversal entries, because rewriting historical movements can alter later stock valuation and audit history ([ERPNext Immutable Ledger](https://docs.frappe.io/erpnext/immutable-ledger)).

**Application to Mini ERP:** SPEC §6.2 and §7 call for stock balances plus append-only movements and transactional receipts/shipments. The v1.1 decision is no reservation at sales-order confirmation: display on-hand as informational, then lock and hard-check stock while posting each shipment. Do not present the on-hand value as available/free-to-use. This accepts that a confirmed order can wait if another shipment consumes stock first.

### Invoices, payments, cash, and reconciliation

- ERPNext distinguishes invoices (amount due) from Payment Entry (money received, paid, or transferred). It documents partial payments, one payment allocated to multiple invoices for one party, advances left unallocated, and later payment reconciliation; the amount allocated cannot exceed the payment available ([ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [ERPNext Payment Reconciliation](https://docs.frappe.io/erpnext/payment-reconciliation)).
- ERPNext differentiates payment reconciliation (linking an already-recorded payment to an invoice) from bank reconciliation (matching the ERP's bank-side postings to statement lines). Bank reconciliation compares statement lines with Payment Entries/Journal Entries, records unmatched differences, and asks the user to identify fees/timing differences rather than forcing a difference into the close ([ERPNext Bank Reconciliation](https://docs.frappe.io/erpnext/bank-reconciliation), [ERPNext Banking](https://docs.frappe.io/erpnext/banking-in-erpnext)).
- ERPNext states that cancelling a posted transaction retains its original ledger lines and adds opposite rows, while normal deletion of cancelled ledger documents is prevented; its correction guidance distinguishes correcting a draft, cancelling/amending a submitted invoice, and using a return/credit note for an actual customer return ([ERPNext Immutable Ledger](https://docs.frappe.io/erpnext/immutable-ledger)).

**Application to Mini ERP:** SPEC §5.5–5.6 already defines multiple payments per invoice, immutable cash movements, reversals, maker-checker controls, and exact-zero reconciliation. Preserve those controls. The current P0's one-payment-to-one-invoice relationship and no advance/overpayment support are deliberate simplifications compared with ERPNext, and should be marked as such in UI and documentation. Reconciliation should store/represent the external statement line separately from the internal cash movement, then link the two with a unique match; never create a second payment or cash movement while matching. The SPEC's existing `erp_reconciliation_items` can represent this relationship, but should make the external line's stable identity/import source explicit if statement import is added. A bank statement match is not the same business action as allocating a payment to an invoice.

### Authorization: role grant plus record and field boundaries

- Odoo documents two data-driven mechanisms: access rights grant model operations and record rules restrict records; its record rules are default-allow when a user has model access and no applicable rule exists ([Odoo security documentation](https://www.odoo.com/documentation/18.0/developer/reference/backend/security.html)).
- ERPNext describes role permissions as document-type permissions and User Permissions as restrictions on selected documents through linked fields. It also documents field-level permission levels, where role and field-level configuration both affect access ([ERPNext permissions](https://docs.frappe.io/erpnext/permissions), [ERPNext User Permissions](https://docs.frappe.io/erpnext/user-permissions), [ERPNext Perm Levels](https://docs.frappe.io/erpnext/v12/user/manual/en/setting-up/articles/managing-perm-level)).
- ERPNext's role matrix lists Print separately from Read, and its Print Format documentation treats the printed page/PDF as a rendering of a document. Odoo's record-rule documentation warns that a user with model access and no applicable record rule is allowed by default ([ERPNext permissions](https://docs.frappe.io/erpnext/permissions), [ERPNext Print Format](https://docs.frappe.io/erpnext/print-format), [Odoo security](https://www.odoo.com/documentation/18.0/developer/reference/backend/security.html)).

**Application to Mini ERP:** SPEC §3.2–3.3's separation between capability and object/field policy matches this layered model. Implement authorization in repository/service boundaries for list, count, search, export, detail, AJAX, and PDF, not only menu and action visibility. Keep row scope and field allowlists explicit; apply row filters before pagination and aggregation so list totals cannot leak hidden records. This is especially important for assigned warehouse and own-sales scopes. On 2026-10-20, the user resolved the cash default: each person sees only explicitly assigned accounts, including DRAFT accounts. The current SPEC has no distinct finance-document print capability, so voucher HTML/PDF uses the source read capability plus assigned-account/source checks; do not infer a new print grant from `erp_invoices_print`. The broader `docs/read-policy-contract.md` remains draft and local route coverage is not yet a full enforcement proof.

## Recommended changes mapped to this repository

| Priority | Repository reference | Recommendation | Why / boundary |
|---|---|---|---|
| P0 before full authorization acceptance | `SPEC.md` §§3, 8–9; `docs/read-policy-contract.md` | Finish the default-deny row/field policy contract. Require scoped repository queries for list/search/count/export/detail/PDF and recheck each write at commit. Exercise the explicit assignment boundary across every view. | Official ERP documentation separates role grants from record and field restrictions; Odoo's record rules are default-allow, so Mini ERP should preserve its stricter fail-closed contract ([Odoo security](https://www.odoo.com/documentation/18.0/developer/reference/backend/security.html), [ERPNext permissions](https://docs.frappe.io/erpnext/permissions)). |
| P0 decision recorded | `SPEC.md` §§1.1, 5.1, 6.2, 7.1 | Do not reserve stock when confirming a sales order. Show on-hand as informational and enforce the balance under lock while posting shipments. Two orders can both confirm; a later shipment can wait if an earlier shipment consumes stock. Never label on-hand as available/free-to-use. | Odoo distinguishes on-hand from unreserved free-to-use stock; Mini ERP keeps the distinction explicit while deferring reservation allocation ([Odoo stock report](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/warehouses_storage/reporting/stock.html)). |
| P0 wording/product clarity | `SPEC.md` §§1.1, 5.2, 5.4, FR-ACC-01 | Label purchase-side documents as internal supplier-bill tracking, distinct from goods receipt and not a legal invoice. | ERPNext treats PO, physical receipt, supplier invoice, and payment as separate lifecycle documents ([purchase cycle](https://docs.frappe.io/erpnext/purchase-cycle-ledger-impact)). |
| P0 reconciliation integrity | `SPEC.md` §§5.6, 6.2 tables 24–26, 7.3.3 | Specify reconciliation as matching internal cash movements against external statement rows, with stable statement-row identity, import deduplication, explicit fees/interest/timing differences, and immutable close. Keep invoice allocation as a separate concept if it is ever added. | ERPNext documents these as distinct reconciliation workflows and warns against forcing unexplained differences ([bank reconciliation](https://docs.frappe.io/erpnext/bank-reconciliation), [payment reconciliation](https://docs.frappe.io/erpnext/payment-reconciliation)). |
| P1 after payment/cash foundation | `SPEC.md` §§1.3, 5.5, 6.2 tables 19, 24, 26 | Consider unallocated customer/supplier advances and payment allocation across invoices as a separate feature, requiring payment-allocation rows (not duplicating cash movements), reversal rules, and receivable/payable reporting changes. | ERPNext supports unallocated advances, partial allocations, and multi-invoice allocation; this is more complex than the current one-payment/one-invoice P0 ([Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [Payment Reconciliation](https://docs.frappe.io/erpnext/payment-reconciliation)). |
| P0 implementation behavior | `SPEC.md` §§5.4–5.6, 7; `src/Infrastructure/Audit/AuditLogger.php` | Preserve immutable posted facts and correct by traceable reversal or an explicit void workflow. Separate data-entry mistakes from real returns/refunds; never use void as refund. | ERPNext retains posted rows and opposite reversal rows and distinguishes cancellation from return/credit-note workflows ([Immutable Ledger](https://docs.frappe.io/erpnext/immutable-ledger)). |

## Suggested end-to-end demonstration order

The following demo order follows the documented business boundaries while staying close to the current scope:

1. Admin enables an existing WordPress user, assigns one ERP role, and assigns warehouse/cash scopes.
2. Manager maintains an active product, customer/supplier, warehouse, and internal cash account.
3. Manager creates a purchase order; a second Manager approves it; Warehouse records partial and final receipts; each stock movement and balance update together.
4. Sales creates a draft order; server computes totals; the configured discount rule either confirms it or sends it for approval; an authorized different Manager approves when required.
5. Warehouse ships the confirmed order in partial and final shipments; stock is rechecked under transaction and unique movements are posted for every shipment line.
6. Accountant issues an internal invoice from each posted shipment using immutable source snapshots.
7. Accountant records one or more payments; each posted payment and its cash movement commit together; the invoice balance is derived from effective payments.
8. Accountant matches internal cash movements against statement lines; Manager closes only an exact, fully explained zero-difference period.
9. A payment-entry mistake follows request → approval → reversal; a genuine return/refund is explicitly shown as unsupported in this MVP.
10. Repeat selected views/actions as Sales, Warehouse, Manager, Accountant, and an unassigned user to show both allowed data and denied object/field scope.

The source documents support the document sequencing and reconciliation distinctions in this demo ([ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [ERPNext Purchase Cycle Ledger Impact](https://docs.frappe.io/erpnext/purchase-cycle-ledger-impact), [ERPNext Bank Reconciliation](https://docs.frappe.io/erpnext/bank-reconciliation)). This is a proposed demo script, not evidence that the current plugin can perform it.

## Source list

1. Odoo 18, [Sales quotations and sales-flow overview](https://www.odoo.com/documentation/18.0/applications/sales/sales/sales_quotations.html).
2. Odoo 18, [Sales invoicing policies](https://www.odoo.com/documentation/18.0/applications/sales/sales/invoicing/invoicing_policy.html).
3. Odoo 18, [Inventory stock report](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/warehouses_storage/reporting/stock.html).
4. Odoo 18, [Security: access rights and record rules](https://www.odoo.com/documentation/18.0/developer/reference/backend/security.html).
5. ERPNext, [Selling module](https://docs.frappe.io/erpnext/selling).
6. ERPNext, [Sales Order](https://docs.frappe.io/erpnext/sales-order).
7. ERPNext, [Delivery Note](https://docs.frappe.io/erpnext/delivery-note).
8. ERPNext, [Purchase Order](https://docs.frappe.io/erpnext/purchase-order).
9. ERPNext, [Purchase Receipt](https://docs.frappe.io/erpnext/purchase-receipt).
10. ERPNext, [Purchase cycle ledger impact](https://docs.frappe.io/erpnext/purchase-cycle-ledger-impact).
11. ERPNext, [Payment Entry](https://docs.frappe.io/erpnext/payment-entry).
12. ERPNext, [Payment Reconciliation](https://docs.frappe.io/erpnext/payment-reconciliation).
13. ERPNext, [Bank Reconciliation](https://docs.frappe.io/erpnext/bank-reconciliation).
14. ERPNext, [Banking](https://docs.frappe.io/erpnext/banking-in-erpnext).
15. ERPNext, [Immutable Ledger](https://docs.frappe.io/erpnext/immutable-ledger).
16. ERPNext, [Concepts and Terms](https://docs.frappe.io/erpnext/concepts-and-terms).
17. ERPNext, [Role-based permissions](https://docs.frappe.io/erpnext/permissions).
18. ERPNext, [User Permissions](https://docs.frappe.io/erpnext/user-permissions).
19. ERPNext v12 documentation, [Permission levels / field-level access](https://docs.frappe.io/erpnext/v12/user/manual/en/setting-up/articles/managing-perm-level).
20. ERPNext, [Buying Reports](https://docs.frappe.io/erpnext/buying_reports).
21. ERPNext, [Print Format](https://docs.frappe.io/erpnext/print-format).

## Limitations

- This research compares only the official product documentation pages listed above; it does not survey every ERP, implementation, edition, country localization, or configurable workflow.
- Vendor documentation describes supported product behavior, not an independent measure of implementation quality or a universal accounting standard.
- ERPNext docs evolve and some linked concepts may differ by installed version or configuration. The current repository targets a simplified single-company WordPress MVP, so parity would require scope and schema decisions rather than copying a vendor screen.
- The initial research pass changed no code, tests, database, browser UI, or local runtime. The follow-up decisions below document implementation candidates only; they do not claim runtime verification.

## Implementation decisions recorded after research

Per the user's request to adopt practical ERP workflows, the SPEC supports multiple shipments and goods receipts, tracks remaining quantities, and makes each posted shipment/receipt a separate invoice source. The current implementation candidate includes the related order, fulfillment, invoice, payment, expense, cash-ledger, reconciliation, report and protected-document flows. On 2026-10-20, payment and expense HTML/PDF vouchers were added as projections of the posted source plus its exact cash movement; reprinting a voided payment shows its approver/time, and each voucher is limited to the viewer's assigned account. The isolated `reports-smoke` fixture verified both voucher types, PDFs, assigned-account denial and void-history display. Those are candidate-level checks, not full UI or production acceptance. v1.1 does not reserve stock on sales confirmation; the shipment transaction is the inventory gate, and on-hand is informational.

## Implementation follow-up: assigned cash accounts (2026-10-21)

The user chose a per-user assignment for cash-account visibility. Mini ERP therefore treats an explicit `cashAccountIds` assignment as the boundary for account balances, movements, payment/expense details, reconciliation, vouchers, and write operations. A finance role by itself does not grant access to every cash account. Each controller performs a scoped target preflight before reading the remaining command fields, and the service repeats the account-scope check in the transaction that posts or changes the business record.

This adopts the role-plus-record-scope pattern documented by ERPNext: roles grant actions on document types, while User Permissions can narrow access to selected records and linked documents ([ERPNext Role Based Permissions](https://docs.frappe.io/erpnext/permissions)). ERPNext's Payment Entry also keeps the selected cash/bank account, payment date, external reference, and invoice allocation together as one money-movement document; the cash ledger and reconciliation remain separate concerns ([ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry)). Mini ERP deliberately keeps its v1.1 one-payment/one-invoice rule and does not adopt advances or multi-invoice allocation from the broader ERPNext feature set.

The local implementation follow-up adds generic 404 responses for absent or unassigned account targets, 422 for correctable input, 409 for an invalid business-state transition such as insufficient funds, and 503 for infrastructure failures. Successful posts retain 303 Post/Redirect/Get behavior. Cash forms keep user-entered values when the user returns from a conflict or recoverable service failure. See `docs/tasks.md` for the exact local HTTP, database, and browser evidence; these checks do not constitute full route-matrix, accessibility, or production acceptance.
