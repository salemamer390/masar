<?php

namespace App\Console\Commands;

use App\Services\InsuranceDeregistrationService;
use Illuminate\Console\Command;

class SendInsuranceTerminationReminders extends Command
{
    protected $signature = 'insurance:notify-termination-removal';
    protected $description = 'Remind HR to remove employees who left from insurance using form 6 (within a week).';

    public function handle(InsuranceDeregistrationService $service): int
    {
        $sent = $service->notify();
        $this->info("Insurance form 6 reminders: {$sent}.");
        return self::SUCCESS;
    }
}
