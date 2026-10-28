<?php

namespace App\Contracts\Fingerprint;

use App\Models\FingerprintDevice;

interface FingerprintDeviceDriverInterface
{
    public function connect(FingerprintDevice $device): bool;

    public function disconnect(): void;

    public function testConnection(FingerprintDevice $device, int $timeoutSeconds = 5): array;

    public function getDeviceInfo(FingerprintDevice $device, int $timeoutSeconds = 5): array;

    /**
     * Pull raw attendance punches from the device without processing them.
     */
    public function pullAttendance(FingerprintDevice $device, int $timeoutSeconds = 10, int $retries = 1): iterable;
}
