<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Localized weekday name for a date, shown in the "اليوم" column next to dates. */
class DayName
{
    public static function of(mixed $date, ?string $locale = null): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        try {
            if ($date instanceof CarbonInterface) {
                $carbon = Carbon::instance($date);
            } elseif (is_string($date) && preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})~', $date, $m)) {
                $carbon = Carbon::createFromFormat('!d/m/Y', sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3]));
            } else {
                $carbon = Carbon::parse((string) $date);
            }
        } catch (\Throwable) {
            return '';
        }

        return $carbon->locale($locale ?? app()->getLocale())->translatedFormat('l');
    }
}
