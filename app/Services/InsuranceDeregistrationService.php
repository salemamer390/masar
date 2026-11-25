<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\InsuranceDeregistrationNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * An employee who leaves (resigned or terminated) must be removed from social
 * insurance with form 6 within a week. Until HR marks the record unregistered
 * the employee stays on the insurance page and reminders keep coming.
 */
class InsuranceDeregistrationService
{
    public const DEADLINE_DAYS = 7;

    public const TERMINAL_STATUSES = ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة', 'Resigned', 'Resignation', 'مستقيل', 'استقالة'];

    public const UNREGISTERED_STATUSES = ['unregistered', 'غير مسجل'];

    /** Days since leaving at which the reminder turns from a warning into a danger alert. */
    public const DANGER_FROM_DAY = 5;

    /** @return array{days:int,left:int,level:string,overdue:bool} */
    public static function info(Carbon|string $terminationDate, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $days = max(0, (int) Carbon::parse($terminationDate)->startOfDay()->diffInDays($today, false));
        $left = self::DEADLINE_DAYS - $days;

        return [
            'days' => $days,
            'left' => $left,
            'level' => $days >= self::DANGER_FROM_DAY ? 'danger' : 'warning',
            'overdue' => $left < 0,
        ];
    }

    /** One reminder per day while it is a warning; one every 6 hours once it is danger. */
    public static function reminderKey(string $level, ?Carbon $now = null): string
    {
        $now ??= Carbon::now();

        return $level === 'danger'
            ? $now->toDateString().'-'.intdiv((int) $now->format('G'), 6)
            : $now->toDateString();
    }

    /** Employees who left and whose insurance record is still not unregistered. */
    public function pending(?int $companyId = null): Collection
    {
        return Employee::withoutGlobalScopes()->with(['status', 'insuranceRecord'])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->whereNotNull('termination_date')
            ->whereHas('status', fn ($status) => $status->whereIn('name', self::TERMINAL_STATUSES))
            ->whereHas('insuranceRecord', fn ($record) => $record->whereNotIn('status', self::UNREGISTERED_STATUSES))
            ->get();
    }

    /** Create the due reminders for every user who can see insurance. Returns how many were created. */
    public function notify(?int $companyId = null, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $sent = 0;
        $recipientsByCompany = [];

        foreach ($this->pending($companyId) as $employee) {
            $info = self::info($employee->termination_date, $now);
            $key = self::reminderKey($info['level'], $now);
            $recipients = $recipientsByCompany[$employee->company_id] ??= User::withoutGlobalScopes()->with('role.permissions')
                ->where('is_active', true)
                ->where('company_id', $employee->company_id)
                ->get()
                ->filter(fn (User $user) => $user->hasPermission('insurance.view'));

            foreach ($recipients as $recipient) {
                $base = $recipient->notifications()
                    ->where('type', InsuranceDeregistrationNotification::class)
                    ->where('data->employee_id', $employee->id);
                if ((clone $base)->where('data->reminder_key', $key)->exists()) {
                    continue;
                }
                // Older reminders for this employee are superseded by the new one.
                (clone $base)->whereNull('read_at')->update(['read_at' => now()]);
                $recipient->notify(new InsuranceDeregistrationNotification($employee, $info, $key));
                $sent++;
            }
        }

        return $sent;
    }

    /** Stop the reminders for an employee (used when HR confirms the deregistration). */
    public function clear(Employee $employee): void
    {
        \Illuminate\Notifications\DatabaseNotification::query()
            ->where('type', InsuranceDeregistrationNotification::class)
            ->where('data->employee_id', $employee->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** The تم فصله button shows only on the newest reminder of an employee who is still registered. */
    public static function showsConfirmButton(\Illuminate\Notifications\DatabaseNotification $notification): bool
    {
        $data = $notification->data;
        if (($data['kind'] ?? '') !== 'insurance_deregistration' || empty($data['record_id'])) {
            return false;
        }
        $record = \App\Models\InsuranceRecord::withoutGlobalScopes()->find($data['record_id']);
        if (!$record || in_array($record->status, self::UNREGISTERED_STATUSES, true)) {
            return false;
        }
        $newest = \Illuminate\Notifications\DatabaseNotification::query()
            ->where('notifiable_type', $notification->notifiable_type)
            ->where('notifiable_id', $notification->notifiable_id)
            ->where('type', $notification->type)
            ->where('data->employee_id', $data['employee_id'] ?? null)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->value('id');

        return $newest === $notification->id;
    }
}
