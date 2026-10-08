<?php

if (! function_exists('format_number')) {
    /**
     * Formats a numeric value for people without changing its stored value.
     * Trailing fractional zeroes are removed, while meaningful decimals remain.
     */
    function format_number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return '';
            }

            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        $number = trim(str_replace(',', '', (string) $value));
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $number, $matches)) {
            return (string) $value;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($matches[3]) ? rtrim($matches[3], '0') : '';
        $sign = $matches[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$integer.($fraction !== '' ? '.'.$fraction : '');
    }
}

if (! function_exists('format_money')) {
    /**
     * Presentation-only money formatter: nearest EGP with thousands grouping.
     * Database decimals and all calculator inputs remain untouched.
     */
    function format_money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $number = is_numeric($value) ? (float) $value : null;
        if ($number === null || ! is_finite($number)) {
            return (string) $value;
        }

        return number_format(round($number, 0, PHP_ROUND_HALF_UP), 0, '.', ',');
    }
}
