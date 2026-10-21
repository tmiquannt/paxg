<?php
declare(strict_types=1);

namespace MiniERP\Security;

use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use Throwable;

/** Resolves one ERP role from the authenticated WordPress identity and denies ambiguous users. */
final class CapabilityPolicy
{
    public static function currentActor(): ?Actor
    {
        if (!function_exists('wp_get_current_user') || !function_exists('get_role')) {
            return null;
        }

        $user = wp_get_current_user();
        if (!is_object($user) || !isset($user->ID, $user->roles) || (int) $user->ID < 1 || !is_array($user->roles)) {
            return null;
        }

        $erpRoles = array_values(array_intersect(array_keys(RolesInstaller::definitions()), $user->roles));
        if (count($erpRoles) !== 1 || in_array('administrator', $user->roles, true)
            || !function_exists('get_user_meta')
            || !in_array(get_user_meta((int) $user->ID, '_mini_erp_enabled', true), [1, '1', true], true)
        ) {
            return null;
        }

        $role = get_role($erpRoles[0]);
        if (!$role || !is_array($role->capabilities ?? null)) {
            return null;
        }

        $capabilities = RolesInstaller::definitions()[$erpRoles[0]]['capabilities'];
        $warehouseIds = ScopeAssignmentIds::normalizeStored(get_user_meta((int) $user->ID, '_mini_erp_warehouse_ids', true)) ?? [];
        $cashAccountIds = ScopeAssignmentIds::normalizeStored(get_user_meta((int) $user->ID, '_mini_erp_cash_account_ids', true)) ?? [];

        return new Actor((int) $user->ID, $erpRoles[0], $capabilities, $warehouseIds, $cashAccountIds);
    }

    public static function allows(string $capability): bool
    {
        if (!in_array($capability, RolesInstaller::knownCapabilities(), true)) {
            return false;
        }
        $actor = self::currentActor();
        return $actor !== null
            && $actor->can($capability)
            && function_exists('current_user_can')
            && current_user_can($capability);
    }

    public static function requireCapability(string $capability): void
    {
        if (!self::allows($capability)) {
            self::recordDeniedAttempt($capability);
            if (function_exists('wp_die')) {
                wp_die(
                    esc_html__('Bạn không có quyền truy cập chức năng này.', 'mini-erp'),
                    esc_html__('Không có quyền', 'mini-erp'),
                    ['response' => 403]
                );
            }
            throw new \RuntimeException('ERP capability denied.');
        }
    }

    public static function logDeniedCapabilityAttempt(string $capability): void
    {
        if (in_array($capability, RolesInstaller::knownCapabilities(), true)) {
            self::recordDeniedAttempt($capability);
        }
    }

    private static function recordDeniedAttempt(string $capability): void
    {
        if (!function_exists('get_current_user_id') || get_current_user_id() < 1) {
            return;
        }
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return;
        }
        try {
            $requestId = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffffffffffff));
            (new TransactionManager($wpdb))->run(static function (TransactionManager $transaction) use ($requestId, $capability): void {
                (new AuditLogger())->record($transaction, $requestId, (int) get_current_user_id(), 'authz.denied.' . $capability, 'access_control', null, 'denied');
            });
        } catch (Throwable) {
            // Denial remains fail-closed when audit storage is unavailable.
        }
    }
}
