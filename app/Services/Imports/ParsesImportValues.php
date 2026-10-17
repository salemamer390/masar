<?php

namespace App\Services\Imports;

use InvalidArgumentException;

/**
 * Pure value parsers/normalisers used by the employee import (no database access),
 * kept separate so they can be unit-tested directly.
 */
trait ParsesImportValues
{
    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => ',',
    ];

    /**
     * Normalised comparison key: lower case, no diacritics/tatweel, unified
     * Arabic letter forms, no spaces or punctuation.
     */
    public function key(string $value): string
    {
        $value = mb_strtolower(trim($this->latinDigits($value)), 'UTF-8');
        $value = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value);
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }

    private function latinDigits(string $value): string
    {
        return strtr($value, self::DIGIT_MAP);
    }

    private function cleanText(?string $value): string
    {
        $value = (string) preg_replace('/[\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', ' ', (string) $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Plain-text rendering of identifiers that Excel may have stored as numbers
     * (Arabic digits, "2.98E+13", "101.0").
     */
    private function numericText(string $value): string
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d(\.\d+)?[eE]\+\d+$/', $value)) {
            return sprintf('%.0f', (float) $value);
        }
        if (preg_match('/^\d+\.0+$/', $value)) {
            return explode('.', $value)[0];
        }

        return $value;
    }

    /**
     * @return array{birth_date: string, gender: string}|string Error message when invalid
     */
    private function nationalIdData(string $nid): array|string
    {
        if (strlen($nid) !== 14) {
            return 'الرقم القومي يجب أن يتكون من 14 رقمًا.';
        }
        if (!in_array($nid[0], ['2', '3'], true)) {
            return 'الرقم القومي غير صحيح (يجب أن يبدأ بالرقم 2 أو 3).';
        }
        $year = (int) substr($nid, 1, 2) + ($nid[0] === '3' ? 2000 : 1900);
        $month = (int) substr($nid, 3, 2);
        $day = (int) substr($nid, 5, 2);
        if (!checkdate($month, $day, $year)) {
            return 'تاريخ الميلاد داخل الرقم القومي غير صحيح.';
        }

        return [
            'birth_date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'gender' => ((int) $nid[12]) % 2 === 1 ? 'ذكر' : 'أنثى',
        ];
    }

    private function normalizePhone(string $value): ?string
    {
        $value = $this->numericText($value);
        $value = (string) preg_replace('/[\s\-().]+/u', '', $value);
        if (!preg_match('/^\+?\d{6,20}$/', $value)) {
            return null;
        }
        // Excel drops the leading zero of mobile numbers stored as numbers.
        if (preg_match('/^1\d{9}$/', $value)) {
            $value = '0' . $value;
        }

        return $value;
    }

    private function parseGender(string $value): ?string
    {
        $key = $this->key($value);
        if (in_array($key, ['ذكر', 'ذكور', 'male', 'm', 'رجل'], true)) {
            return 'ذكر';
        }
        if (in_array($key, ['انثي', 'اناث', 'female', 'f', 'سيده', 'امراه'], true)) {
            return 'أنثى';
        }

        return null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?:\s.*)?$/', $value, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $value) && (float) $value > 20000 && (float) $value < 80000) {
            $date = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify('+' . (int) floor((float) $value) . ' days');

            return $date->format('Y-m-d');
        } else {
            throw new InvalidArgumentException('تاريخ غير صالح «' . $value . '». استخدم الصيغة 2026-01-31 أو 31/01/2026.');
        }

        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('تاريخ غير صالح «' . $value . '».');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseTime(string $value): ?string
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(?:\d{4}-\d{2}-\d{2}[ T]+)?(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm|ص|م)?$/iu', $value, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            $second = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
            $meridiem = isset($m[4]) ? mb_strtolower($m[4]) : '';
            if (($meridiem === 'pm' || $meridiem === 'م') && $hour < 12) {
                $hour += 12;
            } elseif (($meridiem === 'am' || $meridiem === 'ص') && $hour === 12) {
                $hour = 0;
            }
        } elseif (preg_match('/^0?\.\d+$/', $value)) {
            $seconds = (int) round(((float) $value) * 86400);
            $hour = intdiv($seconds, 3600);
            $minute = intdiv($seconds % 3600, 60);
            $second = $seconds % 60;
        } else {
            throw new InvalidArgumentException('وقت غير صالح «' . $value . '». استخدم الصيغة 09:00.');
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw new InvalidArgumentException('وقت غير صالح «' . $value . '».');
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    private function parseMoney(string $value): ?float
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return null;
        }

        $clean = trim((string) preg_replace('/[^\d.,\-]/u', '', $value), '.,');
        if ($clean === '' || $clean === '-') {
            throw new InvalidArgumentException('قيمة غير رقمية «' . $value . '».');
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = preg_match('/^\d{1,3}(,\d{3})+$/', $clean) ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
        }

        if (!is_numeric($clean)) {
            throw new InvalidArgumentException('قيمة غير رقمية «' . $value . '».');
        }

        return round((float) $clean, 2);
    }

    /** Character-level Levenshtein similarity in [0, 1] (multibyte safe). */
    private function similarity(string $a, string $b): float
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);
        if ($n === 0 || $m === 0) {
            return 0.0;
        }

        $previous = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
            }
            $previous = $current;
        }

        return 1 - $previous[$m] / max($n, $m);
    }
}
