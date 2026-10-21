'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../wp-content/plugins/mini-erp/assets/master-form-recovery.js'), 'utf8');

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
    const controls = Object.fromEntries(Object.entries(values).map(([name, value]) => [name, control(name, value, options[name] || null)]));
    const statuses = [];
    const listeners = {};
    return {
        dataset: { masterForm: type, masterActor: String(actor), masterScope: scope },
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
    }, { filename: 'master-form-recovery.js' });
}

const session = storage();
const customerKey = 'mini-erp-master-form:customer:44:new';
const customer = form('customer', 44, 'new', {
    code: 'CUS-01', name: 'Cửa hàng A', tax_id: '0123456789', phone: '0900000000',
    email: 'buyer@example.invalid', address: 'Số 1, đường A', _wpnonce: 'must-not-be-saved', action: 'must-not-be-saved',
});
load([customer], '?page=mini-erp-customers', session);
customer.submit();
assert.deepEqual(Object.keys(JSON.parse(session.getItem(customerKey)).values).sort(), ['address', 'code', 'email', 'name', 'phone', 'tax_id']);

const restoredCustomer = form('customer', 44, 'new', { code: '', name: '', tax_id: '', phone: '', email: '', address: '' });
load([restoredCustomer], '?notice=duplicate&recover_form=customer&recover_scope=new', session);
assert.equal(restoredCustomer.controls.code.value, 'CUS-01');
assert.equal(restoredCustomer.controls.address.value, 'Số 1, đường A');
assert.equal(restoredCustomer.statuses[0]?.getAttribute('role'), 'status');
assert.equal(restoredCustomer.statuses[0]?.textContent, 'Đã khôi phục dữ liệu vừa nhập. Kiểm tra lại trước khi lưu hoặc gửi lại.');
assert.equal(session.getItem(customerKey), null, 'Recovered form state must be consumed once.');

const productKey = 'mini-erp-master-form:product:44:id-73';
session.setItem(productKey, JSON.stringify({ savedAt: Date.now(), values: {
    sku: 'SKU-73', name: 'Nguyên liệu', category_id: '9', unit: 'kg', unit_step: '0.125', sale_price_vnd: '125000',
} }));
const product = form('product', 44, 'id-73', { sku: '', name: '', category_id: '', unit: '', unit_step: '', sale_price_vnd: '' }, { category_id: ['8', '9'] });
load([product], '?notice=invalid&recover_form=product&recover_scope=id-73', session);
assert.equal(product.controls.category_id.value, '9');
assert.equal(product.controls.sale_price_vnd.value, '125000');

session.setItem(productKey, JSON.stringify({ savedAt: Date.now(), values: { category_id: '999' } }));
const invalidCategory = form('product', 44, 'id-73', { category_id: '' }, { category_id: ['8', '9'] });
load([invalidCategory], '?notice=invalid&recover_form=product&recover_scope=id-73', session);
assert.equal(invalidCategory.controls.category_id.value, '', 'A category outside current rendered options must not be restored.');

const editSupplierKey = 'mini-erp-master-form:supplier:44:id-103';
session.setItem(editSupplierKey, JSON.stringify({ savedAt: Date.now(), values: { code: 'SUP-103', name: 'Nhà cung cấp', email: 'ops@example.invalid' } }));
const newSupplier = form('supplier', 44, 'new', { code: '', name: '', email: '' });
load([newSupplier], '?notice=invalid&recover_form=supplier&recover_scope=id-103', session);
assert.equal(newSupplier.controls.code.value, '', 'An edit response must not restore into a different create form.');
assert.ok(session.getItem(editSupplierKey), 'A different form scope must not consume edit data.');

const postSaveForm = form('warehouse', 44, 'new', { code: '', name: '' });
session.setItem('mini-erp-master-form:warehouse:44:new', JSON.stringify({ savedAt: Date.now(), values: { code: 'WH-01' } }));
load([postSaveForm], '?notice=saved&recover_form=warehouse&recover_scope=new', session);
assert.equal(session.getItem('mini-erp-master-form:warehouse:44:new'), null, 'Successful saves must clear form state.');

const oldKey = 'mini-erp-master-form:category:44:new';
session.setItem(oldKey, JSON.stringify({ savedAt: Date.now() - 16 * 60 * 1000, values: { code: 'OLD' } }));
const expired = form('category', 44, 'new', { code: '', name: '' });
load([expired], '?notice=unavailable&recover_form=category&recover_scope=new', session);
assert.equal(expired.controls.code.value, '', 'Expired form values must not be restored.');
assert.equal(session.getItem(oldKey), null);

const profileKey = 'mini-erp-master-form:company-profile:44:profile';
const profile = form('company-profile', 44, 'profile', {
    company_name: 'Công ty Thử nghiệm', company_address: 'Địa chỉ mẫu', company_tax_id: '0123456789', _wpnonce: 'must-not-be-saved',
});
load([profile], '?page=mini-erp-company', session);
profile.submit();
assert.deepEqual(Object.keys(JSON.parse(session.getItem(profileKey)).values).sort(), ['company_address', 'company_name', 'company_tax_id']);
const restoredProfile = form('company-profile', 44, 'profile', { company_name: '', company_address: '', company_tax_id: '' });
load([restoredProfile], '?notice=invalid&recover_form=company-profile&recover_scope=profile', session);
assert.equal(restoredProfile.controls.company_name.value, 'Công ty Thử nghiệm');
assert.equal(restoredProfile.controls.company_address.value, 'Địa chỉ mẫu');
const savedProfile = form('company-profile', 44, 'profile', { company_name: '', company_address: '', company_tax_id: '' });
load([savedProfile], '?notice=saved&recover_form=company-profile&recover_scope=profile', session);
assert.equal(session.getItem(profileKey), null, 'Successful company profile saves must clear recovery state.');

process.stdout.write('Master form recovery checks passed: allowlists, actor/form scope, current select options, expiry and success cleanup.\n');
