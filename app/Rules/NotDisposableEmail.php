<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Blocks known disposable / temp-mail domains (list: resources/data/disposable-email-domains.txt). */
class NotDisposableEmail implements ValidationRule
{
    /** Real mail services that must never be blocked, even if a list update wrongly contains them. */
    private const ALLOWED = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com', 'rocketmail.com', 'outlook.com', 'hotmail.com',
        'live.com', 'msn.com', 'windowslive.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me',
        'protonmail.com', 'pm.me', 'zoho.com', 'gmx.com', 'gmx.net', 'mail.com', 'yandex.com', 'yandex.ru',
        'mail.ru', 'qq.com', '163.com', 'fastmail.com', 'tutanota.com', 'hey.com', 'duck.com',
    ];

    private static ?string $blob = null;

    /** The list as one "\ndomain\n..." string: far smaller in memory than a 100k-entry array. */
    private static function blob(): string
    {
        if (self::$blob === null) {
            $path = dirname(__DIR__, 2) . '/resources/data/disposable-email-domains.txt';
            $raw = is_readable($path) ? (string) file_get_contents($path) : '';
            self::$blob = "\n" . strtolower(str_replace("\r", '', $raw)) . "\n";
        }

        return self::$blob;
    }

    /** True when the address domain, or any parent domain of it, is on the list. */
    public static function isDisposable(mixed $value): bool
    {
        if (!is_string($value) || !str_contains($value, '@')) {
            return false;
        }

        $host = strtolower(trim(substr($value, strrpos($value, '@') + 1)));
        $parts = explode('.', $host);
        while (count($parts) >= 2) {
            $d = implode('.', $parts);
            if (in_array($d, self::ALLOWED, true)) {
                return false;
            }
            if ($d !== '' && str_contains(self::blob(), "\n" . $d . "\n")) {
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
