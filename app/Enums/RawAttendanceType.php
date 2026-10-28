<?php

namespace App\Enums;

enum RawAttendanceType: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'حضور',
            self::CheckOut => 'انصراف',
        };
    }
}
