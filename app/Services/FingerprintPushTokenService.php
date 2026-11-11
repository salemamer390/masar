<?php

namespace App\Services;

use App\Models\FingerprintDevice;
use Illuminate\Support\Str;

/**
 * Per-device secrets for device-initiated ingestion (ADMS push + local bridge).
 *
 * The plain secret is returned exactly once to the admin who generates it and
 * only its sha256 hash is stored. A device serial number is an identity, not
 * authentication: ingestion endpoints must always require this secret.
 */
class FingerprintPushTokenService extends BaseService
{
    /**
     * Generate a new secret for the device and persist its hash.
     *
     * @return string the plain secret, shown once to the caller
     */
    public function regenerate(FingerprintDevice $device): string
    {
        $plain = Str::random(48);

        $device->forceFill([
            'push_token_hash' => $this->hash($plain),
            'push_token_set_at' => now(),
        ])->save();

        return $plain;
    }

    public function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function matches(FingerprintDevice $device, ?string $plain): bool
    {
        if (! $device->push_token_hash || ! $plain) {
            return false;
        }

        return hash_equals($device->push_token_hash, $this->hash($plain));
    }
}
