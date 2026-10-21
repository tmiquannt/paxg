<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use MiniERP\Security\Actor;
use RuntimeException;
use wpdb;

/** Immutable print projections built from posted finance documents and their ledger rows. */
final class CashDocumentService
{
    public function __construct(private readonly wpdb $db) {}

    /** @return array<string,mixed> */
    public function paymentVoucher(Actor $actor, int $paymentId): array
    {
        if (!$actor->can('erp_payments_view')
            || !$actor->can('erp_cash_accounts_view')
            || $paymentId < 1
            || $actor->cashAccountIds === []) {
            throw new DomainException('Bạn không có quyền xem phiếu thu chi này.');
        }

        $payments = $this->table('erp_payments');
        $accounts = $this->table('erp_cash_accounts');
        $slots = implode(',', array_fill(0, count($actor->cashAccountIds), '%d'));
        $rows = $this->rows($this->db->prepare(
            "SELECT p.id,p.payment_no,p.invoice_id,p.direction,p.amount_vnd,p.cash_account_id,p.method,p.external_reference,p.paid_at,p.status,p.created_by,p.void_approved_by,p.void_approved_at,p.void_applied_by,a.account_code,a.name AS account_name,a.type AS account_type,a.currency FROM {$payments} p JOIN {$accounts} a ON a.id=p.cash_account_id WHERE p.id=%d AND p.cash_account_id IN ({$slots})",
            [$paymentId, ...$actor->cashAccountIds]
        ));
        $payment = $rows[0] ?? null;
        if (!is_array($payment)) {
            throw new DomainException('Không tìm thấy phiếu thu chi hoặc bạn không có quyền truy cập.');
        }

        $status = (string) $payment['status'];
        if (!in_array($status, ['POSTED', 'VOID_PENDING', 'VOID_APPROVED', 'VOID'], true)) {
            throw new DomainException('Trạng thái phiếu thu chi không hợp lệ.');
        }

        $invoiceModel = (new InvoiceDocumentService($this->db))->viewModel($actor, (int) $payment['invoice_id']);
        $invoice = $invoiceModel['invoice'] ?? null;
        if (!is_array($invoice)
            || (int) ($invoice['id'] ?? 0) !== (int) $payment['invoice_id']
            || ($invoice['status'] ?? null) !== 'ISSUED'
            || ($invoice['direction'] ?? null) !== $payment['direction']) {
            throw new DomainException('Nguồn hóa đơn của phiếu không còn hợp lệ.');
        }

        $movement = $this->oneMovement('PAYMENT', $paymentId);
        $this->assertMovement($movement, $payment, 'PAYMENT_POST:' . $paymentId, (string) $payment['direction']);
        $movements = $this->table('erp_cash_movements');
        $reversalRows = $this->rows($this->db->prepare(
            "SELECT id,event_key,cash_account_id,source_type,source_id,direction,amount_vnd,occurred_at,actor_id,external_reference FROM {$movements} WHERE source_type='PAYMENT_VOID' AND source_id=%d ORDER BY id",
            [$paymentId]
        ));

        $void = null;
        if ($status === 'VOID') {
            $reversal = $reversalRows[0] ?? null;
            if (count($reversalRows) !== 1
                || !is_array($reversal)
                || (int) ($payment['void_approved_by'] ?? 0) < 1
                || trim((string) ($payment['void_approved_at'] ?? '')) === ''
                || (int) ($payment['void_applied_by'] ?? 0) < 1) {
                throw new DomainException('Thông tin hủy phiếu không đầy đủ.');
            }
            $reverseDirection = $payment['direction'] === 'IN' ? 'OUT' : 'IN';
            $this->assertMovement($reversal, $payment, 'PAYMENT_VOID:' . $paymentId, $reverseDirection, false, false);
            if ((int) $reversal['actor_id'] !== (int) $payment['void_applied_by']
                || strcmp((string) $reversal['occurred_at'], (string) $payment['void_approved_at']) < 0) {
                throw new DomainException('Bút toán đảo của phiếu không khớp lịch sử phê duyệt.');
            }
            $void = [
                'approved_by' => (int) $payment['void_approved_by'],
                'approved_at' => (string) $payment['void_approved_at'],
                'applied_by' => (int) $payment['void_applied_by'],
                'reversed_at' => (string) $reversal['occurred_at'],
            ];
        } elseif ($reversalRows !== []) {
            throw new DomainException('Phiếu chưa hủy nhưng đã có bút toán đảo.');
        }

        $direction = (string) $payment['direction'];
        return [
            'kind' => 'PAYMENT',
            'record_id' => $paymentId,
            'document_no' => (string) $payment['payment_no'],
            'title' => $direction === 'IN' ? 'PHIẾU THU THANH TOÁN HÓA ĐƠN' : 'PHIẾU CHI THANH TOÁN HÓA ĐƠN',
            'direction' => $direction,
            'amount_vnd' => (int) $payment['amount_vnd'],
            'occurred_at' => (string) $payment['paid_at'],
            'status' => $status,
            'prepared_by' => (int) $payment['created_by'],
            'method' => (string) $payment['method'],
            'external_reference' => (string) $payment['external_reference'],
            'account' => $this->accountProjection($payment),
            'source' => [
                'document_no' => (string) $invoice['invoice_no'],
                'document_date' => (string) $invoice['issued_at'],
                'source_type' => (string) $invoice['source_type'],
                'buyer_name' => (string) $invoice['buyer_name'],
                'seller_name' => (string) $invoice['seller_name'],
                'total_vnd' => (int) $invoice['total_vnd'],
            ],
            'void' => $void,
        ];
    }

    /** @return array<string,mixed> */
    public function expenseVoucher(Actor $actor, int $expenseId): array
    {
        if ((!$actor->can('erp_expenses_manage') && !$actor->can('erp_reports_finance'))
            || !$actor->can('erp_cash_accounts_view')
            || $expenseId < 1
            || $actor->cashAccountIds === []) {
            throw new DomainException('Bạn không có quyền xem phiếu chi này.');
        }

        $expenses = $this->table('erp_expenses');
        $accounts = $this->table('erp_cash_accounts');
        $slots = implode(',', array_fill(0, count($actor->cashAccountIds), '%d'));
        $rows = $this->rows($this->db->prepare(
            "SELECT e.id,e.expense_no,e.category,e.description,e.amount_vnd,e.occurred_at,e.recorded_by,e.cash_account_id,e.status,a.account_code,a.name AS account_name,a.type AS account_type,a.currency FROM {$expenses} e JOIN {$accounts} a ON a.id=e.cash_account_id WHERE e.id=%d AND e.cash_account_id IN ({$slots})",
            [$expenseId, ...$actor->cashAccountIds]
        ));
        $expense = $rows[0] ?? null;
        if (!is_array($expense) || $expense['status'] !== 'POSTED') {
            throw new DomainException('Không tìm thấy phiếu chi hoặc bạn không có quyền truy cập.');
        }

        $movement = $this->oneMovement('EXPENSE', $expenseId);
        $this->assertMovement($movement, $expense, 'EXPENSE_POST:' . $expenseId, 'OUT');
        if ((int) $movement['actor_id'] !== (int) $expense['recorded_by']) {
            throw new DomainException('Người ghi sổ của phiếu chi không khớp nguồn.');
        }

        return [
            'kind' => 'EXPENSE',
            'record_id' => $expenseId,
            'document_no' => (string) $expense['expense_no'],
            'title' => 'PHIẾU CHI',
            'direction' => 'OUT',
            'amount_vnd' => (int) $expense['amount_vnd'],
            'occurred_at' => (string) $expense['occurred_at'],
            'status' => 'POSTED',
            'prepared_by' => (int) $expense['recorded_by'],
            'category' => (string) $expense['category'],
            'description' => (string) $expense['description'],
            'account' => $this->accountProjection($expense),
            'source' => null,
            'void' => null,
        ];
    }

    /** Render an escaped, self-contained Vietnamese document. */
    public function html(array $voucher, bool $withPrintButton = true): string
    {
        if (!in_array($voucher['kind'] ?? null, ['PAYMENT', 'EXPENSE'], true)
            || !is_array($voucher['account'] ?? null)
            || !is_int($voucher['amount_vnd'] ?? null)) {
            throw new DomainException('Dữ liệu phiếu không hợp lệ.');
        }

        $h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = static fn(mixed $value): string => number_format((int) $value, 0, ',', '.');
        $status = match ($voucher['status'] ?? '') {
            'VOID' => '<strong class="void">ĐÃ HỦY</strong>',
            'VOID_PENDING' => '<strong class="pending">ĐANG CHỜ DUYỆT HỦY</strong>',
            'VOID_APPROVED' => '<strong class="pending">ĐÃ DUYỆT HỦY — CHỜ ÁP DỤNG</strong>',
            default => '<strong class="posted">ĐÃ GHI SỔ</strong>',
        };
        $account = $voucher['account'];
        $html = '<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . $h($voucher['document_no'] ?? '') . '</title><style>
@page{size:A4;margin:18mm 16mm}*{box-sizing:border-box}body{font:11pt "DejaVu Sans",sans-serif;color:#172033;margin:0}header{border-bottom:2px solid #243958;padding-bottom:14px;margin-bottom:18px}.brand{font-size:9pt;letter-spacing:.08em;color:#596579;font-weight:bold}h1{font-size:17pt;line-height:1.35;margin:8px 0}.meta{color:#596579;line-height:1.65}.status{margin:14px 0}.posted{color:#146b4d}.pending{color:#8a4b12}.void{color:#a32424;font-size:17pt}.panel{border:1px solid #d6dce5;border-radius:6px;padding:12px 14px;margin:12px 0}.panel h2{font-size:10pt;text-transform:uppercase;color:#596579;margin:0 0 8px}.row{display:table;width:100%;border-collapse:collapse}.row>*{display:table-cell;vertical-align:top;padding:4px 0}.row>*:last-child{text-align:right;padding-left:18px}.amount{font-size:22pt;font-weight:bold;color:#172033;text-align:right;margin:20px 0}.foot{margin-top:24px;padding-top:10px;border-top:1px solid #d6dce5;color:#596579;font-size:9pt}.print{margin:20px 0}@media print{.print{display:none}}
</style></head><body><header><div class="brand">MINI ERP · CHỨNG TỪ NỘI BỘ</div><h1>' . $h($voucher['title'] ?? '') . '</h1><div class="meta">Số phiếu: ' . $h($voucher['document_no'] ?? '') . '<br>Ngày giao dịch: ' . $h($voucher['occurred_at'] ?? '') . '</div><div class="status">' . $status . '</div></header>';

        if (($voucher['kind'] ?? '') === 'PAYMENT') {
            $source = $voucher['source'] ?? null;
            if (!is_array($source)) {
                throw new DomainException('Thiếu nguồn hóa đơn của phiếu thanh toán.');
            }
            $html .= '<section class="panel"><h2>Hóa đơn nguồn</h2><div class="row"><span>Số hóa đơn</span><strong>' . $h($source['document_no'] ?? '') . '</strong></div><div class="row"><span>Ngày phát hành</span><span>' . $h($source['document_date'] ?? '') . '</span></div><div class="row"><span>Bên bán</span><span>' . $h($source['seller_name'] ?? '') . '</span></div><div class="row"><span>Bên mua</span><span>' . $h($source['buyer_name'] ?? '') . '</span></div><div class="row"><span>Giá trị hóa đơn</span><span>' . $money($source['total_vnd'] ?? 0) . ' ₫</span></div></section>';
            $html .= '<section class="panel"><h2>Giao dịch</h2><div class="row"><span>Loại</span><span>' . $h($voucher['direction'] === 'IN' ? 'Thu tiền' : 'Chi tiền') . '</span></div><div class="row"><span>Phương thức</span><span>' . $h($voucher['method'] ?? '') . '</span></div><div class="row"><span>Mã giao dịch ngoài</span><span>' . $h(($voucher['external_reference'] ?? '') !== '' ? $voucher['external_reference'] : '—') . '</span></div></section>';
        } else {
            $html .= '<section class="panel"><h2>Nội dung chi</h2><div class="row"><span>Danh mục</span><strong>' . $h($voucher['category'] ?? '') . '</strong></div><div class="row"><span>Diễn giải</span><span>' . $h($voucher['description'] ?? '') . '</span></div></section>';
        }

        $html .= '<section class="panel"><h2>Quỹ được phân quyền</h2><div class="row"><span>Mã quỹ</span><strong>' . $h($account['account_code'] ?? '') . '</strong></div><div class="row"><span>Tên quỹ / tài khoản</span><span>' . $h($account['name'] ?? '') . '</span></div><div class="row"><span>Loại</span><span>' . $h($account['type'] ?? '') . '</span></div></section><div class="amount">' . $money($voucher['amount_vnd']) . ' ₫</div><div class="meta">Người ghi nhận: #' . $h($voucher['prepared_by'] ?? '') . '</div>';
        if (is_array($voucher['void'] ?? null)) {
            $void = $voucher['void'];
            $html .= '<section class="panel"><h2>Lịch sử hủy</h2><div class="row"><span>Người phê duyệt</span><strong>#' . $h($void['approved_by'] ?? '') . '</strong></div><div class="row"><span>Thời điểm phê duyệt</span><span>' . $h($void['approved_at'] ?? '') . '</span></div><div class="row"><span>Thời điểm đảo sổ</span><span>' . $h($void['reversed_at'] ?? '') . '</span></div></section>';
        }
        $html .= '<div class="foot">Chứng từ nội bộ được tạo từ giao dịch đã ghi sổ và dòng sổ quỹ tương ứng. Không thay thế hóa đơn điện tử hoặc chứng từ kế toán theo quy định.</div>';
        if ($withPrintButton) {
            $html .= '<div class="print"><button type="button" onclick="window.print()">In / lưu PDF</button></div>';
        }
        return $html . '</body></html>';
    }

    public function pdf(array $voucher): string
    {
        if (!class_exists(Dompdf::class) || !class_exists(Options::class)) {
            throw new RuntimeException('PDF renderer is unavailable.');
        }
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', [__DIR__]);
        $renderer = new Dompdf($options);
        $renderer->setPaper('A4', 'portrait');
        $renderer->loadHtml($this->html($voucher, false), 'UTF-8');
        $renderer->render();
        return $renderer->output();
    }

    /** @return array<string,mixed> */
    private function oneMovement(string $sourceType, int $sourceId): array
    {
        $movements = $this->table('erp_cash_movements');
        $rows = $this->rows($this->db->prepare(
            "SELECT id,event_key,cash_account_id,source_type,source_id,direction,amount_vnd,occurred_at,actor_id,external_reference FROM {$movements} WHERE source_type=%s AND source_id=%d ORDER BY id",
            [$sourceType, $sourceId]
        ));
        if (count($rows) !== 1 || !is_array($rows[0] ?? null)) {
            throw new DomainException('Phiếu không có đúng một bút toán sổ quỹ tương ứng.');
        }
        return $rows[0];
    }

    private function assertMovement(
        array $movement,
        array $source,
        string $eventKey,
        string $direction,
        bool $checkDate = true,
        bool $checkActor = true
    ): void
    {
        if (($movement['event_key'] ?? null) !== $eventKey
            || (int) ($movement['cash_account_id'] ?? 0) !== (int) ($source['cash_account_id'] ?? 0)
            || (int) ($movement['source_id'] ?? 0) !== (int) ($source['id'] ?? 0)
            || ($movement['direction'] ?? null) !== $direction
            || (int) ($movement['amount_vnd'] ?? 0) !== (int) ($source['amount_vnd'] ?? 0)
            || (string) ($movement['external_reference'] ?? '') !== (string) ($source['external_reference'] ?? '')) {
            throw new DomainException('Dòng sổ quỹ không khớp phiếu nguồn.');
        }

        $sourceDate = (string) ($source['paid_at'] ?? $source['occurred_at'] ?? '');
        if ($checkDate && $sourceDate !== '' && (string) ($movement['occurred_at'] ?? '') !== $sourceDate) {
            throw new DomainException('Ngày giao dịch trên sổ quỹ không khớp phiếu nguồn.');
        }

        if ($checkActor && isset($source['created_by']) && (int) $movement['actor_id'] !== (int) $source['created_by']) {
            throw new DomainException('Người ghi sổ của phiếu thanh toán không khớp nguồn.');
        }
    }

    /** @return array<string,mixed> */
    private function accountProjection(array $row): array
    {
        return [
            'account_code' => (string) $row['account_code'],
            'name' => (string) $row['account_name'],
            'type' => (string) $row['account_type'],
            'currency' => (string) $row['currency'],
        ];
    }

    private function table(string $suffix): string
    {
        $allowed = ['erp_payments', 'erp_expenses', 'erp_cash_accounts', 'erp_cash_movements'];
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $this->db->prefix) || !in_array($suffix, $allowed, true)) {
            throw new DomainException('Cấu hình bảng dữ liệu không hợp lệ.');
        }
        return '`' . $this->db->prefix . $suffix . '`';
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $rows = $this->db->get_results($sql, ARRAY_A);
        if ($this->db->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Cash document query failed.');
        }
        return $rows;
    }
}
