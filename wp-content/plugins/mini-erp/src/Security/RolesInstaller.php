<?php
declare(strict_types=1);

namespace MiniERP\Security;

use RuntimeException;

/** Installs the five least-privilege ERP roles without granting technical administrator powers. */
final class RolesInstaller
{
    /** @return array<string, array{label: string, capabilities: list<string>}> */
    public static function definitions(): array
    {
        return [
            'erp_system_admin' => [
                'label' => 'ERP System Admin',
                'capabilities' => ['erp_dashboard_view', 'erp_users_view', 'erp_users_manage', 'erp_roles_manage', 'erp_settings_manage', 'erp_audit_view', 'erp_ui_studio_manage'],
            ],
            'erp_manager' => [
                'label' => 'ERP Manager',
                'capabilities' => [
                    'erp_dashboard_view', 'erp_products_view', 'erp_products_manage', 'erp_warehouses_view', 'erp_warehouses_manage',
                    'erp_customers_manage', 'erp_suppliers_view', 'erp_suppliers_manage', 'erp_sales_create', 'erp_sales_view_all', 'erp_invoice_sources_view_all', 'erp_sales_confirm',
                    'erp_sales_approve', 'erp_sales_cancel', 'erp_purchases_create', 'erp_purchases_view', 'erp_purchases_approve', 'erp_purchases_cancel',
                    'erp_stock_view', 'erp_stock_adjust_approve', 'erp_invoices_view', 'erp_invoices_print',
                    'erp_payments_view', 'erp_payments_void_approve', 'erp_cash_accounts_view', 'erp_cash_accounts_activate',
                    'erp_cash_book_view', 'erp_reconciliations_view', 'erp_reconciliations_close', 'erp_reports_sales',
                    'erp_reports_stock', 'erp_reports_finance', 'erp_reports_receivables',
                ],
            ],
            'erp_sales' => [
                'label' => 'ERP Sales',
                'capabilities' => [
                    'erp_dashboard_view', 'erp_products_view', 'erp_customers_manage', 'erp_sales_create', 'erp_sales_view_own', 'erp_invoice_sources_view_own', 'erp_sales_confirm', 'erp_sales_cancel',
                    'erp_stock_view', 'erp_invoices_view', 'erp_invoices_print', 'erp_reports_receivables',
                ],
            ],
            'erp_warehouse' => [
                'label' => 'ERP Warehouse',
                'capabilities' => [
                    'erp_dashboard_view', 'erp_products_view', 'erp_warehouses_view', 'erp_purchases_view', 'erp_stock_view',
                    'erp_stock_receive', 'erp_stock_ship', 'erp_stock_count', 'erp_stock_adjust_request', 'erp_stock_adjust_apply',
                ],
            ],
            'erp_accountant' => [
                'label' => 'ERP Accountant',
                'capabilities' => [
                    'erp_dashboard_view', 'erp_products_view', 'erp_suppliers_view', 'erp_purchases_view', 'erp_invoice_sources_view_all', 'erp_invoices_view',
                    'erp_invoices_issue', 'erp_invoices_print', 'erp_invoices_void', 'erp_payments_view', 'erp_payments_record',
                    'erp_payments_void_request', 'erp_payments_void_apply', 'erp_cash_accounts_view', 'erp_cash_accounts_manage',
                    'erp_cash_book_view', 'erp_expenses_manage', 'erp_reconciliations_create', 'erp_reconciliations_view',
                    'erp_reports_finance', 'erp_reports_receivables',
                ],
            ],
        ];
    }

    public static function install(): void
    {
        if (!function_exists('add_role') || !function_exists('get_role')) {
            throw new RuntimeException('WordPress role APIs are unavailable.');
        }

        $managed = self::knownCapabilities();
        foreach (self::definitions() as $slug => $definition) {
            add_role($slug, $definition['label'], ['read' => true]);
            $role = get_role($slug);
            if (!$role) {
                throw new RuntimeException('Could not install an ERP business role.');
            }

            $expected = array_fill_keys($definition['capabilities'], true);
            foreach ($managed as $capability) {
                if (!isset($expected[$capability]) && $role->has_cap($capability)) {
                    $role->remove_cap($capability);
                }
            }
            foreach ($definition['capabilities'] as $capability) {
                $role->add_cap($capability);
            }
        }
    }

    /** @return list<string> */
    public static function knownCapabilities(): array
    {
        $capabilities = [];
        foreach (self::definitions() as $definition) {
            array_push($capabilities, ...$definition['capabilities']);
        }
        return array_values(array_unique($capabilities));
    }
}
