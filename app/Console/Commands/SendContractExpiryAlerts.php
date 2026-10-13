<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\ContractExpiryNotification;
use Illuminate\Console\Command;

class SendContractExpiryAlerts extends Command
{
    protected $signature = 'tazamun:send-contract-alerts {--days=30 : Maximum days before expiry}';
    protected $description = 'Create one database notification per responsible user for contracts expiring soon.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $employees = Employee::whereNotNull('contract_end_date')
            ->whereBetween('contract_end_date', [today(), today()->copy()->addDays($days)])
            ->get();
        $sent = 0;
        foreach ($employees as $employee) {
            $users = User::with('role.permissions')->where('is_active', true)
                ->where('company_id', $employee->company_id)->get()
                ->filter(fn (User $user) => $user->hasPermission('employees.view'));
            foreach ($users as $user) {
                $exists = $user->notifications()->where('type', ContractExpiryNotification::class)
                    ->where('data->employee_id', $employee->id)
                    ->whereDate('created_at', today())->exists();
                if (!$exists) { $user->notify(new ContractExpiryNotification($employee)); $sent++; }
            }
        }
        $this->info("Contract alerts: {$sent} notification(s) created for {$employees->count()} employee(s).");
        return self::SUCCESS;
    }
}
