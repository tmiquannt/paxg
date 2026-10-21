(() => {
    const tables = document.querySelectorAll('.erp-app table.widefat, .erp-bootstrap-page table.widefat');

    const statusClass = (value) => {
        const status = value.trim().toLocaleLowerCase('vi-VN');
        if (/chờ|nháp|một phần|đang kiểm kê|void_pending|pending|draft|partial|review/.test(status)) {
            return 'is-warning';
        }
        if (/hủy|huỷ|từ chối|thất bại|cancel|void|reject|failed|error/.test(status)) {
            return 'is-danger';
        }
        if (/xác nhận|đã duyệt|confirmed|approved/.test(status)) {
            return 'is-info';
        }
        if (/đã ghi sổ|đã phát hành|đã giao|đã nhận|đang dùng|đang hoạt động|posted|issued|shipped|received|active|success/.test(status)) {
            return 'is-success';
        }
        return 'is-neutral';
    };

    tables.forEach((table) => {
        const headers = Array.from(table.querySelectorAll('thead th'), (header) => {
            if (!header.hasAttribute('scope')) header.setAttribute('scope', 'col');
            return header.textContent.replace(/\s+/g, ' ').trim();
        });
        const statusColumn = headers.findIndex((header) => /^(trạng thái|tình trạng|status)$/i.test(header));
        const isStacked = table.closest('.erp-table-stack') !== null;

        table.querySelectorAll('tbody tr').forEach((row) => {
            Array.from(row.cells).forEach((cell, index) => {
                if (isStacked && cell.colSpan > 1) cell.classList.add('erp-table-empty');
                if (isStacked && cell.colSpan < 2 && !cell.hasAttribute('data-label')) {
                    cell.setAttribute('data-label', headers[index] || '');
                }

                if (index !== statusColumn || cell.colSpan > 1 || cell.querySelector('.erp-status-pill')) return;
                const value = cell.textContent.replace(/\s+/g, ' ').trim();
                if (!value) return;

                const pill = document.createElement('span');
                pill.className = `erp-status-pill ${statusClass(value)}`;
                while (cell.firstChild) pill.append(cell.firstChild);
                cell.append(pill);
            });
        });

        const scrollRegion = table.closest('.erp-table-scroll');
        if (scrollRegion && !table.closest('.erp-table-stack')) {
            scrollRegion.setAttribute('role', 'region');
            scrollRegion.setAttribute('tabindex', '0');
            if (!scrollRegion.hasAttribute('aria-label')) {
                scrollRegion.setAttribute('aria-label', 'Bảng dữ liệu có thể cuộn ngang');
            }
        }
    });
})();
