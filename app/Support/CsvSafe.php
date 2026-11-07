<?php

namespace App\Support;

/**
 * Guards CSV exports against spreadsheet formula injection: a text cell that
 * begins with =, +, -, @, tab or CR is executed as a formula when the export
 * is opened in a spreadsheet app. Prefix such text cells with a single quote
 * so they render as text. Numeric values are exported untouched.
 */
class CsvSafe
{
    public static function row(array $cells): array
    {
        return array_map([self::class, 'cell'], $cells);
    }

    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }

        return $value;
    }
}
