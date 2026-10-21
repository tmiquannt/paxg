<?php
declare(strict_types=1);

use MiniERP\Application\Services\InventoryCountInput;
use PHPUnit\Framework\TestCase;

final class InventoryCountInputTest extends TestCase
{
    public function testAcceptsIntegerKeysProducedByPhpForCountsForm(): void
    {
        self::assertSame([
            ['product_id' => 123, 'counted_qty' => '2.000'],
            ['product_id' => 456, 'counted_qty' => '0'],
        ], InventoryCountInput::fromForm([123 => '2.000', 456 => '0']));
    }

    public function testAcceptsCanonicalStringKeysAndPreservesZeroCounts(): void
    {
        self::assertSame([
            ['product_id' => 123, 'counted_qty' => '1.250'],
            ['product_id' => 456, 'counted_qty' => '0'],
        ], InventoryCountInput::fromForm(['123' => '1.250', '456' => '0']));
    }

    public function testRejectsInvalidProductKeysAndNonscalarCounts(): void
    {
        self::assertNull(InventoryCountInput::fromForm([0 => '1']));
        self::assertNull(InventoryCountInput::fromForm([-1 => '1']));
        self::assertNull(InventoryCountInput::fromForm(['01' => '1']));
        self::assertNull(InventoryCountInput::fromForm([(string) PHP_INT_MAX . '0' => '1']));
        self::assertNull(InventoryCountInput::fromForm([123 => ['1']]));
    }
}
