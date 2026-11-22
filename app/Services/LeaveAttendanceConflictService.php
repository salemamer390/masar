<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\User;
use App\Notifications\LeaveAttendanceConflictNotification;
use Carbon\Carbon;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Finds leave days that also have an attendance check-in and notifies the
 * users who can view leaves. Reminders repeat every REMIND_AFTER_HOURS until a
 * user confirms. Confirming only acknowledges: attendance, leaves and payroll
 * are never touched here.
 */
class LeaveAttendanceConflictService
{
    public const REMIND_AFTER_HOURS = 24;

    public static function key(int $leaveId, string $date): string
    {
        return "leave_attendance_conflict:{$leaveId}:{$date}";
    }

    /** @return array<int, array{leave: Leave, employee: Employee, date: Carbon}> */
    public function conflicts(?int $leaveId = null): array
    {
        $leaves = Leave::withoutGlobalScopes()->effective()->with('employee')
            ->whereDate('start_date', '<=', today())
            ->when($leaveId, fn ($q) => $q->where('id', $leaveId))->get();
        $out = [];
        $windowStart = [];
        foreach ($leaves as $leave) {
            if (!$leave->employee) continue;
            // Only days that still matter: from the earliest open (Draft) payroll period of the
            // company, or the current attendance period when no draft is older.
            $cid = (int) $leave->employee->company_id;
            $windowStart[$cid] ??= $this->windowStart($cid);
            if (Carbon::parse($leave->end_date)->toDateString() < $windowStart[$cid]) continue;
            $from = max(Carbon::parse($leave->start_date)->toDateString(), $windowStart[$cid]);
            $to = min(Carbon::parse($leave->end_date)->toDateString(), today()->toDateString());
            $days = Attendance::withoutGlobalScopes()->where('employee_id', $leave->employee_id)
                ->whereBetween('attendance_date', [$from, $to])->whereNotNull('check_in')->pluck('attendance_date');
            foreach ($days as $day) {
                $out[] = ['leave' => $leave, 'employee' => $leave->employee, 'date' => Carbon::parse($day)];
            }
        }
        return $out;
    }

    private function windowStart(int $companyId): string
    {
        $start = \App\Support\CompanyContext::run($companyId, fn () => app(HrOperationalSettingsService::class)->periodContaining(today()->copy())['start']->toDateString());
        $draft = \App\Models\PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'Draft')->whereNotNull('period_start')->min('period_start');
        return $draft ? min($start, Carbon::parse($draft)->toDateString()) : $start;
    }

    /** Send new notices and reminders. Returns the number of notifications sent. */
    public function notify(?int $leaveId = null, ?int $companyId = null): int
    {
        $sent = 0;
        foreach ($this->conflicts($leaveId) as $c) {
            if ($companyId !== null && (int) $c['employee']->company_id !== $companyId) continue;
            $key = self::key($c['leave']->id, $c['date']->toDateString());
            if ($this->isConfirmed((int) $c['employee']->company_id, $key)) continue;
            foreach ($this->recipients((int) $c['employee']->company_id) as $user) {
                $latest = $this->forKey($user, $key)->latest()->first();
                if ($latest && $latest->created_at->gt(now()->subHours(self::REMIND_AFTER_HOURS)->addMinutes(10))) continue;
                if ($latest && !$latest->read_at) $latest->markAsRead();
                $user->notify(new LeaveAttendanceConflictNotification($c['employee'], $c['leave']->id, $c['date'], $key));
                $sent++;
            }
        }
        return $sent;
    }

    /** Acknowledge a conflict for the whole company: stops further reminders. */
    public function confirm(User $actor, DatabaseNotification $notification): void
    {
        $key = (string) ($notification->data['dedupe_key'] ?? '');
        abort_unless($key !== '' && ($notification->data['kind'] ?? '') === 'leave_attendance_conflict', 404);
        $stamp = ['confirmed_at' => now()->toIso8601String(), 'confirmed_by' => $actor->id, 'confirmed_by_name' => $actor->name];
        foreach ($this->recipients((int) $actor->company_id) as $user) {
            foreach ($this->forKey($user, $key)->get() as $item) {
                $item->forceFill(['data' => array_merge($item->data, $stamp), 'read_at' => $item->read_at ?? now()])->save();
            }
        }
    }

    private function isConfirmed(int $companyId, string $key): bool
    {
        foreach ($this->recipients($companyId) as $user) {
            if ($this->forKey($user, $key)->whereNotNull('data->confirmed_at')->exists()) return true;
        }
        return false;
    }

    private function forKey(User $user, string $key)
    {
        return $user->notifications()->where('type', LeaveAttendanceConflictNotification::class)->where('data->dedupe_key', $key);
    }

    private function recipients(int $companyId)
    {
        return User::withoutGlobalScopes()->with('role.permissions')->where('is_active', true)
            ->where('company_id', $companyId)->get()->filter(fn (User $u) => $u->hasPermission('leaves.view'));
    }
}
