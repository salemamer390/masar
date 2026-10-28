<?php

namespace App\Enums;

enum RawProcessingStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case Ignored = 'ignored';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد المعالجة',
            self::Processed => 'تمت المعالجة',
            self::Duplicate => 'مكرر',
            self::Ignored => 'متجاهل',
            self::Failed => 'فشل',
        };
    }
}
