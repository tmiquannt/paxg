<?php
declare(strict_types=1);

namespace MiniERP\Infrastructure;

use RuntimeException;

/** Builds bounded, Excel-safe UTF-8 CSV downloads from explicit field projections. */
final class CsvRenderer
{
    /** @param array<string, string> $columns @param list<array<string, mixed>> $rows */
    public static function render(array $columns, array $rows): string
    {
        if ($columns === []) {
            throw new RuntimeException('CSV columns are required.');
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary CSV stream.');
        }

        try {
            if (fwrite($stream, "\xEF\xBB\xBF") !== 3
                || fputcsv($stream, array_values($columns), ',', '"', '', "\r\n") === false
            ) {
                throw new RuntimeException('Could not write the CSV header.');
            }
            foreach ($rows as $row) {
                $values = [];
                foreach (array_keys($columns) as $field) {
                    $values[] = self::cell($row[$field] ?? '');
                }
                if (fputcsv($stream, $values, ',', '"', '', "\r\n") === false) {
                    throw new RuntimeException('Could not write a CSV row.');
                }
            }

            rewind($stream);
            $csv = stream_get_contents($stream);
            if (!is_string($csv)) {
                throw new RuntimeException('Could not read the rendered CSV.');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (!is_scalar($value)) {
            throw new RuntimeException('CSV cell values must be scalar.');
        }

        $cell = (string) $value;
        if (preg_match('/\A[\x00-\x20]*[=+\-@]/', $cell) === 1) {
            return "'" . $cell;
        }
        return $cell;
    }
}
