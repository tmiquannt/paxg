<?php
declare(strict_types=1);

namespace MiniERP\Security;

use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use Throwable;

/** Read-only, capability-gated audit screen. */
final class AuditLogController
{
    public static function render(): void
    {
        CapabilityPolicy::requireCapability('erp_audit_view');
        global $wpdb;
        $table = '`' . esc_sql($wpdb->prefix . 'erp_audit_logs') . '`';
        $rows = $wpdb->get_results('SELECT id,event_id,request_id,actor_user_id,action,entity_type,entity_id,result,before_json,after_json,created_at FROM ' . $table . ' ORDER BY id DESC LIMIT 100', ARRAY_A);
        if (!is_array($rows)) {
            $rows = [];
        }

        $actor = CapabilityPolicy::currentActor();
        if ($actor !== null) {
            try {
                (new TransactionManager($wpdb))->run(static function (TransactionManager $transaction) use ($actor): void {
                    (new AuditLogger())->record($transaction, self::requestId(), $actor->id, 'audit.read', 'audit_log', null, 'success');
                });
            } catch (Throwable) {
                wp_die(esc_html__('Không thể ghi nhận lượt truy cập nhật ký.', 'mini-erp'), '', ['response' => 503]);
            }
        }

        echo '<div class="wrap erp-app"><h1>' . esc_html__('Nhật ký kiểm toán', 'mini-erp') . '</h1>';
        echo '<p>' . esc_html__('100 sự kiện gần nhất. Nhật ký chỉ đọc và không cho phép sửa hoặc xóa từ giao diện.', 'mini-erp') . '</p>';
        echo '<div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>' . esc_html__('Thời gian UTC', 'mini-erp') . '</th><th>' . esc_html__('Người dùng', 'mini-erp') . '</th><th>' . esc_html__('Hành động', 'mini-erp') . '</th><th>' . esc_html__('Đối tượng', 'mini-erp') . '</th><th>' . esc_html__('Kết quả', 'mini-erp') . '</th><th>' . esc_html__('Thay đổi đã lọc', 'mini-erp') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $change = ['before' => self::decode($row['before_json'] ?? null), 'after' => self::decode($row['after_json'] ?? null)];
            echo '<tr><td>' . esc_html((string) ($row['created_at'] ?? '')) . '</td><td>' . (int) ($row['actor_user_id'] ?? 0) . '</td><td><code>' . esc_html((string) ($row['action'] ?? '')) . '</code></td><td>' . esc_html((string) ($row['entity_type'] ?? '')) . ' #' . esc_html((string) ($row['entity_id'] ?? '')) . '</td><td>' . esc_html((string) ($row['result'] ?? '')) . '</td><td><code>' . esc_html((string) wp_json_encode($change, JSON_UNESCAPED_UNICODE)) . '</code></td></tr>';
        }
        if ($rows === []) {
            echo '<tr><td colspan="6">' . esc_html__('Chưa có sự kiện.', 'mini-erp') . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function decode(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function requestId(): string
    {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffffffffffff));
    }
}
