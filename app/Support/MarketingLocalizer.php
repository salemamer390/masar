<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;

/**
 * Translates the static marketing HTML pages at serve time.
 *
 * The marketing site ships as plain HTML files (no Blade), so user-facing
 * Arabic text is passed through __() here. When the active locale is Arabic
 * the HTML is returned untouched; any string missing from the locale files
 * falls back to its Arabic source unchanged.
 */
class MarketingLocalizer
{
    /**
     * Translate Arabic text nodes and common text attributes in the given HTML.
     */
    public static function translate(string $html): string
    {
        $isRtl = SetLocale::isRtl();

        // Keep the document language/direction in sync with the active locale.
        $html = preg_replace(
            '/<html\s+lang="ar"\s+dir="rtl">/',
            '<html lang="' . e(app()->getLocale()) . '" dir="' . ($isRtl ? 'rtl' : 'ltr') . '">',
            $html,
            1
        ) ?? $html;

        if ($isRtl) {
            return $html;
        }

        // Attributes that carry user-visible text.
        $html = preg_replace_callback(
            '/\b(placeholder|title|aria-label|alt|value)="([^"]*[؀-ۿ][^"]*)"/u',
            fn ($m) => $m[1] . '="' . e(__($m[2])) . '"',
            $html
        ) ?? $html;

        // Whole text nodes between tags (scripts/styles have no Arabic copy here,
        // and a missing translation falls back to the Arabic source unchanged).
        $html = preg_replace_callback(
            '/>([^<>]*[؀-ۿ][^<>]*)</u',
            function ($m) {
                $node = $m[1];
                $translated = __(trim($node));

                return '>' . e($translated) . '<';
            },
            $html
        ) ?? $html;

        return $html;
    }
}
