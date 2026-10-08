<?php

namespace App\Console\Commands;

use App\Services\EmployeeLeaveStatusService;
use Illuminate\Console\Command;

class SyncEmployeeLeaveStatuses extends Command
{
    protected $signature = 'tazamun:sync-leave-statuses';
    protected $description = 'Apply and restore employee Leave statuses from approved leave dates.';

    public function handle(EmployeeLeaveStatusService $leaveStatus): int
    {
        $result = $leaveStatus->sync();
        $this->info("Leave statuses synced: {$result['placed_on_leave']} placed on leave, {$result['restored']} restored.");

        return self::SUCCESS;
    }
}
