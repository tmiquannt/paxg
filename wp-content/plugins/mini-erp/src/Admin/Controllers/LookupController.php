<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use RuntimeException;
use Throwable;
use wpdb;

/** Authenticated, bounded lookup endpoints used by the order editors. */
final class LookupController
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;
    private const MAX_PAGE = 10000;
    private const MAX_REQUESTS_PER_MINUTE = 120;

    public static function searchProducts(): void
    {
        self::handle('erp_search_products', 'erp_products_view', static function (Actor $actor, wpdb $db): array {
            $query = self::queryText();
            [$page, $pageSize, $offset] = self::pagination();
            $warehouseId = self::optionalPositiveInt('warehouse_id');
            $products = self::table($db, 'erp_products');
            $where = 'p.is_active=1';
            $whereArgs = [];
            if ($query !== '') {
                $like = '%' . $db->esc_like($query) . '%';
                $where .= ' AND (p.sku LIKE %s OR p.name LIKE %s)';
                $whereArgs = [$like, $like];
            }

            $total = (int) self::value($db, "SELECT COUNT(*) FROM {$products} p WHERE {$where}", $whereArgs);
            $columns = 'p.id,p.sku,p.name,p.unit,p.unit_step';
            if ($actor->can('erp_sales_create')) {
                $columns .= ',p.sale_price_vnd';
            }
            $joins = '';
            $selectArgs = [];
            if ($warehouseId !== null) {
                self::assertWarehouseReadable($db, $actor, $warehouseId);
                $balances = self::table($db, 'erp_stock_balances');
                $joins = " LEFT JOIN {$balances} b ON b.product_id=p.id AND b.warehouse_id=%d";
                $columns .= ",COALESCE(b.quantity,'0.000') available_qty";
                $selectArgs[] = $warehouseId;
            }

            $rows = self::rows(
                $db,
                "SELECT {$columns} FROM {$products} p{$joins} WHERE {$where} ORDER BY p.name,p.id LIMIT %d OFFSET %d",
                [...$selectArgs, ...$whereArgs, $pageSize, $offset]
            );
            return ['items' => $rows, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
        });
    }

    public static function searchCustomers(): void
    {
        self::handle('erp_search_customers', 'erp_customers_manage', static function (Actor $actor, wpdb $db): array {
            $query = self::queryText();
            [$page, $pageSize, $offset] = self::pagination();
            $customers = self::table($db, 'erp_customers');
            $where = 'c.is_active=1';
            $args = [];
            if ($query !== '') {
                $like = '%' . $db->esc_like($query) . '%';
                $where .= ' AND (c.code LIKE %s OR c.name LIKE %s)';
                $args = [$like, $like];
            }
            $total = (int) self::value($db, "SELECT COUNT(*) FROM {$customers} c WHERE {$where}", $args);
            $rows = self::rows(
                $db,
                "SELECT c.id,c.code,c.name FROM {$customers} c WHERE {$where} ORDER BY c.name,c.id LIMIT %d OFFSET %d",
                [...$args, $pageSize, $offset]
            );
            return ['items' => $rows, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
        });
    }

    public static function searchSuppliers(): void
    {
        self::handle('erp_search_suppliers', 'erp_suppliers_view', static function (Actor $actor, wpdb $db): array {
            $query = self::queryText();
            [$page, $pageSize, $offset] = self::pagination();
            $suppliers = self::table($db, 'erp_suppliers');
            $where = 's.is_active=1';
            $args = [];
            if ($query !== '') {
                $like = '%' . $db->esc_like($query) . '%';
                $where .= ' AND (s.code LIKE %s OR s.name LIKE %s)';
                $args = [$like, $like];
            }
            $total = (int) self::value($db, "SELECT COUNT(*) FROM {$suppliers} s WHERE {$where}", $args);
            $rows = self::rows(
                $db,
                "SELECT s.id,s.code,s.name FROM {$suppliers} s WHERE {$where} ORDER BY s.name,s.id LIMIT %d OFFSET %d",
                [...$args, $pageSize, $offset]
            );
            return ['items' => $rows, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
        });
    }

    /** @param callable(Actor, wpdb): array<string, mixed> $operation */
    private static function handle(string $action, string $capability, callable $operation): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            self::respond(['code' => 'method_not_allowed', 'message' => 'Chỉ chấp nhận yêu cầu POST.'], 405);
        }
        if (!CapabilityPolicy::allows($capability)) {
            CapabilityPolicy::logDeniedCapabilityAttempt($capability);
            self::respond(['code' => 'forbidden', 'message' => 'Bạn không có quyền thực hiện tra cứu này.'], 403);
        }
        $actor = CapabilityPolicy::currentActor();
        if ($actor === null) {
            self::respond(['code' => 'forbidden', 'message' => 'Phiên ERP không hợp lệ.'], 403);
        }
        if (!check_ajax_referer($action, 'nonce', false)) {
            self::respond(['code' => 'invalid_nonce', 'message' => 'Phiên yêu cầu đã hết hạn. Hãy tải lại màn hình.'], 403);
        }
        self::enforceRateLimit($action, $actor);

        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            self::respond(['code' => 'unavailable', 'message' => 'Dịch vụ tra cứu tạm thời không khả dụng.'], 503);
        }
        try {
            self::respond($operation($actor, $wpdb), 200);
        } catch (DomainException $failure) {
            self::respond(['code' => 'invalid_request', 'message' => $failure->getMessage()], 422);
        } catch (Throwable) {
            self::respond(['code' => 'unavailable', 'message' => 'Dịch vụ tra cứu tạm thời không khả dụng.'], 503);
        }
    }

    private static function assertWarehouseReadable(wpdb $db, Actor $actor, int $warehouseId): void
    {
        if (!$actor->can('erp_stock_view')) {
            self::respond(['code' => 'forbidden', 'message' => 'Bạn không có quyền xem tồn kho.'], 403);
        }
        $canReadAll = $actor->can('erp_stock_adjust_approve') || $actor->can('erp_sales_create');
        if (!$canReadAll && !in_array($warehouseId, $actor->warehouseIds, true)) {
            self::respond(['code' => 'not_found', 'message' => 'Không tìm thấy kho hoặc bạn không có quyền truy cập.'], 404);
        }
        $warehouses = self::table($db, 'erp_warehouses');
        $exists = self::value($db, "SELECT id FROM {$warehouses} WHERE id=%d AND is_active=1", [$warehouseId]);
        if ((int) $exists !== $warehouseId) {
            self::respond(['code' => 'not_found', 'message' => 'Không tìm thấy kho hoặc bạn không có quyền truy cập.'], 404);
        }
    }

    private static function enforceRateLimit(string $action, Actor $actor): void
    {
        $key = 'mini_erp_ajax_' . substr(hash('sha256', $action . ':' . $actor->id), 0, 40);
        $count = (int) get_transient($key);
        if ($count >= self::MAX_REQUESTS_PER_MINUTE) {
            self::respond(['code' => 'rate_limited', 'message' => 'Bạn gửi yêu cầu quá nhanh. Hãy thử lại sau một phút.'], 429);
        }
        set_transient($key, $count + 1, defined('MINUTE_IN_SECONDS') ? MINUTE_IN_SECONDS : 60);
    }

    private static function queryText(): string
    {
        $value = $_POST['q'] ?? '';
        if (!is_scalar($value)) {
            throw new DomainException('Từ khóa tra cứu không hợp lệ.');
        }
        $query = sanitize_text_field((string) wp_unslash($value));
        $length = function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query);
        if ($length > 100) {
            throw new DomainException('Từ khóa tra cứu tối đa 100 ký tự.');
        }
        return $query;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function pagination(): array
    {
        $page = self::boundedInt($_POST['page'] ?? 1, 1, self::MAX_PAGE);
        $pageSize = self::boundedInt($_POST['page_size'] ?? self::DEFAULT_PAGE_SIZE, 1, self::MAX_PAGE_SIZE);
        return [$page, $pageSize, ($page - 1) * $pageSize];
    }

    private static function optionalPositiveInt(string $field): ?int
    {
        if (!array_key_exists($field, $_POST)) {
            return null;
        }
        return self::boundedInt($_POST[$field], 1, PHP_INT_MAX);
    }

    private static function boundedInt(mixed $value, int $min, int $max): int
    {
        if (!is_scalar($value) || !preg_match('/\A[0-9]{1,18}\z/D', (string) wp_unslash($value))) {
            throw new DomainException('Tham số tra cứu không hợp lệ.');
        }
        $parsed = filter_var((string) wp_unslash($value), FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < $min || $parsed > $max) {
            throw new DomainException('Tham số tra cứu không hợp lệ.');
        }
        return $parsed;
    }

    /** @param list<mixed> $args @return list<array<string, mixed>> */
    private static function rows(wpdb $db, string $sql, array $args): array
    {
        $query = $args === [] ? $sql : $db->prepare($sql, $args);
        $rows = $db->get_results($query, ARRAY_A);
        if ($db->last_error !== '' || !is_array($rows)) {
            throw new RuntimeException('Lookup query failed.');
        }
        return $rows;
    }

    /** @param list<mixed> $args */
    private static function value(wpdb $db, string $sql, array $args): mixed
    {
        $query = $args === [] ? $sql : $db->prepare($sql, $args);
        $value = $db->get_var($query);
        if ($db->last_error !== '') {
            throw new RuntimeException('Lookup query failed.');
        }
        return $value;
    }

    private static function table(wpdb $db, string $suffix): string
    {
        $allowed = ['erp_products', 'erp_warehouses', 'erp_stock_balances', 'erp_customers', 'erp_suppliers'];
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $db->prefix) || !in_array($suffix, $allowed, true)) {
            throw new RuntimeException('Invalid lookup table.');
        }
        return '`' . $db->prefix . $suffix . '`';
    }

    /** @param array<string, mixed> $payload */
    private static function respond(array $payload, int $status): void
    {
        wp_send_json($payload, $status);
        exit;
    }
}
