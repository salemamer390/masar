<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** New signups must use a Gmail address (gmail.com or googlemail.com). */
class GmailAddress implements ValidationRule
{
    public const DOMAINS = ['gmail.com', 'googlemail.com'];

    public static function passes(mixed $value): bool
    {
        if (!is_string($value) || substr_count($value, '@') !== 1) {
            return false;
        }

        $domain = strtolower(trim(substr($value, strrpos($value, '@') + 1)));

        return in_array($domain, self::DOMAINS, true);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!self::passes($value)) {
            $fail(__('يُقبل التسجيل ببريد Gmail فقط (gmail.com). يرجى استخدام بريد Gmail صحيح.'));
        }
    }
}
