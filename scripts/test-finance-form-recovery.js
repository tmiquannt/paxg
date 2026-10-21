'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../wp-content/plugins/mini-erp/assets/finance-form-recovery.js'), 'utf8');

function control(initialValue = '', options = null) {
    let value = initialValue;
    const item = {};
    Object.defineProperty(item, 'value', {
        get: () => value,
        set: (next) => {
            value = options && !options.includes(next) ? '' : next;
        },
    });
    return item;
}

function form(type, actor, scope, values, selectOptions = {}) {
    const controls = Object.fromEntries(Object.entries(values).map(([name, value]) => [
        name,
        control(value, selectOptions[name] || null),
    ]));
    const statuses = [];
    const listeners = {};
    return {
        dataset: { financeForm: type, financeActor: String(actor), financeScope: scope },
        elements: { namedItem: (name) => controls[name] || null },
        addEventListener: (name, listener) => { listeners[name] = listener; },
        insertAdjacentElement: (_position, element) => statuses.push(element),
        controls,
        statuses,
        submit: () => listeners.submit?.(),
    };
}

function storage() {
    const values = new Map();
    return {
        values,
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
    };
}

function load(forms, query, session) {
    const document = {
        querySelectorAll: () => forms,
        querySelector: () => forms[0] || null,
        createElement: () => ({ setAttribute(name, value) { this[name] = value; } }),
    };
    vm.runInNewContext(source, {
        URLSearchParams,
        document,
        sessionStorage: session,
        window: { location: { search: query } },
    }, { filename: 'finance-form-recovery.js' });
}

const session = storage();
const paymentKey = 'mini-erp-finance-form:payment:17:invoice-41';
const requestKey = 'b14d8b92-d6dc-4b54-9861-e92cc9183fea';
const submittedPayment = form('payment', 17, 'invoice-41', {
    cash_account_id: '701', amount_vnd: '1250000', method: 'BANK_TRANSFER',
    external_reference: 'BANK-REF-41', paid_at: '2026-10-20T14:30', request_key: requestKey,
    _wpnonce: 'must-not-be-saved',
}, { cash_account_id: ['701', '702'] });
load([submittedPayment], '?page=mini-erp-invoices&invoice=41', session);
submittedPayment.submit();
const savedPayment = JSON.parse(session.getItem(paymentKey));
assert.equal(savedPayment.values.amount_vnd, '1250000');
assert.equal(savedPayment.values.request_key, requestKey);
assert.equal(savedPayment.values._wpnonce, undefined, 'Nonce must not enter session storage.');

const restoredPayment = form('payment', 17, 'invoice-41', {
    cash_account_id: '', amount_vnd: '', method: 'CASH', external_reference: '',
    paid_at: '', request_key: 'fresh-request-key',
}, { cash_account_id: ['701', '702'] });
load([restoredPayment], '?page=mini-erp-invoices&invoice=41&notice=invalid&recover_form=payment', session);
assert.equal(restoredPayment.controls.amount_vnd.value, '1250000');
assert.equal(restoredPayment.controls.cash_account_id.value, '701');
assert.equal(restoredPayment.controls.request_key.value, requestKey);
assert.equal(restoredPayment.statuses[0]?.textContent, 'Đã khôi phục dữ liệu vừa nhập. Kiểm tra lại thông tin trước khi gửi lại.');
assert.equal(session.getItem(paymentKey), null, 'Recovered state should be consumed once.');

session.setItem(paymentKey, JSON.stringify({
    savedAt: Date.now(),
    values: { cash_account_id: '999', amount_vnd: '100', request_key: 'tampered' },
}));
const rejectedAssignment = form('payment', 17, 'invoice-41', {
    cash_account_id: '', amount_vnd: '', method: 'CASH', external_reference: '',
    paid_at: '', request_key: 'new-valid-request-key',
}, { cash_account_id: ['701', '702'] });
load([rejectedAssignment], '?notice=invalid&recover_form=payment', session);
assert.equal(rejectedAssignment.controls.cash_account_id.value, '', 'An account outside the rendered assignment options must not be restored.');
assert.equal(rejectedAssignment.controls.request_key.value, 'new-valid-request-key', 'A malformed request key must not replace the server-generated key.');

const submittedExpense = form('expense', 23, 'expense', {
    category: 'Vận chuyển', cash_account_id: '701', amount_vnd: '350000',
    description: 'Giao hàng đơn SO-41', occurred_at: '2026-10-20T15:00', request_key: requestKey,
}, { cash_account_id: ['701'] });
load([submittedExpense], '?page=mini-erp-expenses', session);
submittedExpense.submit();
const expenseKey = 'mini-erp-finance-form:expense:23:expense';
assert.ok(session.getItem(expenseKey));
load([form('expense', 23, 'expense', {})], '?notice=expense_posted&recover_form=expense', session);
assert.equal(session.getItem(expenseKey), null, 'Successful expense posting must clear saved values.');

const voidKey = 'mini-erp-finance-form:invoice-void:23:invoice-88';
session.setItem(voidKey, JSON.stringify({ savedAt: Date.now(), values: { reason: 'Phát hành nhầm chứng từ' } }));
const voidForm = form('invoice-void', 23, 'invoice-88', { reason: '' });
load([voidForm], '?invoice=88&notice=invalid&recover_form=invoice-void', session);
assert.equal(voidForm.controls.reason.value, 'Phát hành nhầm chứng từ');

const createKey = 'mini-erp-finance-form:reconciliation-create:31:create';
session.setItem(createKey, JSON.stringify({
    savedAt: Date.now(),
    values: {
        cash_account_id: '701', period_from: '2026-10-01', period_to: '2026-10-19',
        statement_opening_vnd: '1000000', statement_closing_vnd: '1500000', note: 'Sao kê tháng 10',
    },
}));
const createForm = form('reconciliation-create', 31, 'create', {
    cash_account_id: '', period_from: '', period_to: '', statement_opening_vnd: '',
    statement_closing_vnd: '', note: '',
}, { cash_account_id: ['701'] });
load([createForm], '?notice=invalid&recover_form=reconciliation-create', session);
assert.equal(createForm.controls.statement_opening_vnd.value, '1000000');
assert.equal(createForm.controls.note.value, 'Sao kê tháng 10');

const matchKey = 'mini-erp-finance-form:reconciliation-match:31:match-90-1220';
session.setItem(matchKey, JSON.stringify({
    savedAt: Date.now(), values: { external_reference: 'STMT-1220', external_direction: 'IN' },
}));
load([form('reconciliation-create', 31, 'create', {})], '?notice=matched&recover_form=reconciliation-match&recover_scope=match-90-1220', session);
assert.equal(session.getItem(matchKey), null, 'Matched row state must clear even when the row disappears after success.');

const expiredKey = 'mini-erp-finance-form:payment:17:invoice-41';
session.setItem(expiredKey, JSON.stringify({
    savedAt: Date.now() - 16 * 60 * 1000, values: { amount_vnd: '999999' },
}));
const expiredPayment = form('payment', 17, 'invoice-41', {
    cash_account_id: '', amount_vnd: '', method: 'CASH', external_reference: '',
    paid_at: '', request_key: 'new-key',
}, { cash_account_id: ['701'] });
load([expiredPayment], '?notice=invalid&recover_form=payment', session);
assert.equal(expiredPayment.controls.amount_vnd.value, '', 'Expired values must not be restored.');
assert.equal(session.getItem(expiredKey), null, 'Expired state must be removed.');

process.stdout.write('Finance form recovery checks passed: actor/document scopes, allowlist, expiry, assignment options and success cleanup.\n');
