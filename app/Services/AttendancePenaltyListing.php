<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only list of the lateness / early-departure penalties the company policy produces from
 * attendance, so they can be seen next to manual penalties. It uses the same engine and inputs
 * as payroll (EmployeePeriodMetrics + AttendancePenaltyPolicyService::evaluate); nothing is stored.
 */
class AttendancePenaltyListing
{
    /** @param Collection<int, Employee> $employees */
    public function forPeriod(Collection $employees, Carbon $from, Carbon $to): array
    {
        $policies = app(AttendancePenaltyPolicyService::class);
        $metrics = app(EmployeePeriodMetrics::class);
        $enabled = [];
        $rows = [];
        foreach ($employees as $employee) {
            $companyId = $employee->company_id ? (int) $employee->company_id : 0;
            if (!isset($enabled[$companyId])) {
                $policy = $policies->forCompany($companyId ?: null);
                $enabled[$companyId] = $policy['enabled'] && $policy['tiers'] !== [];
            }
            if (!$enabled[$companyId]) continue;

            $prior = [];
            if ($range = $policies->priorRange($employee, $from)) {
                $prior = $metrics->days($employee, $range[0], $range[1], $this->attendance($employee, $range[0], $range[1]), $this->leaves($employee, $range[0], $range[1]), $this->excuses($employee, $range[0], $range[1]));
            }
            $days = $metrics->days($employee, $from, $to, $this->attendance($employee, $from, $to), $this->leaves($employee, $from, $to), $this->excuses($employee, $from, $to));
            $result = $policies->evaluate($employee, $days, $prior);
            foreach ($result['details'] as $detail) {
                $rows[] = ['employee' => $employee] + $detail;
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return ['enabled' => in_array(true, $enabled, true), 'rows' => $rows];
    }

    private function attendance(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return Attendance::effective()->where('employee_id', $employee->id)
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])->get();
    }

    private function leaves(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return Leave::with('leaveType')->where('employee_id', $employee->id)->effective()
            ->whereDate('start_date', '<=', $to->toDateString())->whereDate('end_date', '>=', $from->toDateString())->get();
    }

    /** Approved permissions and missions overlapping the range (they remove excused minutes). */
    private function excuses(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        return HrRequest::with('requestType')->where('employee_id', $employee->id)->effective()
            ->where(function ($query) {
                $query->whereIn('request_type', ['permission', 'mission'])
                    ->orWhereHas('requestType', fn ($type) => $type->whereIn('source_key', ['permission', 'mission']));
            })->get()->filter(function ($request) use ($from, $to) {
                $payload = is_array($request->payload) ? $request->payload : [];
                $date = $payload['start_date'] ?? $payload['request_date'] ?? $payload['date'] ?? $request->created_at?->toDateString();
                $end = $payload['end_date'] ?? $date;
                return $date && Carbon::parse($date)->lte($to) && Carbon::parse($end)->gte($from);
            })->values();
    }
}
