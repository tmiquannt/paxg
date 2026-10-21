(() => {
    'use strict';

    const fieldsByForm = {
        payment: ['cash_account_id', 'amount_vnd', 'method', 'external_reference', 'paid_at', 'request_key'],
        expense: ['category', 'cash_account_id', 'amount_vnd', 'description', 'occurred_at', 'request_key'],
        'invoice-void': ['reason'],
        'reconciliation-create': ['cash_account_id', 'period_from', 'period_to', 'statement_opening_vnd', 'statement_closing_vnd', 'note'],
        'reconciliation-match': ['external_reference', 'external_direction'],
    };
    const limits = {
        cash_account_id: 18,
        amount_vnd: 20,
        method: 32,
        external_reference: 128,
        paid_at: 16,
        category: 64,
        description: 1000,
        occurred_at: 16,
        request_key: 36,
        reason: 1000,
        period_from: 10,
        period_to: 10,
        statement_opening_vnd: 20,
        statement_closing_vnd: 20,
        note: 1000,
        external_direction: 3,
    };
    const requestKeyPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
    const params = new URLSearchParams(window.location.search);
    const notice = params.get('notice') || '';
    const recoveryForm = params.get('recover_form') || '';
    const recoveryScope = params.get('recover_scope') || '';
    const recoveryFailureNotices = new Set(['invalid', 'conflict', 'unavailable']);
    const successNoticeByForm = {
        payment: 'posted',
        expense: 'expense_posted',
        'invoice-void': 'voided',
        'reconciliation-create': 'created',
        'reconciliation-match': 'matched',
    };

    if (recoveryScope && successNoticeByForm[recoveryForm] === notice) {
        const actor = document.querySelector('form[data-finance-form]')?.dataset.financeActor;
        if (actor) {
            try {
                sessionStorage.removeItem(`mini-erp-finance-form:${recoveryForm}:${actor}:${recoveryScope}`);
            } catch (error) {
                // Storage may be disabled by the browser.
            }
        }
    }

    document.querySelectorAll('form[data-finance-form]').forEach((form) => {
        const type = form.dataset.financeForm;
        const actor = form.dataset.financeActor;
        const scope = form.dataset.financeScope;
        const fields = fieldsByForm[type];
        if (!fields || !actor || !scope) return;

        const key = `mini-erp-finance-form:${type}:${actor}:${scope}`;
        const successNotice = successNoticeByForm[type];
        const matchingResponse = recoveryForm === type;

        if (matchingResponse && notice === successNotice) {
            try {
                sessionStorage.removeItem(key);
            } catch (error) {
                // Storage may be disabled by the browser.
            }
        } else if (matchingResponse && recoveryFailureNotices.has(notice)) {
            let saved = null;
            try {
                const raw = sessionStorage.getItem(key);
                sessionStorage.removeItem(key);
                if (raw && raw.length <= 4096) saved = JSON.parse(raw);
            } catch (error) {
                saved = null;
            }

            const age = saved && Number.isFinite(saved.savedAt) ? Date.now() - saved.savedAt : -1;
            if (saved && age >= 0 && age <= 15 * 60 * 1000 && saved.values && typeof saved.values === 'object') {
                let restored = false;
                fields.forEach((name) => {
                    const value = saved.values[name];
                    if (typeof value !== 'string' || value.length > limits[name]) return;
                    if (name === 'request_key' && !requestKeyPattern.test(value)) return;
                    const control = form.elements.namedItem(name);
                    if (control && typeof control.value === 'string') {
                        control.value = value;
                        restored = true;
                    }
                });
                if (restored) {
                    const status = document.createElement('p');
                    status.className = 'description';
                    status.setAttribute('role', 'status');
                    status.textContent = 'Đã khôi phục dữ liệu vừa nhập. Kiểm tra lại thông tin trước khi gửi lại.';
                    form.insertAdjacentElement('beforebegin', status);
                }
            }
        } else {
            try {
                sessionStorage.removeItem(key);
            } catch (error) {
                // Storage may be disabled by the browser.
            }
        }

        form.addEventListener('submit', () => {
            const values = {};
            fields.forEach((name) => {
                const control = form.elements.namedItem(name);
                if (control && typeof control.value === 'string' && control.value.length <= limits[name]) {
                    values[name] = control.value;
                }
            });

            try {
                sessionStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), values }));
            } catch (error) {
                // Storage may be disabled or full; server-side validation still applies.
            }
        });
    });
})();
