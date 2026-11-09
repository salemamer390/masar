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

        // «اجازة وضع» is a leave state too: it is never auto-overwritten while
        // the maternity leave runs, and it restores to the pre-leave status
        // once no effective leave covers the employee any more.
        $leaveLikeStatusIds = array_values(array_filter([$leaveStatusId, $this->statusId(['اجازة وضع'])]));
        $maternityStatusId = $this->statusId(['اجازة وضع']);
        $maternityEmployeeIds = $maternityStatusId
            ? Leave::query()->effective()
                ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)
                ->whereHas('leaveType', fn ($query) => $query->where('policy_code', 'maternity'))
                ->pluck('employee_id')->unique()
            : collect();

        $employeeIdsOnLeave = Leave::query()
            ->effective()
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->pluck('employee_id')
            ->unique();

        $placedOnLeave = 0;
        Employee::query()->whereIn('id', $employeeIdsOnLeave)->each(function (Employee $employee) use ($activeStatusId, $leaveStatusId, $maternityStatusId, $maternityEmployeeIds, &$placedOnLeave): void {
            // Only an active employee is automatically put on leave. Other
            // employment states (suspended, terminated, etc.) remain intact.
            // A covering maternity leave uses the dedicated «اجازة وضع» status.
            if ((int) $employee->status_id === (int) $activeStatusId && $employee->pre_leave_status_id === null) {
                $employee->update([
                    'pre_leave_status_id' => $activeStatusId,
                    'status_id' => $maternityStatusId && $maternityEmployeeIds->contains($employee->id) ? $maternityStatusId : $leaveStatusId,
                ]);
                $placedOnLeave++;
            }
        });

        $restored = 0;
        Employee::query()->whereNotNull('pre_leave_status_id')->each(function (Employee $employee) use ($employeeIdsOnLeave, $leaveLikeStatusIds, $activeStatusId, &$restored): void {
            if ($employeeIdsOnLeave->contains($employee->id)) {
                // A manual status edit while the employee is on leave wins over automation.
                if (! in_array((int) $employee->status_id, $leaveLikeStatusIds, true)) {
                    $employee->update(['pre_leave_status_id' => null]);
                }
                return;
            }

            if (in_array((int) $employee->status_id, $leaveLikeStatusIds, true)) {
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
