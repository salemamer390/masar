<?php

namespace App\Console\Commands;

use App\Services\LeaveAttendanceConflictService;
use Illuminate\Console\Command;

class NotifyLeaveAttendanceConflicts extends Command
{
    protected $signature = 'leaves:notify-attendance-conflicts';
    protected $description = 'Notify (and remind until confirmed) about leaves that overlap a day with attendance.';

    public function handle(LeaveAttendanceConflictService $service): int
    {
        $this->info('Sent '.$service->notify().' notification(s).');
        return self::SUCCESS;
    }
}
