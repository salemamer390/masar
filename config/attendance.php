<?php

return [
    // Days after a payroll period ends during which its attendance can still be corrected.
    // The period is saved and closed at the start of the day after these full days.
    'archive_grace_days' => (int) env('ATTENDANCE_ARCHIVE_GRACE_DAYS', 3),
];
