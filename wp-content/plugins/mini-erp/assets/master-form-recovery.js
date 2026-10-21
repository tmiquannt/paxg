(() => {
    'use strict';

    const fieldsByForm = {
        product: ['sku', 'name', 'category_id', 'unit', 'unit_step', 'sale_price_vnd'],
        category: ['code', 'name'],
        customer: ['code', 'name', 'tax_id', 'phone', 'email', 'address'],
        supplier: ['code', 'name', 'tax_id', 'phone', 'email', 'address'],
        warehouse: ['code', 'name'],
        'company-profile': ['company_name', 'company_address', 'company_tax_id'],
    };
    const limits = {
        sku: 64,
        name: 191,
        category_id: 18,
        unit: 32,
        unit_step: 22,
        sale_price_vnd: 19,
        code: 64,
        tax_id: 64,
        phone: 32,
        email: 254,
        address: 1000,
        company_name: 191,
        company_address: 1000,
        company_tax_id: 64,
    };
    const recoveryNotices = new Set(['invalid', 'duplicate', 'conflict', 'unavailable']);
    const params = new URLSearchParams(window.location.search);
    const notice = params.get('notice') || '';
    const recoveryForm = params.get('recover_form') || '';
    const recoveryScope = params.get('recover_scope') || '';
    const keyFor = (type, actor, scope) => `mini-erp-master-form:${type}:${actor}:${scope}`;
    const validScope = (scope) => scope === 'new' || scope === 'profile' || /^id-[1-9][0-9]{0,17}$/.test(scope);

    if (recoveryForm && validScope(recoveryScope) && notice === 'saved' && fieldsByForm[recoveryForm]) {
        const actor = document.querySelector('form[data-master-form]')?.dataset.masterActor;
        if (actor && /^[1-9][0-9]{0,17}$/.test(actor)) {
            try {
                sessionStorage.removeItem(keyFor(recoveryForm, actor, recoveryScope));
            } catch (error) {
                // Browser storage may be disabled.
            }
        }
    }

    document.querySelectorAll('form[data-master-form]').forEach((form) => {
        const type = form.dataset.masterForm;
        const actor = form.dataset.masterActor;
        const scope = form.dataset.masterScope;
        const fields = fieldsByForm[type];
        if (!fields || !actor || !/^[1-9][0-9]{0,17}$/.test(actor) || !validScope(scope)) return;

        const key = keyFor(type, actor, scope);
        const matchingResponse = recoveryForm === type && recoveryScope === scope;

        if (matchingResponse && notice === 'saved') {
            try {
                sessionStorage.removeItem(key);
            } catch (error) {
                // Browser storage may be disabled.
            }
        } else if (matchingResponse && recoveryNotices.has(notice)) {
            let saved = null;
            try {
                const raw = sessionStorage.getItem(key);
                sessionStorage.removeItem(key);
                if (raw && raw.length <= 8192) saved = JSON.parse(raw);
            } catch (error) {
                saved = null;
            }

            const age = saved && Number.isFinite(saved.savedAt) ? Date.now() - saved.savedAt : -1;
            if (saved && age >= 0 && age <= 15 * 60 * 1000 && saved.values && typeof saved.values === 'object') {
                let restored = false;
                fields.forEach((name) => {
                    const value = saved.values[name];
                    if (typeof value !== 'string' || value.length > limits[name]) return;
                    const control = form.elements.namedItem(name);
                    if (!control || typeof control.value !== 'string') return;
                    if (control.tagName === 'SELECT' && !Array.from(control.options || []).some((option) => option.value === value)) return;
                    control.value = value;
                    restored = true;
                });

                if (restored) {
                    const status = document.createElement('p');
                    status.className = 'description';
                    status.setAttribute('role', 'status');
                    status.textContent = 'Đã khôi phục dữ liệu vừa nhập. Kiểm tra lại trước khi lưu hoặc gửi lại.';
                    form.insertAdjacentElement('beforebegin', status);
                }
            }
        }

        form.addEventListener('submit', () => {
            const values = Object.create(null);
            fields.forEach((name) => {
                const control = form.elements.namedItem(name);
                if (control && typeof control.value === 'string' && control.value.length <= limits[name]) {
                    values[name] = control.value;
                }
            });

            try {
                sessionStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), values }));
            } catch (error) {
                // Browser storage may be disabled or full; server-side validation still applies.
            }
        });
    });
})();
