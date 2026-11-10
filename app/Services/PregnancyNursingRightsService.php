<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Leave;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pregnancy and nursing rights (Labor Law 14/2025, arts. 54/56) wired into
 * attendance metrics and overtime requests.
 *
 * Dates come from two HR-entered, certificate-based places:
 * - the employee file (expected_delivery_date, pregnancy_reduction_start):
 *   recorded when the pregnancy certificate arrives, while she keeps working,
 *   before any leave exists. The guided maternity action copies them onto the
 *   leave and clears them from the employee file.
 * - the maternity leave (expected_delivery_date, pregnancy_reduction_start,
 *   birth_date): the expected date is the plan, the recorded birth is the
 *   fact. Nursing (two years) and the postpartum overtime ban read the birth.
 * A recorded expected date later than the latest recorded birth means a new
 * pregnancy, so the employee file governs again. An expected delivery date
 * alone never activates the paid reduction: only the certificate's recorded
 * reduction start does. No date is ever inferred.
 */
class PregnancyNursingRightsService
{
    private array $policyCache = [];

    private array $leaveCache = [];

    /** Latest effective maternity leave carrying any pregnancy date. */
    public function maternityLeave(Employee $employee): ?Leave
    {
        if (array_key_exists($employee->id, $this->leaveCache)) {
            return $this->leaveCache[$employee->id];
        }
        $typeIds = LeaveType::withoutGlobalScopes()->where('policy_code', 'maternity')->pluck('id');

        return $this->leaveCache[$employee->id] = Leave::withoutGlobalScopes()
            ->where('employee_id', $employee->id)
            ->where('company_id', $employee->company_id)
            ->whereIn('leave_type_id', $typeIds)
            ->effective()
            ->where(fn ($query) => $query->whereNotNull('birth_date')->orWhereNotNull('expected_delivery_date'))
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * The active pregnancy data source: the employee file when its expected
     * date postdates any recorded birth (a current or new pregnancy), else the
     * latest maternity leave. Returns [expected, reductionStart, birth], any
     * of which may be null except expected when a source exists.
     *
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?Carbon}
     */
    private function pregnancyDates(Employee $employee): array
    {
        $leave = $this->maternityLeave($employee);
        $empExpected = $employee->expected_delivery_date?->copy()->startOfDay();
        $leaveBirth = $leave?->birth_date?->copy()->startOfDay();
        if ($empExpected && (! $leaveBirth || $empExpected->gt($leaveBirth))) {
            return [$empExpected, $employee->pregnancy_reduction_start?->copy()->startOfDay(), null];
        }
        if ($leave) {
            return [
                $leave->expected_delivery_date?->copy()->startOfDay(),
                $leave->pregnancy_reduction_start?->copy()->startOfDay(),
                $leaveBirth,
            ];
        }

        return [null, null, null];
    }

    public function policy(string $key, string $default, ?int $companyId): string
    {
        $cacheKey = $key.':'.($companyId ?? 0);
        if (array_key_exists($cacheKey, $this->policyCache)) {
            return $this->policyCache[$cacheKey];
        }
        if ($companyId) {
            $value = DB::table('leave_policy_settings')->where('setting_key', $key)->where('company_id', $companyId)->value('setting_value');
            if ($value !== null) {
                return $this->policyCache[$cacheKey] = (string) $value;
            }
        }

        return $this->policyCache[$cacheKey] = (string) (DB::table('leave_policy_settings')->where('setting_key', $key)->whereNull('company_id')->value('setting_value') ?? $default);
    }

    /**
     * Nursing (art. 56): paid sessions per day for two years from the recorded
     * birth. Sessions x minutes come from the company's leave policy.
     */
    public function nursingAllowanceMinutes(Employee $employee, Carbon $date): int
    {
        $leave = $this->maternityLeave($employee);
        if (! $leave?->birth_date) {
            return 0;
        }
        $birth = $leave->birth_date->copy()->startOfDay();
        $day = $date->copy()->startOfDay();
        $companyId = $employee->company_id ? (int) $employee->company_id : null;
        $windowMonths = max(1, (int) $this->policy('nursing_window_months', '24', $companyId));
        if ($day->lt($birth) || $day->gt($birth->copy()->addMonthsNoOverflow($windowMonths))) {
            return 0;
        }
        $sessions = (int) $this->policy('nursing_sessions_per_day', '2', $companyId);
        $minutes = (int) $this->policy('nursing_minutes_per_session', '30', $companyId);

        return max(0, $sessions * $minutes);
    }

    /**
     * Pregnancy daily hour reduction (art. 54). This is a paid entitlement, so
     * it activates only from a medically grounded input: the reduction start
     * date HR records from the medical certificate (pregnancy_reduction_start).
     * An expected delivery date alone never activates it - computing a
     * pregnancy month from the expected date is an estimate, and an estimate
     * does not establish eligibility. The reduction runs until the recorded
     * birth, or until the expected date when no birth is recorded.
     */
    public function pregnancyReductionMinutes(Employee $employee, Carbon $date): int
    {
        [$expected, $reductionStart, $birth] = $this->pregnancyDates($employee);
        if (! $expected || ! $reductionStart) {
            return 0;
        }
        $companyId = $employee->company_id ? (int) $employee->company_id : null;
        $hours = (float) $this->policy('pregnancy_reduced_hours_per_day', '1', $companyId);
        if ($hours <= 0) {
            return 0;
        }
        $day = $date->copy()->startOfDay();
        $until = $birth ?? $expected;
        if ($day->lt($reductionStart) || $day->gt($until)) {
            return 0;
        }

        return (int) round($hours * 60);
    }

    /**
     * Statutory daily allowance against recorded lateness / early departure.
     * Pregnancy reduction and nursing never stack: a day is either before
     * delivery (reduction) or after the recorded birth (nursing).
     */
    public function dailyAllowanceMinutes(Employee $employee, Carbon $date): int
    {
        return max(
            $this->nursingAllowanceMinutes($employee, $date),
            $this->pregnancyReductionMinutes($employee, $date)
        );
    }

    /**
     * Why an overtime request must be rejected on this date, or null when the
     * overtime ban (art. 54) does not apply. The pregnancy ban runs while the
     * delivery is still ahead; the postpartum ban runs for the configured
     * months from the recorded birth.
     */
    public function overtimeBlockReason(Employee $employee, Carbon $date): ?string
    {
        $leave = $this->maternityLeave($employee);
        [$expected, , $birth] = $this->pregnancyDates($employee);
        if (! $leave && ! $expected) {
            return null;
        }
        $companyId = $employee->company_id ? (int) $employee->company_id : null;
        $day = $date->copy()->startOfDay();

        if ($expected && ($birth ? $day->lt($birth) : $day->lte($expected))) {
            return 'لا يمكن تسجيل عمل إضافي للموظفة أثناء الحمل (م54 من قانون العمل 14 لسنة 2025).';
        }
        // The postpartum ban always reads the recorded birth on the leave,
        // independently of the pregnancy source: a new recorded pregnancy does
        // not cancel the protection still running from a recent birth.
        $birthForProtection = $leave?->birth_date?->copy()->startOfDay();
        if ($birthForProtection) {
            $months = (int) $this->policy('pregnancy_overtime_protection_months', '6', $companyId);
            $protectionEnd = $birthForProtection->copy()->addMonthsNoOverflow($months);
            if ($months > 0 && $day->gte($birthForProtection) && $day->lt($protectionEnd)) {
                return "لا يمكن تسجيل عمل إضافي خلال {$months} أشهر بعد الوضع (م54). تنتهي الحماية في {$protectionEnd->format('d/m/Y')}.";
            }
        }

        return null;
    }
}
