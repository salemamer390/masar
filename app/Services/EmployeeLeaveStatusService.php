<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class EmployeeLeaveStatusService
{
    /**
     * Keep the employee employment status aligned with approved leave periods.
     * A previous status is retained so a manual status change is never overwritten.
     *
     * @return array{placed_on_leave: int, restored: int}
     */
    public function sync(?Carbon $date = null): array
    {
        $date ??= today();
        $activeStatusId = $this->statusId(['Active', 'نشط']);
        $leaveStatusId = $this->statusId(['Leave', 'إجازة']);

        if (! $activeStatusId || ! $leaveStatusId) {
            return ['placed_on_leave' => 0, 'restored' => 0];
        }

        $employeeIdsOnLeave = Leave::query()
            ->effective()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->unique();

        $placedOnLeave = 0;
        Employee::query()->whereIn('id', $employeeIdsOnLeave)->each(function (Employee $employee) use ($activeStatusId, $leaveStatusId, &$placedOnLeave): void {
            // Only an active employee is automatically put on leave. Other
            // employment states (suspended, terminated, etc.) remain intact.
            if ((int) $employee->status_id === (int) $activeStatusId && $employee->pre_leave_status_id === null) {
                $employee->update([
                    'pre_leave_status_id' => $activeStatusId,
                    'status_id' => $leaveStatusId,
                ]);
                $placedOnLeave++;
            }
        });

        $restored = 0;
        Employee::query()->whereNotNull('pre_leave_status_id')->each(function (Employee $employee) use ($employeeIdsOnLeave, $leaveStatusId, $activeStatusId, &$restored): void {
            if ($employeeIdsOnLeave->contains($employee->id)) {
                // A manual status edit while the employee is on leave wins over automation.
                if ((int) $employee->status_id !== (int) $leaveStatusId) {
                    $employee->update(['pre_leave_status_id' => null]);
                }
                return;
            }

            if ((int) $employee->status_id === (int) $leaveStatusId) {
                $employee->update([
                    'status_id' => $employee->pre_leave_status_id ?: $activeStatusId,
                    'pre_leave_status_id' => null,
                ]);
                $restored++;
            } else {
                $employee->update(['pre_leave_status_id' => null]);
            }
        });

        return ['placed_on_leave' => $placedOnLeave, 'restored' => $restored];
    }

    private function statusId(array $names): ?int
    {
        $id = EmployeeStatus::query()->whereIn('name', $names)->value('id');

        return $id === null ? null : (int) $id;
    }
}
