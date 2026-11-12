<?php

namespace App\Enums;

enum FingerprintConnectionMode: string
{
    case ServerPull = 'server_pull';
    case AdmsPush = 'adms_push';
    case LocalBridge = 'local_bridge';

    public function label(): string
    {
        return match ($this) {
            self::ServerPull => 'سحب من الخادم (الاتصال الحالي)',
            self::AdmsPush => 'إرسال مباشر من الجهاز (ADMS / Push)',
            self::LocalBridge => 'برنامج ربط محلي داخل الشركة',
        };
    }

    public function requiresServerReachableAddress(): bool
    {
        return $this === self::ServerPull;
    }

    public function requiresPushSecret(): bool
    {
        return $this !== self::ServerPull;
    }
}
