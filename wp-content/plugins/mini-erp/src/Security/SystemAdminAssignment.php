<?php
declare(strict_types=1);

namespace MiniERP\Security;

use MiniERP\Infrastructure\Database\TransactionManager;
use RuntimeException;
use wpdb;

/** Serializes and validates assignments of the single day-to-day ERP administrator role. */
final class SystemAdminAssignment
{
    private const ROLE = 'erp_system_admin';

    /** Run an access change while holding a site-specific MySQL advisory lock. */
    public static function withLock(wpdb $db, callable $work): mixed
    {
        $lockName = 'mini_erp_sysadmin_' . substr(hash('sha256', $db->get_blog_prefix() . 'capabilities'), 0, 40);
        $wasSuppressingErrors = $db->suppress_errors(true);
        try {
            $acquired = $db->get_var($db->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 5));
        } finally {
            $db->suppress_errors($wasSuppressingErrors);
        }
        if ((string) $acquired !== '1') {
            throw new RuntimeException('Could not serialize ERP role assignment.');
        }

        try {
            return $work();
        } finally {
            $wasSuppressingErrors = $db->suppress_errors(true);
            try {
                $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            } finally {
                $db->suppress_errors($wasSuppressingErrors);
            }
        }
    }

    /** Reject assigning System Admin when another account already holds the role. */
    public static function assertSoleAssignee(TransactionManager $transaction, wpdb $db, int $targetUserId): void
    {
        $table = str_replace('`', '``', $db->usermeta);
        $rows = $transaction->fetchAll(
            "SELECT user_id, meta_value FROM `{$table}` WHERE meta_key = %s AND meta_value LIKE %s FOR UPDATE",
            [$db->get_blog_prefix() . 'capabilities', '%' . self::ROLE . '%']
        );

        foreach ($rows as $row) {
            $capabilities = maybe_unserialize((string) ($row['meta_value'] ?? ''));
            if (!is_array($capabilities) || empty($capabilities[self::ROLE])) {
                continue;
            }
            if ((int) $row['user_id'] !== $targetUserId) {
                throw new SystemAdminAssignmentConflict('ERP System Admin is already assigned to another account.');
            }
        }
    }

    /** @return list<int> */
    public static function assignedUserIds(): array
    {
        if (!function_exists('get_users')) {
            return [];
        }

        $ids = get_users([
            'role' => self::ROLE,
            'fields' => 'ID',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
