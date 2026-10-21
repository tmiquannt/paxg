<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/** Transactional category and product writes; reads stay in the admin controller. */
final class CatalogService
{
    public function __construct(
        private readonly wpdb $db,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit
    ) {
    }

    public function createCategory(Actor $actor, string $code, string $name): int
    {
        $this->requireManage($actor);
        $table = $this->table('erp_categories');
        $now = $this->now();

        return $this->transactions->run(function (TransactionManager $transaction) use ($actor, $table, $code, $name, $now): int {
            $transaction->execute(
                "INSERT INTO {$table} (code, name, is_active, created_at, updated_at) VALUES (%s, %s, 1, %s, %s)",
                [$code, $name, $now, $now]
            );
            $id = $transaction->insertId();
            $this->audit->record($transaction, $this->requestId(), $actor->id, 'catalog.category.created', 'category', $id, 'success', [], [
                'code' => $code,
                'name' => $name,
                'is_active' => 1,
            ]);
            return $id;
        });
    }

    /** @param array{sku:string,name:string,category_id:int,unit:string,unit_step:string,sale_price_vnd:string} $values */
    public function saveProduct(Actor $actor, array $values, ?int $id = null, ?string $expectedUpdatedAt = null): int
    {
        $this->requireManage($actor);
        $table = $this->table('erp_products');
        $now = $this->now();

        if ($id === null) {
            return $this->transactions->run(function (TransactionManager $transaction) use ($actor, $table, $values, $now): int {
                $categoryTable = $this->table('erp_categories');
                $affected = $transaction->execute(
                    "INSERT INTO {$table} (sku, name, category_id, unit, unit_step, sale_price_vnd, is_active, created_at, updated_at) SELECT %s, %s, c.id, %s, %s, %s, 1, %s, %s FROM {$categoryTable} c WHERE c.id = %d AND c.is_active = 1",
                    [$values['sku'], $values['name'], $values['unit'], $values['unit_step'], $values['sale_price_vnd'], $now, $now, $values['category_id']]
                );
                if ($affected !== 1) {
                    throw new DomainException('Danh mục không còn hoạt động.');
                }
                $productId = $transaction->insertId();
                $this->audit->record($transaction, $this->requestId(), $actor->id, 'catalog.product.created', 'product', $productId, 'success', [], $values + ['is_active' => 1]);
                return $productId;
            });
        }

        if ($id < 1 || $expectedUpdatedAt === null || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $expectedUpdatedAt)) {
            throw new DomainException('Dữ liệu cập nhật sản phẩm không hợp lệ.');
        }

        $before = $this->db->get_row($this->db->prepare(
            "SELECT id, sku, name, category_id, unit, unit_step, sale_price_vnd, is_active, updated_at FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!is_array($before)) {
            throw new DomainException('Không tìm thấy sản phẩm.');
        }
        if ((string) $before['updated_at'] !== $expectedUpdatedAt) {
            throw new DomainException('Sản phẩm đã được cập nhật ở nơi khác. Tải lại trước khi lưu tiếp.');
        }

        return $this->transactions->run(function (TransactionManager $transaction) use ($actor, $table, $values, $id, $expectedUpdatedAt, $before): int {
            $categoryTable = $this->table('erp_categories');
            $affected = $transaction->execute(
                "UPDATE {$table} p INNER JOIN {$categoryTable} c ON c.id = %d SET p.sku = %s, p.name = %s, p.category_id = c.id, p.unit = %s, p.unit_step = %s, p.sale_price_vnd = %s, p.updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND, 1, p.updated_at)) WHERE p.id = %d AND p.updated_at = %s AND (c.is_active = 1 OR c.id = p.category_id)",
                [$values['category_id'], $values['sku'], $values['name'], $values['unit'], $values['unit_step'], $values['sale_price_vnd'], $id, $expectedUpdatedAt]
            );
            if ($affected !== 1) {
                throw new DomainException('Sản phẩm đã được cập nhật ở nơi khác. Tải lại trước khi lưu tiếp.');
            }
            $this->audit->record($transaction, $this->requestId(), $actor->id, 'catalog.product.updated', 'product', $id, 'success', $this->auditValues($before), $values + ['is_active' => (int) $before['is_active']]);
            return $id;
        });
    }

    public function setProductActive(Actor $actor, int $id, bool $active, string $expectedUpdatedAt): void
    {
        $this->requireManage($actor);
        $this->setActive($actor, 'erp_products', 'product', $id, $active, $expectedUpdatedAt);
    }

    public function setCategoryActive(Actor $actor, int $id, bool $active, string $expectedUpdatedAt): void
    {
        $this->requireManage($actor);
        $this->setActive($actor, 'erp_categories', 'category', $id, $active, $expectedUpdatedAt);
    }

    private function setActive(Actor $actor, string $suffix, string $entity, int $id, bool $active, string $expectedUpdatedAt): void
    {
        if ($id < 1 || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $expectedUpdatedAt)) {
            throw new DomainException('Dữ liệu trạng thái không hợp lệ.');
        }
        $table = $this->table($suffix);
        $columns = $suffix === 'erp_products'
            ? 'id, sku, name, category_id, unit, unit_step, sale_price_vnd, is_active, updated_at'
            : 'id, code, name, is_active, updated_at';
        $before = $this->db->get_row($this->db->prepare(
            "SELECT {$columns} FROM {$table} WHERE id = %d",
            $id
        ), ARRAY_A);
        if (!is_array($before)) {
            throw new DomainException('Không tìm thấy bản ghi.');
        }
        if ((string) $before['updated_at'] !== $expectedUpdatedAt) {
            throw new DomainException('Bản ghi đã được cập nhật ở nơi khác. Tải lại trước khi lưu tiếp.');
        }

        $this->transactions->run(function (TransactionManager $transaction) use ($actor, $table, $entity, $id, $active, $expectedUpdatedAt, $before): void {
            $affected = $transaction->execute(
                "UPDATE {$table} SET is_active = %d, updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND, 1, updated_at)) WHERE id = %d AND updated_at = %s",
                [$active ? 1 : 0, $id, $expectedUpdatedAt]
            );
            if ($affected !== 1) {
                throw new DomainException('Bản ghi đã được cập nhật ở nơi khác. Tải lại trước khi lưu tiếp.');
            }
            $after = $this->auditValues($before);
            $after['is_active'] = $active ? 1 : 0;
            $this->audit->record($transaction, $this->requestId(), $actor->id, 'catalog.' . $entity . '.status_changed', $entity, $id, 'success', $this->auditValues($before), $after);
        });
    }

    /** @param array<string, mixed> $row @return array<string, scalar|null> */
    private function auditValues(array $row): array
    {
        $allowed = ['code', 'sku', 'name', 'category_id', 'unit', 'unit_step', 'sale_price_vnd', 'is_active'];
        $values = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $row)) {
                $values[$key] = is_numeric($row[$key]) ? (string) $row[$key] : (string) $row[$key];
            }
        }
        return $values;
    }

    private function requireManage(Actor $actor): void
    {
        if (!$actor->can('erp_products_manage')) {
            throw new DomainException('Bạn không có quyền quản lý danh mục và sản phẩm.');
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function requestId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function table(string $suffix): string
    {
        $prefix = $this->db->prefix;
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $prefix)
            || !in_array($suffix, ['erp_categories', 'erp_products'], true)
        ) {
            throw new DomainException('Cấu hình bảng Mini ERP không hợp lệ.');
        }
        return '`' . $prefix . $suffix . '`';
    }
}
