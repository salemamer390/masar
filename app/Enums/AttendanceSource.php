<?php

namespace App\Enums;

enum AttendanceSource: string
{
    case Fingerprint = 'fingerprint';
    case Manual = 'manual';
    case Imported = 'imported';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'بصمة',
            self::Manual => 'يدوي',
            self::Imported => 'استيراد',
            self::Adjustment => 'تعديل',
        };
    }
}
