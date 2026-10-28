<?php

namespace App\Enums;

enum FingerprintSyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد الانتظار',
            self::Running => 'قيد التشغيل',
            self::Success => 'ناجح',
            self::Failed => 'فشل',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Running => 'info',
            self::Success => 'success',
            self::Failed => 'danger',
        };
    }
}
