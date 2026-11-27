<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Blocks known disposable / temp-mail domains (list: resources/data/disposable-email-domains.txt). */
class NotDisposableEmail implements ValidationRule
{
    private static ?array $domains = null;

    private static function domains(): array
    {
        if (self::$domains === null) {
            $path = dirname(__DIR__, 2) . '/resources/data/disposable-email-domains.txt';
            $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            self::$domains = array_flip(array_map(fn ($l) => strtolower(trim($l)), $lines ?: []));
        }

        return self::$domains;
    }

    /** True when the address domain, or any parent domain of it, is on the list. */
    public static function isDisposable(mixed $value): bool
    {
        if (!is_string($value) || !str_contains($value, '@')) {
            return false;
        }

        $host = strtolower(trim(substr($value, strrpos($value, '@') + 1)));
        $parts = explode('.', $host);
        $list = self::domains();
        while (count($parts) >= 2) {
            if (isset($list[implode('.', $parts)])) {
                return true;
            }
            array_shift($parts);
        }

        return false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::isDisposable($value)) {
            $fail(__('لا يمكن التسجيل ببريد إلكتروني مؤقت. يرجى استخدام بريدك الإلكتروني الحقيقي.'));
        }
    }
}
