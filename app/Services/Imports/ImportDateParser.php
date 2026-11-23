<?php

namespace App\Services\Imports;

use Carbon\Carbon;

/**
 * Date text from import sheets. A written date such as 05/11/2026 is day
 * first (5 November), never month first, and an Excel serial number is
 * accepted. Anything else falls back to Carbon, as before.
 */
final class ImportDateParser
{
    public static function toDate(mixed $value): ?string
    {
        if (!filled($value)) {
            return null;
        }
        $text = trim(strtr((string) $value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]));
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?:[ T].*)?$/', $text, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1])
                : null;
        }
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $text, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $text) && (float) $text > 20000 && (float) $text < 80000) {
            return (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))
                ->modify('+' . (int) floor((float) $text) . ' days')->format('Y-m-d');
        }

        try {
            return Carbon::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
