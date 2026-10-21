<?php
declare(strict_types=1);

namespace MiniERP\Domain;

use InvalidArgumentException;

/** Exact arithmetic only. This class does not define invoice persistence/allocation. */
final class ExactAmounts
{
    public const MAX_VND = '9223372036854775807';
    public const MAX_QUANTITY = '999999999999999.999';

    public static function money(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]{1,19}\z/D', (string) $value)) {
            throw new InvalidArgumentException('Số tiền phải là số nguyên VND không âm.');
        }
        $value = bcadd((string) $value, '0', 0);
        if (bccomp($value, self::MAX_VND, 0) > 0) {
            throw new InvalidArgumentException('Số tiền vượt giới hạn BIGINT.');
        }
        return $value;
    }

    public static function quantity(mixed $value, string $step): string
    {
        $value = self::positiveDecimal($value);
        $step = self::positiveDecimal($step);
        if (bccomp(bcmod($value, $step, 3), '0', 3) !== 0) {
            throw new InvalidArgumentException('Số lượng không đúng bước đơn vị.');
        }
        return $value;
    }

    private static function positiveDecimal(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[0-9]{1,15}(?:\.[0-9]{1,3})?\z/D', (string) $value)) {
            throw new InvalidArgumentException('Số lượng phải có tối đa ba chữ số thập phân.');
        }
        $value = bcadd((string) $value, '0', 3);
        if (bccomp($value, '0', 3) <= 0 || bccomp($value, self::MAX_QUANTITY, 3) > 0) {
            throw new InvalidArgumentException('Số lượng nằm ngoài giới hạn DECIMAL(18,3).');
        }
        return $value;
    }

    /** @param list<array{unit_price_vnd: string, quantity: string, unit_step: string}> $lines */
    public static function subtotal(array $lines): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Chứng từ phải có dòng hàng.');
        }
        $exact = '0.000';
        $lineAmounts = [];
        foreach ($lines as $line) {
            $amount = bcmul(self::money($line['unit_price_vnd']), self::quantity($line['quantity'], $line['unit_step']), 3);
            $exact = bcadd($exact, $amount, 3);
            // No native multiplication, float conversion or per-line rounding.
            $lineAmounts[] = $amount;
        }
        $rounded = self::money(bcadd($exact, '0.500', 0));
        return [
            'lines_exact_vnd' => $lineAmounts,
            'subtotal_exact_vnd' => $exact,
            'subtotal_vnd' => $rounded,
            'rounding_adjustment_vnd' => bcsub($rounded, $exact, 3),
        ];
    }
}
