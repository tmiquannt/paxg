(() => {
    'use strict';

    const fieldsByForm = {
        'inventory-start': ['warehouse_id', 'reason'],
        'inventory-count': [],
        'inventory-apply': ['request_key'],
    };
    const limits = { warehouse_id: 18, reason: 1000, request_key: 36 };
    const requestKeyPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    const successNoticeByForm = {
        'inventory-start': 'created',
        'inventory-count': ['saved', 'requested'],
        'inventory-apply': ['applied', 'stale'],
    };
    const params = new URLSearchParams(window.location.search);
    const notice = params.get('notice') || '';
    const recoveryForm = params.get('recover_form') || '';
    const recoveryScope = params.get('recover_scope') || '';
    const isSuccess = (type, value) => Array.isArray(value) ? value.includes(notice) : value === notice;
    const keyFor = (type, actor, scope) => `mini-erp-inventory-form:${type}:${actor}:${scope}`;

    if (recoveryScope && successNoticeByForm[recoveryForm] && isSuccess(recoveryForm, successNoticeByForm[recoveryForm])) {
        const actor = document.querySelector('form[data-inventory-form]')?.dataset.inventoryActor;
        if (actor) {
            try {
                sessionStorage.removeItem(keyFor(recoveryForm, actor, recoveryScope));
            } catch (error) {
                // Browser storage may be disabled.
            }
        }
    }

    document.querySelectorAll('form[data-inventory-form]').forEach((form) => {
        const type = form.dataset.inventoryForm;
        const actor = form.dataset.inventoryActor;
        const scope = form.dataset.inventoryScope;
        const fields = fieldsByForm[type];
        if (!fields || !actor || !scope) return;

        const key = keyFor(type, actor, scope);
        const matchingResponse = recoveryForm === type && recoveryScope === scope;
        const successfulResponse = matchingResponse && isSuccess(type, successNoticeByForm[type]);

        if (successfulResponse) {
            try {
                sessionStorage.removeItem(key);
            } catch (error) {
                // Browser storage may be disabled.
            }
        } else if (matchingResponse && ['invalid', 'unavailable'].includes(notice)) {
            let saved = null;
            try {
                const raw = sessionStorage.getItem(key);
                sessionStorage.removeItem(key);
                if (raw && raw.length <= 65536) saved = JSON.parse(raw);
            } catch (error) {
                saved = null;
            }

            const age = saved && Number.isFinite(saved.savedAt) ? Date.now() - saved.savedAt : -1;
            if (saved && age >= 0 && age <= 15 * 60 * 1000 && saved.values && typeof saved.values === 'object') {
                let restored = false;
                const controls = type === 'inventory-count'
                    ? Array.from(form.querySelectorAll('input[name^="counts["]'))
                    : fields.map((name) => form.elements.namedItem(name)).filter(Boolean);

                controls.forEach((control) => {
                    const name = control.name || '';
                    const allowed = type === 'inventory-count'
                        ? /^counts\[[1-9][0-9]{0,17}\]$/.test(name)
                        : fields.includes(name);
                    if (!allowed) return;

                    const value = saved.values[name];
                    const limit = limits[name] || 22;
                    if (typeof value !== 'string' || value.length > limit) return;
                    if (name === 'request_key' && !requestKeyPattern.test(value)) return;
                    if (control.tagName === 'SELECT' && !Array.from(control.options || []).some((option) => option.value === value)) return;

                    control.value = value;
                    restored = true;
                });

                if (restored) {
                    const status = document.createElement('p');
                    status.className = 'description';
                    status.setAttribute('role', 'status');
                    status.textContent = 'Đã khôi phục dữ liệu kiểm kê vừa nhập. Kiểm tra lại trước khi lưu hoặc gửi duyệt.';
                    form.insertAdjacentElement('beforebegin', status);
                }
            }
        } else {
            try {
                sessionStorage.removeItem(key);
            } catch (error) {
                // Browser storage may be disabled.
            }
        }

        form.addEventListener('submit', () => {
            const values = Object.create(null);
            const controls = type === 'inventory-count'
                ? Array.from(form.querySelectorAll('input[name^="counts["]'))
                : fields.map((name) => form.elements.namedItem(name)).filter(Boolean);

            controls.forEach((control) => {
                const name = control.name || '';
                const allowed = type === 'inventory-count'
                    ? /^counts\[[1-9][0-9]{0,17}\]$/.test(name)
                    : fields.includes(name);
                if (!allowed) return;
                const limit = limits[name] || 22;
                if (typeof control.value === 'string' && control.value.length <= limit) values[name] = control.value;
            });

            try {
                sessionStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), values }));
            } catch (error) {
                // Browser storage may be disabled or full; server validation still applies.
            }
        });
    });
})();
