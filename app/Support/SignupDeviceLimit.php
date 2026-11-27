<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lifetime cap on accounts created from one device. A device is a long-lived
 * random browser cookie plus the IP address. Either one reaching the cap blocks
 * the signup. Only hashes are stored.
 */
class SignupDeviceLimit
{
    public const MAX_ACCOUNTS = 3;
    /** Looser cap for the IP alone, since offices and mobile carriers share one. */
    public const MAX_PER_IP = 10;
    public const COOKIE = 'odir_device';

    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    /** The host sits behind a proxy, so prefer the forwarded client address. */
    public static function clientIp(Request $request): string
    {
        $cf = trim((string) $request->headers->get('CF-Connecting-IP', ''));
        if (filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = trim(explode(',', (string) $request->headers->get('X-Forwarded-For', ''))[0]);
        if (filter_var($xff, FILTER_VALIDATE_IP)) {
            return $xff;
        }

        return (string) $request->ip();
    }

    public static function enabled(): bool
    {
        return Schema::hasTable('signup_devices');
    }

    public static function reached(Request $request): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $ip = DB::table('signup_devices')->where('ip_hash', self::hash(self::clientIp($request)))->count();
        if ($ip >= self::MAX_PER_IP) {
            return true;
        }

        $token = $request->cookie(self::COOKIE);

        return is_string($token) && $token !== ''
            && DB::table('signup_devices')->where('token_hash', self::hash($token))->count() >= self::MAX_ACCOUNTS;
    }

    /** Records a signup and returns the cookie token to set. */
    public static function record(Request $request, int $userId): ?string
    {
        if (!self::enabled()) {
            return null;
        }

        $token = $request->cookie(self::COOKIE);
        if (!is_string($token) || strlen($token) < 20) {
            $token = Str::random(48);
        }

        DB::table('signup_devices')->insert([
            'token_hash' => self::hash($token),
            'ip_hash' => self::hash(self::clientIp($request)),
            'user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
