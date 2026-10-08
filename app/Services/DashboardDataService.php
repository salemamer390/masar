<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\PayrollRecord;
use App\Models\PayrollRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A single source of truth for dashboard aggregates and drill-down queries. */
class DashboardDataService
{
    public function __construct(
        private readonly HrOperationalSettingsService $settings,
        private readonly EmployeeProfileCompletionService $profileCompletion,
    ) {}

    public function employeesFor(User $user): Builder
    {
        $query = Employee::query();
        // Managers see their direct team; HR, payroll and administrators keep their existing company-wide scope.
        if ($user->role?->name === 'manager' && $user->employee_id) {
            $query->where('manager_employee_id', $user->employee_id);
        }
        return $query;
    }

    public function activeEmployeesFor(User $user, ?Carbon $date = null): Builder
    {
        $date ??= today();
        return $this->employeesFor($user)
            ->whereHas('status', fn (Builder $q) => $q->whereIn('name', ['Active', 'نشط']))
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $date));
    }

    /** Employees expected to have an attendance state today, including approved leave. */
    public function attendanceEmployeesFor(User $user, ?Carbon $date = null): Builder
    {
        $date ??= today();

        return $this->employeesFor($user)
            ->whereHas('status', fn (Builder $q) => $q->whereIn('name', ['Active', 'نشط', 'Leave', 'إجازة']))
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhereDate('termination_date', '>=', $date));
    }

    public function attendance(User $user, ?Carbon $date = null): array
    {
        $date ??= today();
        $holiday = DB::table('hr_holidays')->whereDate('holiday_date', $date)->exists();
        $employees = $this->attendanceEmployeesFor($user, $date)->get(['id', 'full_name', 'employee_code', 'shift_start', 'shift_end']);
        $records = Attendance::whereDate('attendance_date', $date)->whereIn('employee_id', $employees->pluck('id'))
            ->get()->keyBy('employee_id');
        $onLeave = Leave::where('status', 'Approved')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
            ->whereIn('employee_id', $employees->pluck('id'))->pluck('id', 'employee_id');
        $cutoffReached = now()->gte(Carbon::parse($date->toDateString().' '.$this->settings->absenceCutoff()));
        $states = collect();

        foreach ($employees as $employee) {
            if ($holiday || $this->isWeeklyRest($date)) continue;
            $record = $records->get($employee->id);
            // Presence has priority over leave; it means at least one actual clock event exists.
            if ($record && ($record->check_in || $record->check_out)) {
                $state = ((int) $record->late_minutes > 0 || in_array($record->status, ['Late', 'متأخر'], true)) ? 'late' : 'present';
            } elseif ($onLeave->has($employee->id)) {
                $state = 'leave';
            } else {
                $state = $cutoffReached ? 'absent' : 'not_recorded';
            }
            $states->push(['employee' => $employee, 'state' => $state, 'record' => $record]);
        }

        $counts = ['present' => 0, 'on_time' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'not_recorded' => 0];
        foreach ($states as $item) {
            $counts[$item['state']]++;
            // Late is a subset of present, but both labels are required for the dashboard.
            if ($item['state'] === 'late') $counts['present']++;
            if ($item['state'] === 'present') $counts['on_time']++;
        }
        $counts['scheduled'] = $states->count();
        return compact('counts', 'states', 'cutoffReached', 'holiday');
    }

    public function payroll(User $user, Carbon $month): array
    {
        $run = PayrollRun::whereDate('payroll_month', $month->copy()->startOfMonth())->latest('id')->first();
        if (!$run) return ['run' => null, 'records' => 0, 'net' => null, 'cost' => null];
        $totals = PayrollRecord::where('payroll_run_id', $run->id)
            ->whereIn('employee_id', $this->employeesFor($user)->select('id'))
            ->selectRaw('COUNT(*) records, COALESCE(SUM(net_salary),0) net, COALESCE(SUM(employee_cost),0) cost, COALESCE(SUM(employee_insurance),0) employee_insurance, COALESCE(SUM(employer_insurance),0) employer_insurance, COALESCE(SUM(income_tax),0) tax')->first();
        return ['run' => $run, 'records' => (int) $totals->records, 'net' => $totals->net, 'cost' => $totals->cost, 'insurance' => (float) $totals->employee_insurance + (float) $totals->employer_insurance, 'tax' => $totals->tax];
    }

    public function expiringDocuments(User $user, int $days = 30): Collection
    {
        // Only the newest expiry per employee/type is actionable; obsolete copies are ignored.
        $latest = Document::query()->selectRaw('employee_id, COALESCE(document_type_id, 0) type_key, MAX(expiry_date) expiry_date')
            ->whereNotNull('expiry_date')->groupBy('employee_id', DB::raw('COALESCE(document_type_id, 0)'));
        $documents = Document::with(['employee', 'type'])->joinSub($latest, 'latest_docs', function ($join) {
                $join->on('documents.employee_id', '=', 'latest_docs.employee_id')
                    ->on('documents.expiry_date', '=', 'latest_docs.expiry_date')
                    ->whereRaw('COALESCE(documents.document_type_id, 0) = latest_docs.type_key');
            })
            ->whereBetween('documents.expiry_date', [today(), today()->copy()->addDays($days)])
            ->whereIn('documents.employee_id', $this->employeesFor($user)->select('id'))
            ->orderBy('documents.expiry_date')->get(['documents.*'])
            ->map(fn (Document $document) => [
                'employee' => $document->employee,
                'label' => $document->type?->name ?? $document->document_name,
                'expiry_date' => $document->expiry_date,
                'kind' => 'document',
            ]);

        $contracts = $this->activeEmployeesFor($user)
            ->whereNotNull('contract_end_date')
            ->whereBetween('contract_end_date', [today(), today()->copy()->addDays($days)])
            ->get(['id', 'full_name', 'employee_code', 'contract_end_date'])
            ->map(fn (Employee $employee) => [
                'employee' => $employee,
                'label' => 'عقد العمل',
                'expiry_date' => $employee->contract_end_date,
                'kind' => 'contract',
            ]);

        return $documents->concat($contracts)->sortBy('expiry_date')->values();
    }

    public function documentCompleteness(User $user): array
    {
        $requiredDocuments = $this->profileCompletion->requiredDocumentTypes();
        $employees = $this->activeEmployeesFor($user)->with(['profileDetail', 'documents'])->get();
        $items = $employees->map(function (Employee $employee) use ($requiredDocuments): array {
            return ['employee' => $employee] + $this->profileCompletion->evaluate($employee, $requiredDocuments);
        });

        return [
            'required' => $requiredDocuments->count(),
            'complete' => $items->where('complete', true)->count(),
            'incomplete' => $items->where('complete', false)->count(),
            'employees' => $items,
        ];
    }

    public function isWeeklyRest(Carbon $date): bool
    {
        $pattern = str_pad($this->settings->value('weekly_rest_pattern', '0000100'), 7, '0');
        // The stored convention is Monday to Sunday.
        return ($pattern[$date->dayOfWeekIso - 1] ?? '0') === '1';
    }

    public function payrollStatus(?string $status): string
    {
        return match ($status) {
            'Draft' => 'مسودة', 'Review' => 'قيد المراجعة', 'Approved' => 'معتمدة', 'Locked' => 'مقفلة', 'Paid' => 'تم الصرف',
            null, '' => 'لا توجد دورة', default => $status,
        };
    }
}
