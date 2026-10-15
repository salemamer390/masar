<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\InsuranceTerminationRemovalNotification;
use Illuminate\Console\Command;

class SendInsuranceTerminationReminders extends Command
{
    protected $signature = 'insurance:notify-termination-removal';
    protected $description = 'Remind HR to remove terminated employees from insurance using form 6.';

    public function handle(): int
    {
        $employees = Employee::with(['status', 'insuranceRecord'])
            ->whereNotNull('termination_date')
            ->whereHas('status', fn ($status) => $status->whereIn('name', ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة', 'Resigned', 'Resignation', 'مستقيل', 'استقالة']))
            ->whereHas('insuranceRecord', fn ($record) => $record->whereIn('status', ['registered', 'مسجل'])
                ->where(function ($date) {
                    $date->whereNull('insurance_end_date')->orWhereDate('insurance_end_date', '>=', today());
                }))
            ->get();

        $sent = 0;
        foreach ($employees as $employee) {
            $recipients = User::with('role.permissions')
                ->where('is_active', true)
                ->where('company_id', $employee->company_id)
                ->get()
                ->filter(fn (User $user) => $user->hasPermission('insurance.view'));

            foreach ($recipients as $recipient) {
                $alreadySent = $recipient->notifications()
                    ->where('type', InsuranceTerminationRemovalNotification::class)
                    ->where('data->employee_id', $employee->id)
                    ->where('data->reminder_date', today()->toDateString())
                    ->exists();

                if (! $alreadySent) {
                    $recipient->notify(new InsuranceTerminationRemovalNotification($employee));
                    $sent++;
                }
            }
        }

        $this->info("Insurance termination-removal reminders: {$sent}.");
        return self::SUCCESS;
    }
}
