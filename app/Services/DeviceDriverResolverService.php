<?php

namespace App\Services;

use App\Contracts\Fingerprint\FingerprintDeviceDriverInterface;
use App\Devices\Fingerprint\Drivers\ZKTecoTcpDeviceDriver;
use App\Enums\FingerprintDeviceDriver;
use App\Models\FingerprintDevice;
use InvalidArgumentException;

class DeviceDriverResolverService
{
    public function resolve(FingerprintDevice $device): FingerprintDeviceDriverInterface
    {
        return match ($device->driver) {
            FingerprintDeviceDriver::ZKTecoTcp => app(ZKTecoTcpDeviceDriver::class),
            default => throw new InvalidArgumentException('Unsupported fingerprint device driver.'),
        };
    }

    public function supportedDrivers(): array
    {
        return FingerprintDeviceDriver::cases();
    }
}
