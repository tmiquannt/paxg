<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure\Audit;

use InvalidArgumentException;
use MiniERP\Infrastructure\Database\TransactionManager;
use Throwable;

/** Writes a privacy-limited audit event through the caller's active transaction. */
final class AuditLogger
{
    private const ALLOWED_FIELDS = ['status', 'quantity', 'amount_vnd', 'role', 'is_active', 'warehouse_scope_count', 'cash_scope_count', 'code', 'sku', 'name', 'category_id', 'unit', 'unit_step', 'sale_price_vnd'];

    public function __construct(private readonly ?string $tablePrefix = null)
    {
    }

    /** @param array<string, scalar|null> $before @param array<string, scalar|null> $after */
    public function record(
        TransactionManager $transaction,
        string $requestId,
        int $actorId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $result,
        array $before = [],
        array $after = []
    ): void {
        if ($actorId < 1 || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $requestId)
            || !preg_match('/\A[a-z][a-z0-9._-]{0,95}\z/D', $action)
            || !preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $entityType)
            || !in_array($result, ['success', 'denied', 'failure'], true)
            || ($entityId !== null && $entityId < 1)
        ) {
            throw new InvalidArgumentException('Thông tin audit không hợp lệ.');
        }

        $eventId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : self::uuid4();
        $beforeJson = $this->json($before);
        $afterJson = $this->json($after);
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

        $transaction->execute(
            'INSERT INTO ' . $this->tableName() . ' (event_id, request_id, actor_user_id, action, entity_type, entity_id, result, before_json, after_json, ip_hash, created_at) VALUES (%s, %s, %d, %s, %s, %s, %s, NULLIF(%s, \'\'), NULLIF(%s, \'\'), NULL, %s)',
            [$eventId, $requestId, $actorId, $action, $entityType, $entityId === null ? '' : (string) $entityId, $result, $beforeJson, $afterJson, $now]
        );
    }

    /** @param array<string, scalar|null> $fields */
    private function json(array $fields): string
    {
        $safe = [];
        foreach ($fields as $field => $value) {
            if (!in_array($field, self::ALLOWED_FIELDS, true) || !is_scalar($value) && $value !== null) {
                continue;
            }
            $safe[$field] = $value;
        }
        if ($safe === []) {
            return '';
        }
        try {
            return json_encode($safe, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            throw new InvalidArgumentException('Dữ liệu audit không thể mã hóa an toàn.');
        }
    }

    private function tableName(): string
    {
        global $wpdb;
        $prefix = $this->tablePrefix ?? ($wpdb->prefix ?? '');
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $prefix)) {
            throw new InvalidArgumentException('The WordPress table prefix is not supported.');
        }
        return '`' . $prefix . 'erp_audit_logs`';
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
