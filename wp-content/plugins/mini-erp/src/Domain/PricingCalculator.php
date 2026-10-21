<?php
declare(strict_types=1);

namespace MiniERP\Domain;

use InvalidArgumentException;

/** Pure, deterministic allocation of exact source-line amounts to document totals. */
final class PricingCalculator
{
    private const ALGORITHM_VERSION = 'PRICE-1';
    private const BASIS_POINT_DENOMINATOR = '10000';
    private const THREE_DECIMALS = 3;

    /**
     * @param list<array{line_id: string, unit_price_vnd: string, quantity: string, unit_step: string}> $lines
     * @return array<string, mixed>
     */
    public static function calculate(array $lines, string $discountBps = '0', string $taxBps = '0'): array
    {
        if (!array_is_list($lines) || $lines === []) {
            throw new InvalidArgumentException('Chứng từ phải có danh sách dòng hàng hợp lệ.');
        }

        $discountBps = self::canonicalInteger($discountBps, false, 'discount_bps');
        if (bccomp($discountBps, self::BASIS_POINT_DENOMINATOR, 0) > 0) {
            throw new InvalidArgumentException('Tỷ lệ chiết khấu phải nằm trong khoảng 0..10000 bps.');
        }
        $taxBps = self::canonicalInteger($taxBps, false, 'tax_bps');

        $normalizedLines = [];
        $seenIds = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                throw new InvalidArgumentException('Dòng hàng không hợp lệ.');
            }
            foreach (['line_id', 'unit_price_vnd', 'quantity', 'unit_step'] as $field) {
                if (!array_key_exists($field, $line) || !is_string($line[$field])) {
                    throw new InvalidArgumentException('Thiếu hoặc sai kiểu dữ liệu dòng hàng.');
                }
            }

            $lineId = self::canonicalInteger($line['line_id'], true, 'line_id');
            $idKey = 'id:' . $lineId;
            if (isset($seenIds[$idKey])) {
                throw new InvalidArgumentException('Mã dòng hàng bị trùng.');
            }
            $seenIds[$idKey] = true;

            $price = self::canonicalInteger($line['unit_price_vnd'], false, 'unit_price_vnd');
            $quantity = self::canonicalDecimal($line['quantity'], 'quantity');
            $unitStep = self::canonicalDecimal($line['unit_step'], 'unit_step');

            // Let the accepted shared helper enforce BIGINT, DECIMAL and unit-step bounds.
            $price = ExactAmounts::money($price);
            $quantity = ExactAmounts::quantity($quantity, $unitStep);
            $normalizedLines[] = [
                'line_id' => $lineId,
                'unit_price_vnd' => $price,
                'quantity' => $quantity,
                'unit_step' => ExactAmounts::quantity($unitStep, $unitStep),
            ];
        }

        usort($normalizedLines, static fn (array $left, array $right): int => bccomp($left['line_id'], $right['line_id'], 0));

        $subtotalInput = array_map(
            static fn (array $line): array => [
                'unit_price_vnd' => $line['unit_price_vnd'],
                'quantity' => $line['quantity'],
                'unit_step' => $line['unit_step'],
            ],
            $normalizedLines
        );
        $subtotalResult = ExactAmounts::subtotal($subtotalInput);

        $lineIds = array_column($normalizedLines, 'line_id');
        $rawLineAmounts = $subtotalResult['lines_exact_vnd'];
        $allocatedSubtotals = self::allocateRoundedSubtotal(
            $rawLineAmounts,
            $lineIds,
            $subtotalResult['subtotal_vnd']
        );

        $discount = self::roundBasisPointAmount($subtotalResult['subtotal_vnd'], $discountBps);
        $discount = ExactAmounts::money($discount);
        $lineDiscounts = self::allocateProportionally($discount, $allocatedSubtotals, $lineIds);

        $taxable = bcsub($subtotalResult['subtotal_vnd'], $discount, 0);
        $tax = self::roundBasisPointAmount($taxable, $taxBps);
        $tax = ExactAmounts::money($tax);
        $lineNetAmounts = [];
        foreach ($lineIds as $index => $lineId) {
            $lineNetAmounts[$index] = bcsub($allocatedSubtotals[$index], $lineDiscounts[$index], 0);
        }
        $lineTaxes = self::allocateProportionally($tax, $lineNetAmounts, $lineIds);

        $total = bcadd($taxable, $tax, 0);
        $total = ExactAmounts::money($total);

        $resultLines = [];
        foreach ($normalizedLines as $index => $line) {
            $lineTotal = bcadd($lineNetAmounts[$index], $lineTaxes[$index], 0);
            $lineTotal = ExactAmounts::money($lineTotal);
            $resultLines[] = [
                'line_id' => $line['line_id'],
                'unit_price_vnd' => $line['unit_price_vnd'],
                'quantity' => $line['quantity'],
                'line_subtotal_exact_vnd' => $rawLineAmounts[$index],
                'rounding_allocation_vnd' => bcsub(
                    $allocatedSubtotals[$index],
                    $rawLineAmounts[$index],
                    self::THREE_DECIMALS
                ),
                'line_subtotal_vnd' => $allocatedSubtotals[$index],
                'discount_vnd' => $lineDiscounts[$index],
                'tax_vnd' => $lineTaxes[$index],
                'line_total_vnd' => $lineTotal,
            ];
        }

        return [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'subtotal_exact_vnd' => $subtotalResult['subtotal_exact_vnd'],
            'rounding_adjustment_vnd' => $subtotalResult['rounding_adjustment_vnd'],
            'subtotal_vnd' => $subtotalResult['subtotal_vnd'],
            'discount_bps' => $discountBps,
            'discount_vnd' => $discount,
            'tax_bps' => $taxBps,
            'tax_vnd' => $tax,
            'total_vnd' => $total,
            'lines' => $resultLines,
        ];
    }

    /** @param list<string> $rawAmounts @param list<string> $lineIds @return list<string> */
    private static function allocateRoundedSubtotal(array $rawAmounts, array $lineIds, string $subtotal): array
    {
        $allocated = [];
        $remainders = [];
        $floorTotal = '0';
        foreach ($rawAmounts as $index => $raw) {
            $floor = bcadd($raw, '0', 0);
            $allocated[$index] = $floor;
            $remainders[$index] = bcsub($raw, $floor, self::THREE_DECIMALS);
            $floorTotal = bcadd($floorTotal, $floor, 0);
        }

        $remaining = bcsub($subtotal, $floorTotal, 0);
        return self::distributeRemainderUnits($allocated, $remainders, $lineIds, $remaining);
    }

    /**
     * @param list<string> $weights
     * @param list<string> $lineIds
     * @return list<string>
     */
    private static function allocateProportionally(string $amount, array $weights, array $lineIds): array
    {
        $weightTotal = '0';
        foreach ($weights as $weight) {
            $weightTotal = bcadd($weightTotal, $weight, 0);
        }
        if ($weightTotal === '0') {
            if ($amount !== '0') {
                throw new InvalidArgumentException('Không thể phân bổ số tiền trên dòng có tổng trọng số bằng không.');
            }
            return array_fill(0, count($weights), '0');
        }

        $allocated = [];
        $remainders = [];
        $allocatedTotal = '0';
        foreach ($weights as $index => $weight) {
            $product = bcmul($amount, $weight, 0);
            $allocated[$index] = bcdiv($product, $weightTotal, 0);
            $remainders[$index] = bcmod($product, $weightTotal, 0);
            $allocatedTotal = bcadd($allocatedTotal, $allocated[$index], 0);
        }

        $remaining = bcsub($amount, $allocatedTotal, 0);
        return self::distributeRemainderUnits($allocated, $remainders, $lineIds, $remaining);
    }

    /** @param list<string> $allocated @param list<string> $remainders @param list<string> $lineIds @return list<string> */
    private static function distributeRemainderUnits(array $allocated, array $remainders, array $lineIds, string $remaining): array
    {
        if (bccomp($remaining, '0', 0) < 0 || bccomp($remaining, (string) count($allocated), 0) > 0) {
            throw new InvalidArgumentException('Phân bổ số dư không hợp lệ.');
        }
        $unitsLeft = (int) $remaining;
        if ($unitsLeft === 0) {
            return $allocated;
        }

        $order = array_keys($allocated);
        usort($order, static function (int $left, int $right) use ($remainders, $lineIds): int {
            $byRemainder = bccomp($remainders[$right], $remainders[$left], self::THREE_DECIMALS);
            return $byRemainder !== 0 ? $byRemainder : bccomp($lineIds[$left], $lineIds[$right], 0);
        });

        for ($index = 0; $index < $unitsLeft; ++$index) {
            $winner = $order[$index];
            $allocated[$winner] = bcadd($allocated[$winner], '1', 0);
        }
        return $allocated;
    }

    private static function roundBasisPointAmount(string $amount, string $basisPoints): string
    {
        $product = bcmul($amount, $basisPoints, 0);
        $numerator = bcadd($product, '5000', 0);
        return bcdiv($numerator, self::BASIS_POINT_DENOMINATOR, 0);
    }

    private static function canonicalInteger(string $value, bool $positive, string $field): string
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
            throw new InvalidArgumentException('Giá trị ' . $field . ' phải là chuỗi số nguyên chuẩn.');
        }
        $normalized = ExactAmounts::money($value);
        if ($positive && $normalized === '0') {
            throw new InvalidArgumentException('Giá trị ' . $field . ' phải lớn hơn không.');
        }
        return $normalized;
    }

    private static function canonicalDecimal(string $value, string $field): string
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,3})?\z/D', $value)) {
            throw new InvalidArgumentException('Giá trị ' . $field . ' phải là chuỗi thập phân chuẩn.');
        }
        return $value;
    }
}
