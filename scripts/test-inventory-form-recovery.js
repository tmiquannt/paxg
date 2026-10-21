'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../wp-content/plugins/mini-erp/assets/inventory-form-recovery.js'), 'utf8');

function control(name, initialValue = '', options = null) {
    let value = initialValue;
    const item = { name, tagName: options ? 'SELECT' : 'INPUT', options: (options || []).map((option) => ({ value: option })) };
    Object.defineProperty(item, 'value', {
        get: () => value,
        set: (next) => { value = options && !options.includes(next) ? '' : next; },
    });
    return item;
}

function form(type, actor, scope, values, options = {}) {
    const controls = Object.fromEntries(Object.entries(values).map(([name, value]) => [
        name,
        control(name, value, options[name] || null),
    ]));
    const statuses = [];
    const listeners = {};
    return {
        dataset: { inventoryForm: type, inventoryActor: String(actor), inventoryScope: scope },
        elements: { namedItem: (name) => controls[name] || null },
        querySelectorAll: (selector) => selector === 'input[name^="counts["]'
            ? Object.values(controls).filter((item) => item.name.startsWith('counts['))
            : [],
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
    }, { filename: 'inventory-form-recovery.js' });
}

const session = storage();
const startKey = 'mini-erp-inventory-form:inventory-start:44:start';
const startForm = form('inventory-start', 44, 'start', {
    warehouse_id: '17', reason: 'Kiểm kê cuối tháng', _wpnonce: 'must-not-be-saved',
}, { warehouse_id: ['17', '18'] });
load([startForm], '?page=mini-erp-inventory', session);
startForm.submit();
assert.equal(JSON.parse(session.getItem(startKey)).values.reason, 'Kiểm kê cuối tháng');
assert.equal(JSON.parse(session.getItem(startKey)).values._wpnonce, undefined, 'Nonce must not enter session storage.');

const restoredStart = form('inventory-start', 44, 'start', { warehouse_id: '', reason: '' }, { warehouse_id: ['17', '18'] });
load([restoredStart], '?notice=invalid&recover_form=inventory-start&recover_scope=start', session);
assert.equal(restoredStart.controls.warehouse_id.value, '17');
assert.equal(restoredStart.controls.reason.value, 'Kiểm kê cuối tháng');
assert.equal(restoredStart.statuses[0]?.textContent, 'Đã khôi phục dữ liệu kiểm kê vừa nhập. Kiểm tra lại trước khi lưu hoặc gửi duyệt.');
assert.equal(session.getItem(startKey), null, 'Recovered start values should be consumed once.');

session.setItem(startKey, JSON.stringify({ savedAt: Date.now(), values: { warehouse_id: '999', reason: 'Không hợp lệ' } }));
const rejectedWarehouse = form('inventory-start', 44, 'start', { warehouse_id: '', reason: '' }, { warehouse_id: ['17', '18'] });
load([rejectedWarehouse], '?notice=invalid&recover_form=inventory-start&recover_scope=start', session);
assert.equal(rejectedWarehouse.controls.warehouse_id.value, '', 'A warehouse outside the rendered actor scope must not be restored.');

const adjustmentScope = 'adjustment-901';
const countKey = `mini-erp-inventory-form:inventory-count:44:${adjustmentScope}`;
const countForm = form('inventory-count', 44, adjustmentScope, {
    'counts[73]': '4.500', 'counts[74]': '0', 'counts[999999]': '8', _wpnonce: 'must-not-be-saved',
});
load([countForm], '?page=mini-erp-inventory&adjustment=901', session);
countForm.submit();
const savedCounts = JSON.parse(session.getItem(countKey));
assert.deepEqual(Object.keys(savedCounts.values).sort(), ['counts[73]', 'counts[74]', 'counts[999999]']);

const restoredCount = form('inventory-count', 44, adjustmentScope, { 'counts[73]': '', 'counts[74]': '' });
load([restoredCount], '?adjustment=901&notice=unavailable&recover_form=inventory-count&recover_scope=adjustment-901', session);
assert.equal(restoredCount.controls['counts[73]'].value, '4.500');
assert.equal(restoredCount.controls['counts[74]'].value, '0');
assert.equal(restoredCount.statuses.length, 1);
assert.equal(session.getItem(countKey), null, 'Recovered count values should be consumed once.');

const otherAdjustment = form('inventory-count', 44, 'adjustment-902', { 'counts[73]': '' });
session.setItem(countKey, JSON.stringify({ savedAt: Date.now(), values: { 'counts[73]': '6.000' } }));
load([otherAdjustment], '?adjustment=902&notice=invalid&recover_form=inventory-count&recover_scope=adjustment-901', session);
assert.equal(otherAdjustment.controls['counts[73]'].value, '', 'Counts must not cross adjustment scopes.');
assert.ok(session.getItem(countKey), 'A different adjustment must not consume the original form state.');

const requestKey = 'b14d8b92-d6dc-4b54-9861-e92cc9183fea';
const applyScope = 'adjustment-903';
const applyKey = `mini-erp-inventory-form:inventory-apply:44:${applyScope}`;
const applyForm = form('inventory-apply', 44, applyScope, { request_key: requestKey, _wpnonce: 'must-not-be-saved' });
load([applyForm], '?adjustment=903', session);
applyForm.submit();
const restoredApply = form('inventory-apply', 44, applyScope, { request_key: 'fresh-valid-key' });
load([restoredApply], '?notice=invalid&recover_form=inventory-apply&recover_scope=adjustment-903', session);
assert.equal(restoredApply.controls.request_key.value, requestKey);

session.setItem(applyKey, JSON.stringify({ savedAt: Date.now(), values: { request_key: 'tampered' } }));
const rejectRequestKey = form('inventory-apply', 44, applyScope, { request_key: 'fresh-valid-key' });
load([rejectRequestKey], '?notice=invalid&recover_form=inventory-apply&recover_scope=adjustment-903', session);
assert.equal(rejectRequestKey.controls.request_key.value, 'fresh-valid-key', 'Malformed idempotency keys must not replace the generated key.');

session.setItem(applyKey, JSON.stringify({ savedAt: Date.now(), values: { request_key: requestKey } }));
const appliedForm = form('inventory-apply', 44, applyScope, { request_key: requestKey });
load([appliedForm], '?adjustment=903&notice=applied&recover_form=inventory-apply&recover_scope=adjustment-903', session);
assert.equal(session.getItem(applyKey), null, 'A successfully applied adjustment must clear its request key.');

session.setItem(countKey, JSON.stringify({ savedAt: Date.now(), values: { 'counts[73]': '9.000' } }));
const cleanupProbe = form('inventory-start', 44, 'start', { warehouse_id: '', reason: '' }, { warehouse_id: ['17'] });
load([cleanupProbe], '?adjustment=901&notice=requested&recover_form=inventory-count&recover_scope=adjustment-901', session);
assert.equal(session.getItem(countKey), null, 'A successful request must clear count state even after the editable form disappears.');

session.setItem(countKey, JSON.stringify({ savedAt: Date.now() - 16 * 60 * 1000, values: { 'counts[73]': '9.000' } }));
const expiredCount = form('inventory-count', 44, adjustmentScope, { 'counts[73]': '' });
load([expiredCount], '?adjustment=901&notice=invalid&recover_form=inventory-count&recover_scope=adjustment-901', session);
assert.equal(expiredCount.controls['counts[73]'].value, '', 'Expired count values must not be restored.');
assert.equal(session.getItem(countKey), null, 'Expired inventory values must be removed.');

process.stdout.write('Inventory form recovery checks passed: user/adjustment scopes, allowlists, assigned warehouses, expiry and success cleanup.\n');
