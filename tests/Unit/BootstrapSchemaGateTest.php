<?php
declare(strict_types=1);

use MiniERP\Bootstrap;
use PHPUnit\Framework\TestCase;

final class BootstrapSchemaGateTest extends TestCase
{
    public function testSchemaGateIncludesAllErpPostAndAjaxActions(): void
    {
        foreach ([
            'mini_erp_invoice_issue',
            'mini_erp_user_access_save',
            'erp_search_products',
            'erp_search_customers',
            'erp_search_suppliers',
        ] as $action) {
            self::assertTrue(Bootstrap::isSchemaGatedAction($action), $action . ' must wait for the ERP schema.');
        }
    }

    public function testSchemaGateDoesNotClaimUnrelatedOrMalformedActions(): void
    {
        foreach ([
            'profile_update',
            'erp_search_products_external',
            '',
            null,
            ['erp_search_products'],
        ] as $action) {
            self::assertFalse(Bootstrap::isSchemaGatedAction($action));
        }
    }
}
