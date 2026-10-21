<?php
declare(strict_types=1);

use MiniERP\Domain\ExactAmounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure tests: no persistent state. SPEC §4.1, D3; no line allocation contract implied. */
final class ExactAmountsTest extends TestCase
{
    public function testDocumentRoundsOnceRatherThanPerLine(): void
    {
        $result = ExactAmounts::subtotal([
            ['unit_price_vnd' => '1', 'quantity' => '0.500', 'unit_step' => '0.001'],
            ['unit_price_vnd' => '1', 'quantity' => '0.500', 'unit_step' => '0.001'],
        ]);
        self::assertSame(['0.500', '0.500'], $result['lines_exact_vnd']);
        self::assertSame('1.000', $result['subtotal_exact_vnd']);
        self::assertSame('1', $result['subtotal_vnd']);
        self::assertSame('0.000', $result['rounding_adjustment_vnd']);
    }

    #[DataProvider('roundingCases')]
    public function testDocumentRoundingIsTransparent(string $qty, string $rounded, string $adjustment): void
    {
        $result = ExactAmounts::subtotal([['unit_price_vnd' => '1', 'quantity' => $qty, 'unit_step' => '0.001']]);
        self::assertSame($rounded, $result['subtotal_vnd']);
        self::assertSame($adjustment, $result['rounding_adjustment_vnd']);
        self::assertSame(bcadd($result['subtotal_exact_vnd'], $adjustment, 3), $rounded . '.000');
    }

    public static function roundingCases(): array
    {
        return [['0.499', '0', '-0.499'], ['0.500', '1', '0.500'], ['1.001', '1', '-0.001'], ['1.999', '2', '0.001']];
    }

    public function testBigintBoundaryWithoutNativeMultiplication(): void
    {
        $result = ExactAmounts::subtotal([['unit_price_vnd' => '9223372036854775807', 'quantity' => '1', 'unit_step' => '1']]);
        self::assertSame('9223372036854775807', $result['subtotal_vnd']);
    }

    public function testDecimalQuantityBoundary(): void
    {
        self::assertSame('999999999999999.999', ExactAmounts::quantity('999999999999999.999', '0.001'));
    }

    #[DataProvider('invalidQuantities')]
    public function testRejectsInvalidQuantities(mixed $quantity, string $step): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactAmounts::quantity($quantity, $step);
    }

    public static function invalidQuantities(): array
    {
        return [['0', '1'], ['-1', '1'], ['1.5', '1'], ['1.0001', '0.001'], ['1e3', '1'], [1.2, '0.001'], ['1000000000000000', '0.001'], ['1', '0'], ['1', '0.3']];
    }

    #[DataProvider('invalidMoney')]
    public function testRejectsInvalidMoney(mixed $money): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactAmounts::money($money);
    }

    public static function invalidMoney(): array
    {
        return [['-1'], ['1.5'], ['1e3'], ['9223372036854775808'], [1.0], [' 1'], ['1,000'], [true]];
    }

    public function testOverflowAcrossLinesRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactAmounts::subtotal([
            ['unit_price_vnd' => '9223372036854775807', 'quantity' => '1', 'unit_step' => '1'],
            ['unit_price_vnd' => '1', 'quantity' => '1', 'unit_step' => '1'],
        ]);
    }

    public function testHugeIntermediateProductRejectedWithoutFloat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactAmounts::subtotal([['unit_price_vnd' => '9223372036854775807', 'quantity' => '999999999999999.999', 'unit_step' => '0.001']]);
    }

    public function testEmptyDocumentRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExactAmounts::subtotal([]);
    }
}
