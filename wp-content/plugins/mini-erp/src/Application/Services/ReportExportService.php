<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use RuntimeException;
use MiniERP\Security\Actor;
use wpdb;

/** Bounded CSV projections that mirror the report screen's role and row scopes. */
final class ReportExportService
{
    public const MAX_ROWS = 5000;
    private const TIMEZONE = 'Asia/Ho_Chi_Minh';
    private const EFFECTIVE_PAYMENTS = "'POSTED','VOID_PENDING','VOID_APPROVED'";

    public function __construct(private readonly wpdb $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function rows(Actor $actor, string $view, string $from, string $to, string $asOf): array
    {
        return match ($view) {
            'receivables' => $this->receivables($actor, $asOf),
            'cash' => $this->cash($actor, $from, $to),
            'revenue' => $this->revenue($actor, $from, $to),
            'stock' => $this->stock($actor),
            default => throw new DomainException('Báo cáo CSV không hợp lệ.'),
        };
    }

    /** @return list<array<string, mixed>> */
    private function receivables(Actor $actor, string $asOf): array
    {
        if (!$actor->can('erp_reports_receivables')) {
            throw new DomainException('Bạn không có quyền xuất báo cáo công nợ.');
        }
        $this->assertDate($asOf);
        $invoices = $this->table('erp_invoices');
        $payments = $this->table('erp_payments');
        $scope = '';
        $args = [$asOf];
        if (!$actor->can('erp_invoice_sources_view_all')) {
            if (!$actor->can('erp_invoice_sources_view_own')) {
                throw new DomainException('Bạn không có quyền xuất báo cáo công nợ.');
            }
            $shipments = $this->table('erp_shipments');
            $orders = $this->table('erp_sales_orders');
            $scope = " AND i.source_type='SHIPMENT' AND EXISTS (SELECT 1 FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id WHERE sh.id=i.source_id AND so.created_by=%d)";
            $args[] = $actor->id;
        }
        $sql = "SELECT i.invoice_no,i.buyer_name,i.issued_at,DATE(DATE_ADD(i.due_at,INTERVAL 7 HOUR)) due_date,i.total_vnd,COALESCE(p.effective_paid_vnd,0) effective_paid_vnd,GREATEST(i.total_vnd-COALESCE(p.effective_paid_vnd,0),0) outstanding_vnd,GREATEST(DATEDIFF(%s,DATE(DATE_ADD(i.due_at,INTERVAL 7 HOUR))),0) days_overdue FROM {$invoices} i LEFT JOIN (SELECT invoice_id,SUM(amount_vnd) effective_paid_vnd FROM {$payments} WHERE status IN (" . self::EFFECTIVE_PAYMENTS . ") GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.direction='IN' AND i.status='ISSUED' AND GREATEST(i.total_vnd-COALESCE(p.effective_paid_vnd,0),0)>0{$scope} ORDER BY days_overdue DESC,i.due_at,i.id LIMIT %d";
        return $this->limited($this->db->prepare($sql, [...$args, self::MAX_ROWS + 1]));
    }

    /** @return list<array<string, mixed>> */
    private function cash(Actor $actor, string $from, string $to): array
    {
        if (!$actor->can('erp_reports_finance')) {
            throw new DomainException('Bạn không có quyền xuất báo cáo sổ quỹ.');
        }
        [$fromUtc, $toUtc] = $this->utcRange($from, $to);
        if ($actor->cashAccountIds === []) {
            return [];
        }
        $slots = implode(',', array_fill(0, count($actor->cashAccountIds), '%d'));
        $accounts = $this->table('erp_cash_accounts');
        $movements = $this->table('erp_cash_movements');
        $sql = "SELECT m.occurred_at,a.account_code,a.name account_name,m.direction,m.source_type,m.source_id,m.external_reference,m.amount_vnd FROM {$movements} m JOIN {$accounts} a ON a.id=m.cash_account_id WHERE m.cash_account_id IN ({$slots}) AND m.occurred_at>=%s AND m.occurred_at<%s ORDER BY m.occurred_at,m.id LIMIT %d";
        return $this->limited($this->db->prepare($sql, [...$actor->cashAccountIds, $fromUtc, $toUtc, self::MAX_ROWS + 1]));
    }

    /** @return list<array<string, mixed>> */
    private function revenue(Actor $actor, string $from, string $to): array
    {
        if (!$actor->can('erp_reports_sales')) {
            throw new DomainException('Bạn không có quyền xuất báo cáo doanh thu.');
        }
        [$fromUtc, $toUtc] = $this->utcRange($from, $to);
        $invoices = $this->table('erp_invoices');
        $sql = "SELECT invoice_no,buyer_name,issued_at,due_at,total_vnd FROM {$invoices} WHERE source_type='SHIPMENT' AND direction='IN' AND status='ISSUED' AND issued_at>=%s AND issued_at<%s ORDER BY issued_at,invoice_no LIMIT %d";
        return $this->limited($this->db->prepare($sql, [$fromUtc, $toUtc, self::MAX_ROWS + 1]));
    }

    /** @return list<array<string, mixed>> */
    private function stock(Actor $actor): array
    {
        if (!$actor->can('erp_reports_stock')) {
            throw new DomainException('Bạn không có quyền xuất báo cáo tồn kho.');
        }
        $allWarehouses = $actor->can('erp_stock_adjust_approve');
        if (!$allWarehouses && $actor->warehouseIds === []) {
            return [];
        }
        $balances = $this->table('erp_stock_balances');
        $products = $this->table('erp_products');
        $warehouses = $this->table('erp_warehouses');
        $scope = '';
        $args = [];
        if (!$allWarehouses) {
            $scope = ' WHERE b.warehouse_id IN (' . implode(',', array_fill(0, count($actor->warehouseIds), '%d')) . ')';
            $args = $actor->warehouseIds;
        }
        $sql = "SELECT w.code warehouse_code,w.name warehouse_name,p.sku,p.name product_name,p.unit,b.quantity,b.updated_at FROM {$balances} b JOIN {$products} p ON p.id=b.product_id JOIN {$warehouses} w ON w.id=b.warehouse_id{$scope} ORDER BY w.code,p.sku LIMIT %d";
        return $this->limited($this->db->prepare($sql, [...$args, self::MAX_ROWS + 1]));
    }

    /** @return list<array<string, mixed>> */
    private function limited(string $sql): array
    {
        $rows = $this->db->get_results($sql, ARRAY_A);
        if ($this->db->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('A report export query failed.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new DomainException('Báo cáo vượt giới hạn 5.000 dòng. Hãy thu hẹp bộ lọc ngày rồi xuất lại.');
        }
        return $rows;
    }

    /** @return array{0:string,1:string} */
    private function utcRange(string $from, string $to): array
    {
        $this->assertDate($from);
        $this->assertDate($to);
        if ($to < $from) {
            throw new DomainException('Khoảng ngày báo cáo không hợp lệ.');
        }
        $local = new DateTimeZone(self::TIMEZONE);
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($from . ' 00:00:00', $local))->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $end = (new DateTimeImmutable($to . ' 00:00:00', $local))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s.u');
        return [$start, $end];
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::TIMEZONE));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Ngày báo cáo không hợp lệ.');
        }
    }

    private function table(string $suffix): string
    {
        $allowed = ['erp_invoices', 'erp_payments', 'erp_shipments', 'erp_sales_orders', 'erp_cash_accounts', 'erp_cash_movements', 'erp_stock_balances', 'erp_products', 'erp_warehouses'];
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $this->db->prefix) || !in_array($suffix, $allowed, true)) {
            throw new DomainException('Cấu hình bảng dữ liệu không hợp lệ.');
        }
        return '`' . $this->db->prefix . $suffix . '`';
    }
}
