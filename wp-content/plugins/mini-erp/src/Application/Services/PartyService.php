<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use wpdb;

/** Maintains customers, suppliers, and warehouses without deleting referenced masters. */
final class PartyService
{
    private const ENTITIES = [
        'customer' => ['table' => 'erp_customers', 'capability' => 'erp_customers_manage', 'type' => 'customer'],
        'supplier' => ['table' => 'erp_suppliers', 'capability' => 'erp_suppliers_manage', 'type' => 'supplier'],
        'warehouse' => ['table' => 'erp_warehouses', 'capability' => 'erp_warehouses_manage', 'type' => 'warehouse'],
    ];

    public function __construct(
        private readonly wpdb $db,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit
    ) {
    }

    /** @param array{code:string,name:string,tax_id?:string,phone?:string,email?:string,address?:string} $values */
    public function save(Actor $actor, string $entity, array $values, ?int $id = null, ?string $expectedUpdatedAt = null): int
    {
        $definition = $this->definition($entity);
        if (!$actor->can($definition['capability'])) {
            throw new DomainException('Bạn không có quyền quản lý dữ liệu này.');
        }
        $table = $this->table($definition['table']);
        $fields = $entity === 'warehouse' ? ['code', 'name'] : ['code', 'name', 'tax_id', 'phone', 'email', 'address'];
        foreach ($fields as $field) {
            if (!isset($values[$field]) || !is_string($values[$field])) {
                throw new DomainException('Dữ liệu đối tác không hợp lệ.');
            }
        }
        if (array_diff(array_keys($values), $fields) !== []
            || !preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D', $values['code'])
            || $this->length($values['name']) < 1 || $this->length($values['name']) > 191
        ) {
            throw new DomainException('Mã hoặc tên dữ liệu không hợp lệ.');
        }
        foreach (array_slice($fields, 2) as $field) {
            $limits = ['tax_id' => 64, 'phone' => 32, 'email' => 254, 'address' => 1000];
            if ($this->length($values[$field]) > $limits[$field]) {
                throw new DomainException('Thông tin liên hệ vượt quá độ dài cho phép.');
            }
        }
        if ($entity !== 'warehouse' && $values['email'] !== '' && (!function_exists('is_email') || !is_email($values['email']))) {
            throw new DomainException('Email không hợp lệ.');
        }
        $now = gmdate('Y-m-d H:i:s.u');

        if ($id === null) {
            return $this->transactions->run(function (TransactionManager $tx) use ($actor, $entity, $definition, $table, $fields, $values, $now): int {
                $columns = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', $fields));
                $slots = implode(', ', array_fill(0, count($fields), '%s'));
                $args = array_map(static fn (string $field): string => $values[$field], $fields);
                $tx->execute("INSERT INTO {$table} ({$columns}, is_active, created_at, updated_at) VALUES ({$slots}, 1, %s, %s)", [...$args, $now, $now]);
                $newId = $tx->insertId();
                $this->audit->record($tx, $this->requestId(), $actor->id, 'master.' . $entity . '.created', $definition['type'], $newId, 'success', [], ['code' => $values['code'], 'name' => $values['name'], 'is_active' => 1]);
                return $newId;
            });
        }

        if ($id < 1 || $expectedUpdatedAt === null || !$this->validTimestamp($expectedUpdatedAt)) {
            throw new DomainException('Dữ liệu cập nhật không hợp lệ.');
        }
        $columns = implode(', ', array_map(static fn (string $field): string => '`' . $field . '`', [...$fields, 'is_active', 'updated_at']));
        $before = $this->db->get_row($this->db->prepare("SELECT id, {$columns} FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($before) || (string) $before['updated_at'] !== $expectedUpdatedAt) {
            throw new DomainException('Bản ghi đã thay đổi hoặc không còn tồn tại. Tải lại trước khi lưu.');
        }

        return $this->transactions->run(function (TransactionManager $tx) use ($actor, $entity, $definition, $table, $fields, $values, $id, $expectedUpdatedAt, $before): int {
            $assignments = implode(', ', array_map(static fn (string $field): string => "`{$field}` = %s", $fields));
            $args = array_map(static fn (string $field): string => $values[$field], $fields);
            $changed = $tx->execute("UPDATE {$table} SET {$assignments}, updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND, 1, updated_at)) WHERE id = %d AND updated_at = %s", [...$args, $id, $expectedUpdatedAt]);
            if ($changed !== 1) {
                throw new DomainException('Bản ghi đã thay đổi ở nơi khác. Tải lại trước khi lưu.');
            }
            $this->audit->record($tx, $this->requestId(), $actor->id, 'master.' . $entity . '.updated', $definition['type'], $id, 'success', $this->auditProjection($before), ['code' => $values['code'], 'name' => $values['name'], 'is_active' => (int) $before['is_active']]);
            return $id;
        });
    }

    public function setActive(Actor $actor, string $entity, int $id, bool $active, string $expectedUpdatedAt): void
    {
        $definition = $this->definition($entity);
        if (!$actor->can($definition['capability']) || $id < 1 || !$this->validTimestamp($expectedUpdatedAt)) {
            throw new DomainException('Bạn không có quyền hoặc dữ liệu trạng thái không hợp lệ.');
        }
        $table = $this->table($definition['table']);
        $before = $this->db->get_row($this->db->prepare("SELECT id, code, name, is_active, updated_at FROM {$table} WHERE id = %d", $id), ARRAY_A);
        if (!is_array($before) || (string) $before['updated_at'] !== $expectedUpdatedAt) {
            throw new DomainException('Bản ghi đã thay đổi hoặc không còn tồn tại. Tải lại trước khi lưu.');
        }
        $this->transactions->run(function (TransactionManager $tx) use ($actor, $entity, $definition, $table, $id, $active, $expectedUpdatedAt, $before): void {
            if ($tx->execute("UPDATE {$table} SET is_active = %d, updated_at = GREATEST(UTC_TIMESTAMP(6), TIMESTAMPADD(MICROSECOND, 1, updated_at)) WHERE id = %d AND updated_at = %s", [$active ? 1 : 0, $id, $expectedUpdatedAt]) !== 1) {
                throw new DomainException('Bản ghi đã thay đổi ở nơi khác. Tải lại trước khi lưu.');
            }
            $after = $this->auditProjection($before);
            $after['is_active'] = $active ? 1 : 0;
            $this->audit->record($tx, $this->requestId(), $actor->id, 'master.' . $entity . '.status_changed', $definition['type'], $id, 'success', $this->auditProjection($before), $after);
        });
    }

    private function definition(string $entity): array
    {
        if (!isset(self::ENTITIES[$entity])) {
            throw new DomainException('Loại dữ liệu không hợp lệ.');
        }
        return self::ENTITIES[$entity];
    }

    private function table(string $suffix): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $this->db->prefix)) {
            throw new DomainException('Cấu hình bảng Mini ERP không hợp lệ.');
        }
        return '`' . $this->db->prefix . $suffix . '`';
    }

    private function validTimestamp(string $timestamp): bool
    {
        return (bool) preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $timestamp);
    }

    private function requestId(): string
    {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffffffffffff));
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @param array<string,mixed> $row @return array<string,scalar|null> */
    private function auditProjection(array $row): array
    {
        return ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'is_active' => (int) $row['is_active']];
    }
}
