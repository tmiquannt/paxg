(() => {
    'use strict';

    const initializeOrderLines = (section) => {
        const addButton = section.parentElement.querySelector('[data-add-order-line]');
        const status = section.parentElement.querySelector('[data-order-lines-status]');
        const maxLines = Number.parseInt(section.dataset.maxLines || '20', 10);
        const lineLabel = section.dataset.lineLabel || 'Sản phẩm';

        if (!addButton || !status || !Number.isInteger(maxLines) || maxLines < 1) return;

        const rows = () => Array.from(section.querySelectorAll('[data-order-line]'));
        const update = () => {
            const currentRows = rows();
            currentRows.forEach((row, index) => {
                const number = index + 1;
                const title = row.querySelector('[data-line-title]');
                const search = row.querySelector('[data-product-search]');
                const removeButton = row.querySelector('[data-remove-order-line]');
                if (title) title.textContent = `Tìm ${lineLabel.toLowerCase()} ${number}`;
                if (search) search.setAttribute('aria-label', `Tìm sản phẩm ${number}`);
                if (removeButton) {
                    removeButton.hidden = false;
                    removeButton.disabled = currentRows.length <= 1;
                    removeButton.setAttribute('aria-label', `Xóa dòng ${lineLabel.toLowerCase()} ${number}`);
                }
            });

            addButton.hidden = false;
            addButton.disabled = currentRows.length >= maxLines;
            status.textContent = `${currentRows.length}/${maxLines} dòng. Có thể thêm hoặc xóa dòng trước khi lưu.`;
        };

        addButton.addEventListener('click', () => {
            const currentRows = rows();
            if (currentRows.length >= maxLines || currentRows.length === 0) return;

            const newRow = currentRows[0].cloneNode(true);
            newRow.querySelectorAll('select').forEach((select) => { select.selectedIndex = 0; });
            newRow.querySelectorAll('input').forEach((input) => { input.value = ''; });
            section.append(newRow);
            update();
            const firstControl = newRow.querySelector('[data-product-search]') || newRow.querySelector('select');
            if (firstControl) firstControl.focus();
        });

        section.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-order-line]');
            if (!removeButton || removeButton.disabled) return;
            const row = removeButton.closest('[data-order-line]');
            if (row) row.remove();
            update();
        });

        update();
    };

    const requestLookup = async (action, nonce, query, signal, extra = {}) => {
        const body = new URLSearchParams({ action, nonce, q: query, page: '1', page_size: '100', ...extra });
        const response = await fetch(MiniErpOrderLookup.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body,
            signal,
        });
        const payload = await response.json();
        if (!response.ok || !payload || !Array.isArray(payload.items)) {
            throw new Error(typeof payload?.message === 'string' ? payload.message : 'Không thể tra cứu lúc này.');
        }
        return payload;
    };

    const setOptions = (select, items, makeLabel) => {
        const selectedValue = select.value;
        const selectedOption = select.selectedOptions[0]?.cloneNode(true);
        select.replaceChildren(new Option('— Chọn —', ''));
        items.forEach((item) => select.add(new Option(makeLabel(item), String(item.id))));
        if (selectedValue && !items.some((item) => String(item.id) === selectedValue) && selectedOption) {
            select.add(selectedOption);
        }
        select.value = selectedValue;
    };

    const formatInteger = (value) => String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const formatQuantity = (value) => {
        const quantity = String(value);
        return quantity.includes('.') ? quantity.replace(/0+$/, '').replace(/\.$/, '') : quantity;
    };

    const initializeProductLookup = (section) => {
        const timers = new WeakMap();
        const requests = new WeakMap();
        const warehouse = section.closest('form')?.querySelector('[data-product-warehouse]');
        const lookup = (input) => {
            const row = input.closest('[data-order-line]');
            const select = row?.querySelector('[data-product-select]');
            const status = row?.querySelector('[data-product-search-status]');
            if (!select || !status) return;
            clearTimeout(timers.get(input));
            requests.get(input)?.abort();
            const query = input.value.trim();
            timers.set(input, setTimeout(async () => {
                const controller = new AbortController();
                requests.set(input, controller);
                status.textContent = 'Đang tìm sản phẩm…';
                try {
                    const extra = warehouse?.value ? { warehouse_id: warehouse.value } : {};
                    const payload = await requestLookup('erp_search_products', MiniErpOrderLookup.productNonce, query, controller.signal, extra);
                    setOptions(select, payload.items, (item) => {
                        const parts = [item.sku, item.name, item.unit];
                        if (item.sale_price_vnd !== undefined) parts.push(`${formatInteger(item.sale_price_vnd)} VND`);
                        if (item.available_qty !== undefined) parts.push(`Tồn hiện tại: ${formatQuantity(item.available_qty)}`);
                        return parts.filter(Boolean).join(' · ');
                    });
                    const resultLabel = payload.total > payload.items.length
                        ? `Hiển thị ${payload.items.length} / ${payload.total} sản phẩm. Thêm từ khóa để thu hẹp kết quả.`
                        : `${payload.total} sản phẩm phù hợp.`;
                    status.textContent = !warehouse
                        ? resultLabel
                        : warehouse.value
                            ? `${resultLabel} Tồn hiện tại theo kho đang chọn.`
                            : `${resultLabel} Chọn kho để xem tồn hiện tại.`;
                } catch (error) {
                    if (error.name !== 'AbortError') status.textContent = error.message || 'Không thể tra cứu sản phẩm lúc này.';
                }
            }, 250));
        };
        section.addEventListener('keydown', (event) => {
            if (event.target.matches('[data-product-search]') && event.key === 'Enter') event.preventDefault();
        });
        section.addEventListener('input', (event) => {
            const input = event.target.closest('[data-product-search]');
            if (!input) return;
            lookup(input);
        });
        warehouse?.addEventListener('change', () => {
            section.querySelectorAll('[data-product-search]').forEach((input) => {
                const row = input.closest('[data-order-line]');
                const select = row?.querySelector('[data-product-select]');
                if (!input.value.trim() && !select?.value) return;
                if (!input.value.trim() && select?.value) input.value = select.selectedOptions[0]?.textContent.trim().slice(0, 100) || '';
                lookup(input);
            });
        });
    };

    const initializeCustomerLookup = (form) => {
        const input = form.querySelector('[data-customer-search]');
        const select = form.querySelector('[data-customer-select]');
        const status = form.querySelector('[data-customer-search-status]');
        if (!input || !select || !status) return;
        let timer = 0;
        let request = null;
        input.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            request?.abort();
            const query = input.value.trim();
            timer = window.setTimeout(async () => {
                request = new AbortController();
                status.textContent = 'Đang tìm khách hàng…';
                try {
                    const payload = await requestLookup('erp_search_customers', MiniErpOrderLookup.customerNonce, query, request.signal);
                    setOptions(select, payload.items, (item) => `${item.code} · ${item.name}`);
                    status.textContent = payload.total > payload.items.length
                        ? `Hiển thị ${payload.items.length} / ${payload.total} khách hàng. Thêm từ khóa để thu hẹp kết quả.`
                        : `${payload.total} khách hàng phù hợp.`;
                } catch (error) {
                    if (error.name !== 'AbortError') status.textContent = error.message || 'Không thể tra cứu khách hàng lúc này.';
                }
            }, 250);
        });
    };

    const initializeSupplierLookup = (form) => {
        const input = form.querySelector('[data-supplier-search]');
        const select = form.querySelector('[data-supplier-select]');
        const status = form.querySelector('[data-supplier-search-status]');
        if (!input || !select || !status) return;
        let timer = 0;
        let request = null;
        input.addEventListener('keydown', (event) => { if (event.key === 'Enter') event.preventDefault(); });
        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            request?.abort();
            const query = input.value.trim();
            timer = window.setTimeout(async () => {
                request = new AbortController();
                status.textContent = 'Đang tìm nhà cung cấp…';
                try {
                    const payload = await requestLookup('erp_search_suppliers', MiniErpOrderLookup.supplierNonce, query, request.signal);
                    setOptions(select, payload.items, (item) => `${item.code} · ${item.name}`);
                    status.textContent = payload.total > payload.items.length
                        ? `Hiển thị ${payload.items.length} / ${payload.total} nhà cung cấp. Thêm từ khóa để thu hẹp kết quả.`
                        : `${payload.total} nhà cung cấp phù hợp.`;
                } catch (error) {
                    if (error.name !== 'AbortError') status.textContent = error.message || 'Không thể tra cứu nhà cung cấp lúc này.';
                }
            }, 250);
        });
    };

    const initializeOrderFormState = (section) => {
        const form = section.closest('form[data-order-form-state]');
        if (!form) return;

        const module = form.dataset.orderFormState;
        const actor = form.dataset.orderActor;
        if (!module || !actor) return;
        const key = `mini-erp-order-form:${module}:${actor}`;
        const params = new URLSearchParams(window.location.search);
        const notice = params.get('notice') || '';
        const recoverable = params.get('recover_form') === module
            && ['invalid', 'duplicate', 'conflict', 'unavailable'].includes(notice);
        let saved = null;

        try {
            if (recoverable) {
                const raw = sessionStorage.getItem(key);
                sessionStorage.removeItem(key);
                if (raw) saved = JSON.parse(raw);
            } else {
                sessionStorage.removeItem(key);
            }
        } catch (error) {
            saved = null;
        }

        if (saved && Number.isFinite(saved.savedAt) && Date.now() - saved.savedAt <= 15 * 60 * 1000
            && saved.singles && Array.isArray(saved.productIds) && Array.isArray(saved.quantities)
            && Array.isArray(saved.unitCosts) && saved.productIds.length === saved.quantities.length
            && (module !== 'purchase' || saved.productIds.length === saved.unitCosts.length)
            && saved.productIds.length <= Number.parseInt(section.dataset.maxLines || '20', 10)) {
            const details = form.closest('details');
            if (details) details.open = true;

            Object.entries(saved.singles).forEach(([name, value]) => {
                const control = form.elements.namedItem(name);
                if (control && typeof value === 'string') control.value = value;
            });

            const addButton = form.querySelector('[data-add-order-line]');
            while (section.querySelectorAll('[data-order-line]').length < saved.productIds.length && addButton && !addButton.disabled) {
                addButton.click();
            }

            const rows = Array.from(section.querySelectorAll('[data-order-line]'));
            saved.productIds.forEach((value, index) => {
                if (index >= rows.length) return;
                const select = rows[index].querySelector('select[name="product_id[]"]');
                const quantity = rows[index].querySelector('input[name="quantity[]"]');
                const cost = rows[index].querySelector('input[name="unit_cost_vnd[]"]');
                if (select && typeof value === 'string') select.value = value;
                if (quantity && typeof saved.quantities[index] === 'string') quantity.value = saved.quantities[index];
                if (cost && typeof saved.unitCosts[index] === 'string') cost.value = saved.unitCosts[index];
            });
        }

        form.addEventListener('submit', () => {
            const singleNames = ['customer_id', 'supplier_id', 'warehouse_id', 'discount_bps', 'note'];
            const singles = {};
            singleNames.forEach((name) => {
                const control = form.elements.namedItem(name);
                if (control && typeof control.value === 'string') singles[name] = control.value;
            });
            const values = (name) => Array.from(section.querySelectorAll(`[name="${name}"]`), (control) => control.value);
            const payload = {
                savedAt: Date.now(),
                singles,
                productIds: values('product_id[]'),
                quantities: values('quantity[]'),
                unitCosts: values('unit_cost_vnd[]'),
            };
            try {
                sessionStorage.setItem(key, JSON.stringify(payload));
            } catch (error) {
                // Browser storage can be disabled or full; server validation still works.
            }
        });
    };

    document.querySelectorAll('[data-order-lines]').forEach((section) => {
        initializeOrderLines(section);
        if (window.MiniErpOrderLookup?.ajaxUrl) initializeProductLookup(section);
        initializeOrderFormState(section);
        const form = section.closest('form');
        initializeCustomerLookup(form);
        initializeSupplierLookup(form);
    });
})();
