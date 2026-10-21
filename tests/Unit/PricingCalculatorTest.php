<?php
declare(strict_types=1);

use MiniERP\Domain\ExactAmounts;
use MiniERP\Domain\PricingCalculator;
use PHPUnit\Framework\TestCase;

/** Pure calculator tests: no persistence, WordPress, or database state. */
final class PricingCalculatorTest extends TestCase
{
    public function testRoundsDocumentSubtotalOnceAndAllocatesTiesBySourceLineId(): void
    {
        $result = PricingCalculator::calculate([
            $this->line('20', '1', '0.500'),
            $this->line('10', '1', '0.500'),
        ]);

        self::assertSame('PRICE-1', $result['algorithm_version']);
        self::assertSame('1.000', $result['subtotal_exact_vnd']);
        self::assertSame('1', $result['subtotal_vnd']);
        self::assertSame('0.000', $result['rounding_adjustment_vnd']);
        self::assertSame(['10', '20'], array_column($result['lines'], 'line_id'));
        self::assertSame(['1', '0'], array_column($result['lines'], 'line_subtotal_vnd'));
        self::assertSame(['0.500', '-0.500'], array_column($result['lines'], 'rounding_allocation_vnd'));
        self::assertSame('1', $result['total_vnd']);
        self::assertSame('1', $this->sum($result['lines'], 'line_total_vnd'));
    }

    public function testSubVndRawAmountsAreNotIndependentlyRounded(): void
    {
        $result = PricingCalculator::calculate([
            $this->line('1', '1', '0.499'),
            $this->line('2', '1', '0.499'),
        ]);

        self::assertSame('0.998', $result['subtotal_exact_vnd']);
        self::assertSame('1', $result['subtotal_vnd']);
        self::assertSame('1', $this->sum($result['lines'], 'line_subtotal_vnd'));
        self::assertSame('0.002', $this->sum($result['lines'], 'rounding_allocation_vnd'));
        self::assertSame(['1', '0'], array_column($result['lines'], 'line_subtotal_vnd'));
    }

    public function testNegativeDocumentRoundingBridgeIsPreserved(): void
    {
        $result = PricingCalculator::calculate([$this->line('1', '1', '1.499')]);

        self::assertSame('1.499', $result['subtotal_exact_vnd']);
        self::assertSame('1', $result['subtotal_vnd']);
        self::assertSame('-0.499', $result['rounding_adjustment_vnd']);
        self::assertSame('-0.499', $result['lines'][0]['rounding_allocation_vnd']);
    }

    public function testDiscountAndTaxAllocateWithoutNegativeLineAmounts(): void
    {
        $result = PricingCalculator::calculate([
            $this->line('20', '1', '1.000'),
            $this->line('10', '1', '1.000'),
        ], '5000', '5000');

        self::assertSame('2', $result['subtotal_vnd']);
        self::assertSame('1', $result['discount_vnd']);
        self::assertSame(['1', '0'], array_column($result['lines'], 'discount_vnd'));
        self::assertSame('1', $result['tax_vnd']);
        self::assertSame(['0', '1'], array_column($result['lines'], 'tax_vnd'));
        self::assertSame(['0', '2'], array_column($result['lines'], 'line_total_vnd'));
        self::assertSame('2', $result['total_vnd']);

        foreach ($result['lines'] as $line) {
            self::assertGreaterThanOrEqual(0, (int) $line['line_subtotal_vnd'] - (int) $line['discount_vnd']);
            self::assertGreaterThanOrEqual(0, (int) $line['line_total_vnd']);
        }
    }

    public function testHundredPercentDiscountReturnsZeroDraftAndHandlesZeroWeights(): void
    {
        $result = PricingCalculator::calculate([
            $this->line('2', '1', '0.500'),
            $this->line('1', '0', '0.500'),
        ], '10000', '0');

        self::assertSame('1', $result['subtotal_vnd']);
        self::assertSame('1', $result['discount_vnd']);
        self::assertSame('0', $result['tax_vnd']);
        self::assertSame('0', $result['total_vnd']);
        self::assertSame(['0', '1'], array_column($result['lines'], 'line_subtotal_vnd'));
        self::assertSame(['0', '1'], array_column($result['lines'], 'discount_vnd'));
        self::assertSame(['0', '0'], array_column($result['lines'], 'line_total_vnd'));
    }

    public function testDiscountHalfUpBoundary(): void
    {
        $half = PricingCalculator::calculate([$this->line('1', '1', '1.000')], '5000');
        $below = PricingCalculator::calculate([$this->line('1', '1', '1.000')], '4999');

        self::assertSame('1', $half['discount_vnd']);
        self::assertSame('0', $below['discount_vnd']);
    }

    public function testTaxRateHasNoBusinessCeilingButAmountsRemainBounded(): void
    {
        $result = PricingCalculator::calculate([$this->line('1', '1', '2.000')], '0', '25000');

        self::assertSame('5', $result['tax_vnd']);
        self::assertSame('7', $result['total_vnd']);
        self::assertSame('25000', $result['tax_bps']);
    }

    public function testAcceptsManySubVndLinesWithoutCalculatorSpecificLineLimit(): void
    {
        $lines = [];
        for ($i = 1; $i <= 499; ++$i) {
            $lines[] = $this->line((string) $i, '1', '0.001');
        }

        $result = PricingCalculator::calculate($lines);

        self::assertCount(499, $result['lines']);
        self::assertSame('0.499', $result['subtotal_exact_vnd']);
        self::assertSame('0', $result['subtotal_vnd']);
        self::assertSame('-0.499', $result['rounding_adjustment_vnd']);
        self::assertSame('0', $this->sum($result['lines'], 'line_subtotal_vnd'));
    }

    public function testSourceLinePermutationDoesNotChangeResults(): void
    {
        $lines = [
            $this->line('30', '7', '0.125'),
            $this->line('10', '7', '0.125'),
            $this->line('20', '7', '0.125'),
        ];

        self::assertSame(
            PricingCalculator::calculate($lines),
            PricingCalculator::calculate(array_reverse($lines))
        );
    }

    public function testDuplicateProductsAreOutsideCalculatorIdentityButDuplicateLineIdsFail(): void
    {
        $result = PricingCalculator::calculate([
            $this->line('2', '1', '0.500'),
            $this->line('1', '1', '0.500'),
        ]);
        self::assertSame(['1', '2'], array_column($result['lines'], 'line_id'));

        $this->expectException(InvalidArgumentException::class);
        PricingCalculator::calculate([
            $this->line('1', '1', '1'),
            $this->line('1', '2', '1'),
        ]);
    }

    public function testRejectsMalformedOrNonCanonicalInputs(): void
    {
        $invalidLines = [
            ['line_id' => '0', 'unit_price_vnd' => '1', 'quantity' => '1', 'unit_step' => '1'],
            ['line_id' => '01', 'unit_price_vnd' => '1', 'quantity' => '1', 'unit_step' => '1'],
            ['line_id' => '1', 'unit_price_vnd' => '01', 'quantity' => '1', 'unit_step' => '1'],
            ['line_id' => '1', 'unit_price_vnd' => '1.0', 'quantity' => '1', 'unit_step' => '1'],
            ['line_id' => '1', 'unit_price_vnd' => '1', 'quantity' => '1e0', 'unit_step' => '1'],
            ['line_id' => '1', 'unit_price_vnd' => '1', 'quantity' => '0', 'unit_step' => '1'],
            ['line_id' => '1', 'unit_price_vnd' => '1', 'quantity' => '1.0001', 'unit_step' => '0.001'],
            ['line_id' => '1', 'unit_price_vnd' => '1', 'quantity' => '0.500', 'unit_step' => '1'],
            ['line_id' => '1', 'unit_price_vnd' => 1, 'quantity' => '1', 'unit_step' => '1'],
        ];

        foreach ($invalidLines as $line) {
            try {
                PricingCalculator::calculate([$line]);
                self::fail('Expected malformed input to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        foreach (['00', '-1', '1.0', '1e2', '9223372036854775808'] as $bps) {
            try {
                PricingCalculator::calculate([$this->line('1', '1', '1')], $bps);
                self::fail('Expected invalid discount bps to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRejectsEmptyDocumentAndTaxOverflow(): void
    {
        try {
            PricingCalculator::calculate([]);
            self::fail('Expected empty document to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        PricingCalculator::calculate([
            $this->line('1', ExactAmounts::MAX_VND, '1'),
        ], '0', ExactAmounts::MAX_VND);
    }

    public function testRejectsFinalTotalOverflowEvenWhenTaxFitsBigint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PricingCalculator::calculate([
            $this->line('1', ExactAmounts::MAX_VND, '1'),
        ], '0', '1');
    }

    public function testDeterministicPropertiesAcrossGeneratedInputs(): void
    {
        for ($case = 1; $case <= 120; ++$case) {
            $lines = [];
            $count = ($case % 7) + 1;
            for ($index = 0; $index < $count; ++$index) {
                $price = (string) (($case * 37 + $index * 19) % 1000);
                $milli = (($case * 113 + $index * 271) % 2500) + 1;
                $quantity = intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
                $lines[] = $this->line((string) (1000 + $index * 3), $price, $quantity);
            }
            $discountBps = (string) (($case * 1379) % 10001);
            $taxBps = (string) (($case * 997) % 50001);

            $result = PricingCalculator::calculate($lines, $discountBps, $taxBps);
            $raw = '0.000';
            foreach ($result['lines'] as $line) {
                $raw = bcadd($raw, $line['line_subtotal_exact_vnd'], 3);
            }
            $rounded = bcadd($raw, '0.500', 0);

            self::assertSame($raw, $result['subtotal_exact_vnd']);
            self::assertSame($rounded, $result['subtotal_vnd']);
            self::assertSame($rounded, $this->sum($result['lines'], 'line_subtotal_vnd'));
            self::assertSame(
                bcsub($rounded, $raw, 3),
                $this->sum($result['lines'], 'rounding_allocation_vnd')
            );
            self::assertSame($result['discount_vnd'], $this->sum($result['lines'], 'discount_vnd'));
            self::assertSame($result['tax_vnd'], $this->sum($result['lines'], 'tax_vnd'));
            self::assertSame($result['total_vnd'], $this->sum($result['lines'], 'line_total_vnd'));
            self::assertSame(
                bcadd(bcsub($result['subtotal_vnd'], $result['discount_vnd'], 0), $result['tax_vnd'], 0),
                $result['total_vnd']
            );

            foreach ($result['lines'] as $line) {
                self::assertGreaterThanOrEqual(0, (int) $line['line_subtotal_vnd'] - (int) $line['discount_vnd']);
                self::assertGreaterThanOrEqual(0, (int) $line['tax_vnd']);
                self::assertGreaterThanOrEqual(0, (int) $line['line_total_vnd']);
            }
            self::assertSame(
                $result,
                PricingCalculator::calculate(array_reverse($lines), $discountBps, $taxBps)
            );
        }
    }

    /** @return array{line_id: string, unit_price_vnd: string, quantity: string, unit_step: string} */
    private function line(string $id, string $price, string $quantity): array
    {
        return [
            'line_id' => $id,
            'unit_price_vnd' => $price,
            'quantity' => $quantity,
            'unit_step' => '0.001',
        ];
    }

    /** @param list<array<string, string>> $lines */
    private function sum(array $lines, string $field): string
    {
        $sum = '0';
        foreach ($lines as $line) {
            $sum = bcadd($sum, $line[$field], str_contains($line[$field], '.') ? 3 : 0);
        }
        return $sum;
    }
}
