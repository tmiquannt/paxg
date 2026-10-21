<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

/** Converts PHP-parsed counts[product_id] form keys into service count lines. */
final class InventoryCountInput
{
    /** @param array<array-key,mixed> $raw @return list<array{product_id:int,counted_qty:string}>|null */
    public static function fromForm(array $raw): ?array
    {
        $counts = [];
        foreach ($raw as $key => $quantity) {
            if (is_int($key)) {
                $productId = $key;
            } elseif (is_string($key) && preg_match('/\A[1-9][0-9]*\z/D', $key)) {
                $parsed = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($parsed === false || (string) $parsed !== $key) {
                    return null;
                }
                $productId = $parsed;
            } else {
                return null;
            }

            if ($productId < 1 || !is_scalar($quantity)) {
                return null;
            }
            $counts[] = ['product_id' => $productId, 'counted_qty' => (string) $quantity];
        }

        return $counts;
    }
}
