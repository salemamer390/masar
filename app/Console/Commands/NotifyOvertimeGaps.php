<?php

namespace App\Console\Commands;

use App\Services\OvertimeGapReminderService;
use Illuminate\Console\Command;

class NotifyOvertimeGaps extends Command
{
    protected $signature = 'overtime:notify-gaps';

    protected $description = 'Send and repeat reminders for overtime days no request covers';

    public function handle(OvertimeGapReminderService $reminders): int
    {
        $this->info('Sent '.$reminders->notify().' notification(s).');

        return self::SUCCESS;
    }
}
