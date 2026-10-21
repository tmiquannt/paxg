<?php
declare(strict_types=1);

namespace MiniERP\Security;

/** Canonical parsing for WordPress form scopes and serialized user-meta scopes. */
final class ScopeAssignmentIds
{
    /** @return list<int>|null */
    public static function parseInput(mixed $value): ?array
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        return self::normalizeParts(explode(',', $value));
    }

    /** @return list<int>|null */
    public static function parseSelection(mixed $value): ?array
    {
        return is_array($value) ? self::normalizeStored($value) : self::parseInput($value);
    }

    /** @return list<int>|null */
    public static function normalizeStored(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        return self::normalizeParts($value);
    }

    /** @param list<mixed> $parts @return list<int>|null */
    private static function normalizeParts(array $parts): ?array
    {
        if (count($parts) > 100) {
            return null;
        }

        $ids = [];
        foreach ($parts as $part) {
            if (is_int($part) && $part > 0) {
                $ids[] = $part;
                continue;
            }
            if (is_string($part)) {
                $part = trim($part);
            }
            if (!is_string($part) || !preg_match('/\A[1-9][0-9]*\z/D', $part)) {
                return null;
            }
            $id = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false || (string) $id !== $part) {
                return null;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }
}
