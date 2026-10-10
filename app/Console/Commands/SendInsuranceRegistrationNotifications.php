<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\InsuranceRegistrationDeadlineNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendInsuranceRegistrationNotifications extends Command
{
    protected $signature = 'insurance:notify-registration';
    protected $description = 'Notify HR users before an employee insurance-registration deadline.';

    public function handle(): int
    {
        $graceDays = (int) (DB::table('insurance_organization_settings')->value('grace_days') ?? 30);
        $employees = Employee::whereNotNull('hire_date')->whereDoesntHave('insuranceRecord', fn ($q) => $q->where('status', 'مسجل'))->get();
        $users = User::with('role.permissions')->where('is_active', true)->get()->filter(fn (User $user) => $user->hasPermission('insurance.view'));
        $sent = 0;
        foreach ($employees as $employee) {
            $deadline = Carbon::parse($employee->hire_date)->addDays($graceDays)->startOfDay();
            $days = today()->diffInDays($deadline, false);
            if (!in_array($days, [7, 3, 0, -1], true)) continue;
            foreach ($users as $user) {
                $exists = $user->notifications()->where('type', InsuranceRegistrationDeadlineNotification::class)->where('data->employee_id', $employee->id)->where('data->days_remaining', $days)->exists();
                if (!$exists) { $user->notify(new InsuranceRegistrationDeadlineNotification($employee, $deadline, $days)); $sent++; }
            }
        }
        $this->info("Insurance registration notifications: {$sent}.");
        return self::SUCCESS;
    }
}
