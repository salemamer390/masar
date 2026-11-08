<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Company-defined lateness / early-departure penalty policy.
 *
 * Each company owns its tiers: a minute range and a repetition (occurrence) range
 * map to a number of deduction days. Nothing is deducted until the company
 * enables the policy. The days produced here join the ordinary penalty days of the
 * payroll calculator, so the monthly penalty cap and the penalty basis apply once.
 *
 * Minutes are the engine's effective minutes (EmployeePeriodMetrics): lateness is counted
 * from the shift start and grace only decides whether the day counts as late at all;
 * minutes covered by an approved permission are removed.
 *
 * Repetition is counted per employee and kind across the whole employment spell, in date
 * order, not per payroll period. The spell starts at the hire/contract date, or at the
 * return date after a dismissal, resignation or contract end, and violations before the
 * policy's effective date are neither charged nor counted.
 */
class AttendancePenaltyPolicyService
{
    public const KINDS = ['late' => 'تأخير', 'early_leave' => 'خروج مبكر'];

    /** @return array{enabled: bool, effective_from: ?string, tiers: array<int, array<string, mixed>>} */
    public function forCompany(?int $companyId): array
    {
        $empty = ['enabled' => false, 'effective_from' => null, 'tiers' => [], 'charge_both_same_day' => true, 'manual_replaces_types' => []];
        if (!$companyId || !Schema::hasTable('attendance_penalty_policies')) return $empty;
        $policy = DB::table('attendance_penalty_policies')->where('company_id', $companyId)->first();
        if (!$policy) return $empty;
        $tiers = DB::table('attendance_penalty_tiers')->where('company_id', $companyId)
            ->orderBy('kind')->orderBy('from_minutes')->orderBy('occurrence_from')->get()
            ->map(fn ($t) => [
                'kind' => $t->kind, 'from_minutes' => (int) $t->from_minutes, 'to_minutes' => $t->to_minutes === null ? null : (int) $t->to_minutes,
                'occurrence_from' => (int) $t->occurrence_from, 'occurrence_to' => $t->occurrence_to === null ? null : (int) $t->occurrence_to,
                'deduction_days' => (float) $t->deduction_days,
            ])->all();

        return [
            'enabled' => (bool) $policy->is_enabled, 'effective_from' => $policy->effective_from, 'tiers' => $tiers,
            'charge_both_same_day' => (bool) ($policy->charge_both_same_day ?? true),
            'manual_replaces_types' => array_map('intval', (array) json_decode((string) ($policy->manual_replaces_types ?? '[]'), true)),
        ];
    }

    /**
     * Replace the company's policy. Tiers of one kind must not overlap on both
     * the minute range and the occurrence range, so every violation has one answer.
     */
    public function save(int $companyId, bool $enabled, ?string $effectiveFrom, array $tiers, bool $chargeBothSameDay = true, array $manualReplacesTypes = []): void
    {
        $tiers = array_values($tiers);
        if ($enabled && !$tiers) {
            throw ValidationException::withMessages(['tiers' => 'أضف شريحة واحدة على الأقل قبل تفعيل اللائحة.']);
        }
        if ($enabled && !$effectiveFrom) {
            throw ValidationException::withMessages(['effective_from' => 'حدد تاريخ بداية تطبيق اللائحة.']);
        }
        foreach ($tiers as $i => $a) {
            foreach ($tiers as $j => $b) {
                if ($j <= $i || $a['kind'] !== $b['kind']) continue;
                if ($this->overlaps($a['from_minutes'], $a['to_minutes'], $b['from_minutes'], $b['to_minutes'])
                    && $this->overlaps($a['occurrence_from'], $a['occurrence_to'], $b['occurrence_from'], $b['occurrence_to'])) {
                    throw ValidationException::withMessages(['tiers' => 'شريحتان من نفس النوع تتداخلان في الدقائق والتكرار معًا. عدّل الحدود حتى تنطبق شريحة واحدة فقط على كل مخالفة.']);
                }
            }
        }

        DB::transaction(function () use ($companyId, $enabled, $effectiveFrom, $tiers, $chargeBothSameDay, $manualReplacesTypes) {
            DB::table('attendance_penalty_policies')->updateOrInsert(
                ['company_id' => $companyId],
                ['is_enabled' => $enabled, 'effective_from' => $effectiveFrom, 'charge_both_same_day' => $chargeBothSameDay, 'manual_replaces_types' => json_encode(array_values(array_map('intval', $manualReplacesTypes))), 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('attendance_penalty_tiers')->where('company_id', $companyId)->delete();
            foreach ($tiers as $tier) {
                DB::table('attendance_penalty_tiers')->insert([
                    'company_id' => $companyId, 'kind' => $tier['kind'],
                    'from_minutes' => $tier['from_minutes'], 'to_minutes' => $tier['to_minutes'],
                    'occurrence_from' => $tier['occurrence_from'], 'occurrence_to' => $tier['occurrence_to'],
                    'deduction_days' => $tier['deduction_days'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Suggested starting tiers, shown to a company that has not defined a policy yet. They are a labelled
     * suggestion, not a legal default: the policy stays disabled and the company edits every number.
     * Minutes are contiguous (1-15, 16-30, 31-60, 61-120, 121-180); above 180 nothing is suggested.
     * Days per repetition band: occurrences 1-3, then 4 and above.
     */
    public static function suggestedTiers(): array
    {
        $bands = [[1, 15, .25, .5], [16, 30, .5, 1], [31, 60, .75, 1.5], [61, 120, 1, 2], [121, 180, 1.5, 3]];
        $tiers = [];
        foreach (array_keys(self::KINDS) as $kind) {
            foreach ($bands as [$from, $to, $first, $repeat]) {
                $tiers[] = ['kind' => $kind, 'from_minutes' => $from, 'to_minutes' => $to, 'occurrence_from' => 1, 'occurrence_to' => 3, 'deduction_days' => $first];
                $tiers[] = ['kind' => $kind, 'from_minutes' => $from, 'to_minutes' => $to, 'occurrence_from' => 4, 'occurrence_to' => null, 'deduction_days' => $repeat];
            }
        }
        return $tiers;
    }

    /** Create the company's disabled policy with suggested tiers once; never touches an existing policy. */
    public function ensureSuggested(int $companyId): void
    {
        if (!Schema::hasTable('attendance_penalty_policies')) return;
        if (DB::table('attendance_penalty_policies')->where('company_id', $companyId)->exists()) return;
        $this->save($companyId, false, null, self::suggestedTiers());
    }

    /** First date of the employee's current employment spell (hire, or return after leaving). */
    public function spellStart(Employee $employee): ?\Carbon\Carbon
    {
        $start = $employee->contract_start_date ?? $employee->hire_date ?? null;
        $start = $start ? \Carbon\Carbon::parse($start)->startOfDay() : null;
        if ($employee->return_date) {
            $return = \Carbon\Carbon::parse($employee->return_date)->startOfDay();
            if (!$start || $return->gt($start)) $start = $return;
        }
        return $start;
    }

    /**
     * Range of earlier days (before the payroll window) whose violations count as prior occurrences:
     * from the later of spell start and policy effective date, up to the day before the window.
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}|null
     */
    public function priorRange(Employee $employee, \Carbon\Carbon $windowFrom): ?array
    {
        $policy = $this->forCompany($employee->company_id ? (int) $employee->company_id : null);
        if (!$policy['enabled'] || !$policy['tiers']) return null;
        $from = $this->spellStart($employee);
        if ($policy['effective_from']) {
            $eff = \Carbon\Carbon::parse($policy['effective_from'])->startOfDay();
            if (!$from || $eff->gt($from)) $from = $eff;
        }
        if (!$from) return null;
        $to = $windowFrom->copy()->subDay();
        return $from->gt($to) ? null : [$from, $to];
    }

    /**
     * Penalty days for one employee over the period's classified days (EmployeePeriodMetrics::days).
     * $priorDays are earlier days of the same spell: they only advance the occurrence counters.
     * A date with a manual penalty of a type the company marked as "replaces automatic" is not charged
     * again (it still counts as an occurrence). Other manual penalties never block the automatic one.
     *
     * @param array<int, array<string, mixed>> $days
     * @param array<int, string> $replacingManualDates Y-m-d dates covered by a replacing manual penalty
     * @param array<int, array<string, mixed>> $priorDays
     * @return array{days: float, details: array<int, array<string, mixed>>}
     */
    public function evaluate(Employee $employee, array $days, array $replacingManualDates = [], array $priorDays = []): array
    {
        $result = ['days' => 0.0, 'details' => []];
        $policy = $this->forCompany($employee->company_id ? (int) $employee->company_id : null);
        if (!$policy['enabled'] || !$policy['tiers']) return $result;
        $manual = array_flip($replacingManualDates);
        $count = ['late' => 0, 'early_leave' => 0];
        $effective = $policy['effective_from'] ? substr((string) $policy['effective_from'], 0, 10) : null;
        $spell = $this->spellStart($employee)?->toDateString();

        $violations = function (array $dayList, bool $charge) use (&$count, &$result, $policy, $manual, $effective, $spell) {
            foreach ($dayList as $day) {
                if (!empty($day['state']) && in_array($day['state'], ['future', 'before_hire', 'after_termination'], true)) continue;
                $date = (string) $day['date'];
                if ($effective && $date < $effective) continue;
                if ($spell && $date < $spell) continue;
                $found = [];
                foreach (['late' => 'late_minutes', 'early_leave' => 'early_leave_minutes'] as $kind => $field) {
                    $minutes = (int) ($day[$field] ?? 0);
                    if ($minutes <= 0) continue;
                    $count[$kind]++;
                    $tier = $this->match($policy['tiers'], $kind, $minutes, $count[$kind]);
                    if ($tier && $tier['deduction_days'] > 0) $found[$kind] = [$minutes, $count[$kind], $tier['deduction_days']];
                }
                if (!$charge || isset($manual[$date])) continue;
                if (!$policy['charge_both_same_day'] && count($found) > 1) {
                    uasort($found, fn ($x, $y) => $y[2] <=> $x[2]);
                    $found = array_slice($found, 0, 1, true);
                }
                foreach ($found as $kind => [$minutes, $occurrence, $deduction]) {
                    $result['days'] += $deduction;
                    $result['details'][] = [
                        'date' => $date, 'kind' => $kind, 'minutes' => $minutes, 'occurrence' => $occurrence,
                        'deduction_days' => $deduction,
                        'reason' => self::KINDS[$kind].' '.$minutes.' دقيقة - المرة '.$occurrence.' خلال مدة العمل',
                    ];
                }
            }
        };
        $violations($priorDays, false);
        $violations($days, true);
        $result['days'] = round($result['days'], 2);

        return $result;
    }

    private function match(array $tiers, string $kind, int $minutes, int $occurrence): ?array
    {
        foreach ($tiers as $tier) {
            if ($tier['kind'] !== $kind) continue;
            if ($minutes < $tier['from_minutes'] || ($tier['to_minutes'] !== null && $minutes > $tier['to_minutes'])) continue;
            if ($occurrence < $tier['occurrence_from'] || ($tier['occurrence_to'] !== null && $occurrence > $tier['occurrence_to'])) continue;
            return $tier;
        }
        return null;
    }

    private function overlaps(int $aFrom, ?int $aTo, int $bFrom, ?int $bTo): bool
    {
        $aTo ??= PHP_INT_MAX;
        $bTo ??= PHP_INT_MAX;
        return $aFrom <= $bTo && $bFrom <= $aTo;
    }
}
