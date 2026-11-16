<?php

namespace App\Services\Imports;

/** Attendance header meanings shared by both import entry points, not parser rules. */
final class AttendanceHeaderDictionary
{
    public const ALIASES = [
        'employee_code' => ['كود الموظف', 'كود', 'الكود', 'رقم الموظف', 'الرقم الوظيفي', 'employee code', 'employee_code', 'emp code', 'code', 'رقم العامل', 'كود العامل', 'رقم البصمة', 'كود البصمة', 'كود الموظف في الجهاز', 'user id', 'userid', 'emp id', 'employee id', 'employee no', 'emp no', 'badge', 'badge no', 'الرقم'],
        'employee_name' => ['الموظفين', 'الموظفون', 'الاسماء', 'اسم الموظف', 'الموظف', 'الاسم', 'employee name', 'employee_name', 'name', 'اسم العامل', 'اسم الموظف بالكامل', 'الاسم بالكامل', 'full name', 'staff name', 'worker name'],
        'date' => ['التاريخ', 'تاريخ الحضور', 'تاريخ يوم العمل', 'تاريخ العمل', 'date', 'attendance date', 'attendance_date', 'work date', 'تاريخ اليوم', 'تاريخ الدوام', 'يوم العمل', 'تاريخ الحضور والانصراف', 'day date', 'attendance day', 'اليوم', 'يوم الحضور', 'تاريخ يوم الحضور'],
        'punch_at' => ['التاريخ والوقت', 'وقت البصمة', 'التوقيت', 'datetime', 'date time', 'punch time', 'timestamp', 'تاريخ ووقت البصمة', 'التاريخ والتوقيت', 'التاريخ / الوقت', 'date/time', 'date & time', 'date and time', 'punch date time', 'check time', 'clock time'],
        'check_in' => ['الحضور', 'وقت الحضور', 'أول بصمة', 'اول بصمة', 'بصمة الحضور', 'c/in', 'check in', 'check_in', 'first punch', 'in', 'دخول', 'الدخول', 'وقت الدخول', 'بصمة دخول', 'حضور', 'time in', 'clock in', 'in time', 'first in', 'check-in', 'checkin', 'arrival', 'ميعاد الحضور', 'أول بصمه', 'اول بصمه', 'ساعة الحضور', 'تسجيل الدخول', 'بصمة الدخول', 'entry time', 'punch in'],
        'check_out' => ['الانصراف', 'وقت الانصراف', 'آخر بصمة', 'اخر بصمة', 'بصمة الانصراف', 'c/out', 'check out', 'check_out', 'last punch', 'out', 'خروج', 'الخروج', 'وقت الخروج', 'بصمة خروج', 'انصراف', 'time out', 'clock out', 'out time', 'last out', 'check-out', 'checkout', 'departure', 'ميعاد الانصراف', 'أخر بصمه', 'آخر بصمه', 'اخر بصمه', 'ساعة الانصراف', 'تسجيل الخروج', 'بصمة الخروج', 'exit time', 'punch out'],
    ];

    /** «اليوم» can mean weekday alongside a real date column. Keep it as
     * a fallback, but never let it hide a clearer date header in either UI.
     */
    public static function forHeaders(array $headers): array
    {
        $normalizer = app(ImportValueNormalizer::class);
        $day = $normalizer->key('اليوم');
        $dates = array_map(fn ($alias) => $normalizer->key($alias), self::ALIASES['date']);
        $hasSpecificDate = false;
        foreach ($headers as $header) {
            $key = $normalizer->key((string) $header);
            if ($key !== $day && in_array($key, $dates, true)) { $hasSpecificDate = true; break; }
        }
        $aliases = self::ALIASES;
        if ($hasSpecificDate) {
            $aliases['date'] = array_values(array_filter($aliases['date'], fn ($alias) => $normalizer->key($alias) !== $day));
        }
        return $aliases;
    }
}
