'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../wp-content/plugins/mini-erp/assets/user-access-form-recovery.js'), 'utf8');

function select(name, values, selectedValues = [], disabledValues = [], multiple = false) {
    const options = values.map((value) => ({ value, selected: selectedValues.includes(value), disabled: disabledValues.includes(value) }));
    return { name, tagName: 'SELECT', multiple, options, value: selectedValues[0] || '' };
}

function checkbox(name, checked) {
    return { name, type: 'checkbox', checked };
}

function form(actor, user, page, fields) {
    const listeners = {};
    const statuses = [];
    return {
        dataset: { accessActor: String(actor), accessUser: String(user), accessPage: String(page) },
        elements: { namedItem: (name) => fields[name] || null },
        addEventListener: (name, listener) => { listeners[name] = listener; },
        insertAdjacentElement: (_position, element) => statuses.push(element),
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
        createElement: () => ({
            attributes: {},
            setAttribute(name, value) { this.attributes[name] = value; },
            getAttribute(name) { return this.attributes[name]; },
        }),
    };
    vm.runInNewContext(source, {
        URLSearchParams,
        document,
        sessionStorage: session,
        window: { location: { search: query } },
    }, { filename: 'user-access-form-recovery.js' });
}

const key = 'mini-erp-user-access-form:44:73';
const session = storage();
const assigned = form(44, 73, 3, {
    erp_role: select('erp_role', ['', 'erp_system_admin', 'erp_manager', 'erp_sales', 'erp_warehouse', 'erp_accountant'], ['erp_warehouse']),
    'warehouse_ids[]': select('warehouse_ids[]', ['17', '18'], ['17'], ['18'], true),
    'cash_account_ids[]': select('cash_account_ids[]', ['31', '32'], ['32'], ['31'], true),
    enabled: checkbox('enabled', true),
    _mini_erp_nonce: { value: 'must-not-be-saved' },
    user_id: { value: 'must-not-be-saved' },
});
load([assigned], '?erp_page=mini-erp-users&erp_user_page=3', session);
assigned.submit();
const saved = JSON.parse(session.getItem(key));
assert.equal(saved.values.erp_role, 'erp_warehouse');
assert.deepEqual(saved.values['warehouse_ids[]'], ['17']);
assert.deepEqual(saved.values['cash_account_ids[]'], ['32']);
assert.equal(saved.values.enabled, true);
assert.equal(saved.values.user_id, undefined, 'Target IDs must not be saved as editable values.');
assert.equal(saved.values._mini_erp_nonce, undefined, 'Nonces must not be stored.');

const restored = form(44, 73, 3, {
    erp_role: select('erp_role', ['', 'erp_system_admin', 'erp_manager', 'erp_sales', 'erp_warehouse', 'erp_accountant']),
    'warehouse_ids[]': select('warehouse_ids[]', ['17', '18'], [], ['18'], true),
    'cash_account_ids[]': select('cash_account_ids[]', ['31', '32'], [], ['31'], true),
    enabled: checkbox('enabled', false),
});
load([restored], '?erp_page=mini-erp-users&erp_user_page=3&erp_access_error=1&recover_user_id=73', session);
assert.equal(restored.elements.namedItem('erp_role').value, 'erp_warehouse');
assert.equal(restored.elements.namedItem('warehouse_ids[]').options[0].selected, true);
assert.equal(restored.elements.namedItem('warehouse_ids[]').options[1].selected, false, 'Unavailable warehouse options must not be restored.');
assert.equal(restored.elements.namedItem('cash_account_ids[]').options[1].selected, true);
assert.equal(restored.elements.namedItem('enabled').checked, true);
assert.equal(restored.statuses[0]?.getAttribute('role'), 'status');
assert.equal(session.getItem(key), null, 'Recovered access values must be consumed once.');

session.setItem(key, JSON.stringify({ savedAt: Date.now(), values: { erp_role: 'erp_manager', 'cash_account_ids[]': ['31'] } }));
const differentActor = form(45, 73, 3, {
    erp_role: select('erp_role', ['', 'erp_manager']),
    'warehouse_ids[]': select('warehouse_ids[]', ['17'], [], [], true),
    'cash_account_ids[]': select('cash_account_ids[]', ['31'], [], [], true),
    enabled: checkbox('enabled', false),
});
load([differentActor], '?erp_user_page=3&erp_access_error=1&recover_user_id=73', session);
assert.equal(differentActor.elements.namedItem('erp_role').value, '');
assert.ok(session.getItem(key), 'Another actor must not consume the first actor session state.');

const wrongPage = form(44, 73, 2, {
    erp_role: select('erp_role', ['', 'erp_manager']),
    'warehouse_ids[]': select('warehouse_ids[]', ['17'], [], [], true),
    'cash_account_ids[]': select('cash_account_ids[]', ['31'], [], [], true),
    enabled: checkbox('enabled', false),
});
load([wrongPage], '?erp_user_page=3&erp_access_error=1&recover_user_id=73', session);
assert.equal(wrongPage.elements.namedItem('erp_role').value, '', 'A recovery must stay on the submitted list page.');

const successPage = form(44, 73, 3, {
    erp_role: select('erp_role', ['', 'erp_manager']),
    'warehouse_ids[]': select('warehouse_ids[]', ['17'], [], [], true),
    'cash_account_ids[]': select('cash_account_ids[]', ['31'], [], [], true),
    enabled: checkbox('enabled', false),
});
load([successPage], '?erp_user_page=3&erp_access_saved=1&recover_user_id=73', session);
assert.equal(session.getItem(key), null, 'Successful access changes must clear recovered state.');

session.setItem(key, JSON.stringify({ savedAt: Date.now() - 16 * 60 * 1000, values: { erp_role: 'erp_manager' } }));
const expired = form(44, 73, 3, {
    erp_role: select('erp_role', ['', 'erp_manager']),
    'warehouse_ids[]': select('warehouse_ids[]', ['17'], [], [], true),
    'cash_account_ids[]': select('cash_account_ids[]', ['31'], [], [], true),
    enabled: checkbox('enabled', false),
});
load([expired], '?erp_user_page=3&erp_access_error=1&recover_user_id=73', session);
assert.equal(expired.elements.namedItem('erp_role').value, '');
assert.equal(session.getItem(key), null, 'Expired user access data must be removed.');

process.stdout.write('User access recovery checks passed: page/actor/user scope, role and assignment allowlists, disabled options, expiry and success cleanup.\n');
