<?php

namespace App\Enums;

enum FingerprintDeviceDriver: string
{
    case ZKTecoTcp = 'zkteco_tcp';

    public function label(): string
    {
        return match ($this) {
            self::ZKTecoTcp => 'ZKTeco TCP',
        };
    }
}
