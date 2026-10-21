(() => {
    'use strict';

    const roleValues = new Set(['', 'erp_system_admin', 'erp_manager', 'erp_sales', 'erp_warehouse', 'erp_accountant']);
    const scopeFields = ['warehouse_ids[]', 'cash_account_ids[]'];
    const params = new URLSearchParams(window.location.search);
    const targetUserId = params.get('recover_user_id') || '';
    const requestedPage = params.get('erp_user_page') || '1';
    const notice = params.has('erp_access_saved') ? 'saved' : params.has('erp_access_error') ? 'error' : '';
    const validId = (value) => /^[1-9][0-9]{0,17}$/.test(value || '');
    const validPage = /^[1-9][0-9]{0,4}$/.test(requestedPage);
    const keyFor = (actor, user) => `mini-erp-user-access-form:${actor}:${user}`;

    const forms = Array.from(document.querySelectorAll('form[data-access-form="user-access"]'));
    const responseForm = forms.find((form) =>
        validId(targetUserId)
        && form.dataset.accessUser === targetUserId
        && form.dataset.accessPage === requestedPage
    );
    if (notice === 'saved' && responseForm) {
        try {
            sessionStorage.removeItem(keyFor(responseForm.dataset.accessActor, targetUserId));
        } catch (error) {
            // Browser storage may be disabled.
        }
    }

    forms.forEach((form) => {
        const actor = form.dataset.accessActor || '';
        const user = form.dataset.accessUser || '';
        const page = form.dataset.accessPage || '';
        if (!validId(actor) || !validId(user) || !/^[1-9][0-9]{0,4}$/.test(page)) return;

        const key = keyFor(actor, user);
        const matchingResponse = form === responseForm;
        if (notice === 'saved' && matchingResponse) {
            try {
                sessionStorage.removeItem(key);
            } catch (error) {
                // Browser storage may be disabled.
            }
        } else if (notice === 'error' && matchingResponse) {
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
                const role = saved.values.erp_role;
                const roleControl = form.elements.namedItem('erp_role');
                if (typeof role === 'string' && roleValues.has(role) && roleControl && Array.from(roleControl.options || []).some((option) => option.value === role && !option.disabled)) {
                    roleControl.value = role;
                    restored = true;
                }

                scopeFields.forEach((name) => {
                    const values = saved.values[name];
                    const control = form.elements.namedItem(name);
                    if (!Array.isArray(values) || !control || control.tagName !== 'SELECT' || !control.multiple) return;
                    const allowed = new Set(values.filter((value) => typeof value === 'string' && validId(value)));
                    let selectedAny = false;
                    Array.from(control.options || []).forEach((option) => {
                        option.selected = allowed.has(option.value) && !option.disabled;
                        selectedAny = selectedAny || option.selected;
                    });
                    restored = restored || selectedAny || values.length === 0;
                });

                if (typeof saved.values.enabled === 'boolean') {
                    const enabled = form.elements.namedItem('enabled');
                    if (enabled && enabled.type === 'checkbox') {
                        enabled.checked = saved.values.enabled;
                        restored = true;
                    }
                }

                if (restored) {
                    const status = document.createElement('p');
                    status.className = 'description';
                    status.setAttribute('role', 'status');
                    status.textContent = 'Đã khôi phục vai trò và phạm vi vừa nhập. Kiểm tra lại người dùng, kho, quỹ và trạng thái trước khi lưu.';
                    form.insertAdjacentElement('beforebegin', status);
                }
            }
        }

        form.addEventListener('submit', () => {
            const values = Object.create(null);
            const roleControl = form.elements.namedItem('erp_role');
            if (roleControl && typeof roleControl.value === 'string' && roleValues.has(roleControl.value)) {
                values.erp_role = roleControl.value;
            }
            scopeFields.forEach((name) => {
                const control = form.elements.namedItem(name);
                if (!control || control.tagName !== 'SELECT' || !control.multiple) return;
                values[name] = Array.from(control.options || [])
                    .filter((option) => option.selected && validId(option.value))
                    .slice(0, 100)
                    .map((option) => option.value);
            });
            const enabled = form.elements.namedItem('enabled');
            if (enabled && enabled.type === 'checkbox') values.enabled = Boolean(enabled.checked);

            try {
                sessionStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), values }));
            } catch (error) {
                // Browser storage may be disabled or full; server-side authorization still applies.
            }
        });
    });
})();
