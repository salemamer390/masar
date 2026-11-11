<?php

namespace App\Http\Middleware;

use App\Enums\FingerprintConnectionMode;
use App\Models\FingerprintDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token authentication for the local-bridge API. The token resolves
 * exactly one active bridge device; the request serial must also match it.
 */
class AuthenticateFingerprintBridge
{
    public const DEVICE_ATTRIBUTE = 'fingerprint_bridge_device';

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if (! $plain || strlen($plain) < 32) {
            return response()->json(['message' => 'Missing or invalid device token.'], 401);
        }

        $device = FingerprintDevice::query()
            ->where('push_token_hash', hash('sha256', $plain))
            ->where('connection_mode', FingerprintConnectionMode::LocalBridge->value)
            ->where('is_active', true)
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Missing or invalid device token.'], 401);
        }

        $serial = trim((string) ($request->input('device_serial') ?: $request->header('X-Device-Serial', '')));

        if ($serial === '' || ! $device->serial_number || ! hash_equals((string) $device->serial_number, $serial)) {
            return response()->json(['message' => 'Device serial does not match the token.'], 403);
        }

        $request->attributes->set(self::DEVICE_ATTRIBUTE, $device);

        return $next($request);
    }
}
