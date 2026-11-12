<?php

namespace App\Enums;

enum FingerprintSyncType: string
{
    case ConnectionTest = 'connection_test';
    case DeviceInfo = 'device_info';
    case PlaceholderSync = 'placeholder_sync';
    case AttendancePull = 'attendance_pull';
    case AdmsPush = 'adms_push';
    case BridgePush = 'bridge_push';

    public function label(): string
    {
        return match ($this) {
            self::ConnectionTest => 'اختبار الاتصال',
            self::DeviceInfo => 'قراءة معلومات الجهاز',
            self::PlaceholderSync => 'مزامنة مستقبلية',
            self::AttendancePull => 'سحب بصمات الحضور',
            self::AdmsPush => 'بصمات مرسلة من الجهاز (ADMS)',
            self::BridgePush => 'بصمات عبر برنامج الربط المحلي',
        };
    }
}
