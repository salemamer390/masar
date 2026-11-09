<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, PayrollRecord, Penalty, PenaltyType, Role, Salary, SubscriptionPlan, User};
use App\Services\AttendancePenaltyPolicyService;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Lateness / early-departure policy: configured tiers reach the payroll record and the net salary. */
class AttendancePenaltyPolicyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00:00', 'Africa/Cairo'));
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $this->company = $this->makeCompany('pen-a');
        $this->manager = $this->makeManager($this->company, 'pen-a');
        $this->actingAs($this->manager);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function makeCompany(string $slug): Company
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'pen-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        return $company;
    }

    private function makeManager(Company $company, string $slug): User
    {
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        return User::create(['name' => 'M', 'email' => $slug . '@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
    }

    private function employee(Company $company, string $code): Employee
    {
        $employee = Employee::create([
            'employee_code' => $code, 'first_name' => $code, 'full_name' => $code, 'company_id' => $company->id,
            'status_id' => EmployeeStatus::where('name', 'Active')->first()->id, 'hire_date' => '2025-01-01',
            'shift_start' => '09:00', 'shift_end' => '17:00',
        ]);
        Salary::create([
            'company_id' => $company->id, 'employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 12000,
            'basic_salary' => 12000, 'allowances' => 0, 'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 12000, 'net_salary' => 12000, 'no_insurance' => true, 'status' => 'Active',
        ]);
        return $employee;
    }

    private function day(Employee $employee, string $date, int $late = 0, int $early = 0): void
    {
        Attendance::create([
            'employee_id' => $employee->id, 'attendance_date' => $date, 'check_in' => '09:00:00', 'check_out' => '17:00:00',
            'status' => $late > 0 ? 'Late' : 'Present', 'late_minutes' => $late, 'early_leave_minutes' => $early,
        ]);
    }

    private function tiers(): array
    {
        return [
            ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 30, 'occurrence_from' => 1, 'occurrence_to' => 2, 'deduction_days' => 0.25],
            ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 30, 'occurrence_from' => 3, 'occurrence_to' => null, 'deduction_days' => 0.5],
            ['kind' => 'late', 'from_minutes' => 31, 'to_minutes' => null, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 1],
            ['kind' => 'early_leave', 'from_minutes' => 1, 'to_minutes' => null, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 0.5],
        ];
    }

    private function enable(?array $tiers = null, string $from = '2026-10-01'): void
    {
        app(AttendancePenaltyPolicyService::class)->save($this->company->id, true, $from, $tiers ?? $this->tiers());
    }

    private function enableSuggested(string $from = '2026-10-01'): void
    {
        app(AttendancePenaltyPolicyService::class)->save($this->company->id, true, $from, AttendancePenaltyPolicyService::suggestedTiers());
    }

    private function record(Employee $employee): PayrollRecord
    {
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-10-01'), null, $this->company->id);
        return PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
    }

    public function test_no_deduction_while_the_policy_is_not_enabled(): void
    {
        $employee = $this->employee($this->company, 'P1');
        $this->day($employee, '2026-10-05', 45);
        $record = $this->record($employee);
        $this->assertEqualsWithDelta(0.0, (float) $record->penalty_deduction, 0.001);
    }

    public function test_tiers_and_repetition_reach_the_payroll_net(): void
    {
        $employee = $this->employee($this->company, 'P2');
        foreach ([['2026-10-05', 20], ['2026-10-06', 10], ['2026-10-07', 20], ['2026-10-08', 45]] as [$d, $m]) $this->day($employee, $d, $m);
        $baseline = $this->record($employee);

        $this->enable();
        $withPolicy = $this->record($employee);

        // 0.25 + 0.25 (first two short lates) + 0.5 (third short late) + 1 (45 min) = 2 days; the engine basic of a 12000 gross is 9600, so a day is 320.
        $this->assertEqualsWithDelta(2.0, (float) $withPolicy->penalty_days, 0.001);
        $this->assertEqualsWithDelta(640.0, (float) $withPolicy->penalty_deduction, 0.01);
        // Lost-time wage is always deducted too: 95 minutes x 12000 gross / 14400 = 79.17.
        $this->assertEqualsWithDelta(79.17, (float) $withPolicy->attendance_time_deduction, 0.01);
        $this->assertEqualsWithDelta((float) $baseline->net_salary - 719.17, (float) $withPolicy->net_salary, 0.01);
        $details = data_get($withPolicy->calculation_snapshot, 'inputs.attendance_penalty_details');
        $this->assertCount(4, $details);
        $this->assertSame(3, $details[2]['occurrence']);
        $this->assertStringContainsString('تأخير 45 دقيقة', $details[3]['reason']);
    }

    public function test_early_departure_is_penalised_by_its_own_tiers(): void
    {
        $employee = $this->employee($this->company, 'P3');
        $this->day($employee, '2026-10-05', 0, 60);
        $this->enable();
        $this->assertEqualsWithDelta(0.5, (float) $this->record($employee)->penalty_days, 0.001);
    }

    public function test_a_manual_penalty_on_the_same_date_stays_separate_from_the_automatic_one(): void
    {
        $employee = $this->employee($this->company, 'P4');
        $this->day($employee, '2026-10-05', 45);
        $this->day($employee, '2026-10-06', 45);
        $type = PenaltyType::create(['name' => 'manual', 'is_active' => true, 'default_deduction_days' => 1, 'company_id' => $this->company->id]);
        Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => '2026-10-05', 'title' => 'manual', 'deduction_days' => 1]);
        $this->enable();
        $record = $this->record($employee);
        // Manual and attendance penalties are independent: 1 manual day + 2 automatic policy days
        // (the 5th and the 6th), the manual one never replaces the automatic one.
        $this->assertEqualsWithDelta(3.0, (float) $record->penalty_days, 0.001);
        $this->assertCount(2, data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details'));
    }

    public function test_repetition_runs_across_payroll_periods_within_the_employment_spell(): void
    {
        $employee = $this->employee($this->company, 'P5');
        // Two short lates in September already used the first band (occurrences 1-2); October's is the 3rd.
        $this->day($employee, '2026-09-10', 10);
        $this->day($employee, '2026-09-20', 10);
        $this->day($employee, '2026-10-05', 10);
        $this->enable(null, '2026-09-01');
        $record = $this->record($employee);
        $this->assertEqualsWithDelta(0.5, (float) $record->penalty_days, 0.001);
        $this->assertSame(3, data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details.0.occurrence'));
    }

    public function test_repetition_restarts_after_the_employee_returns(): void
    {
        $employee = $this->employee($this->company, 'P6');
        $this->day($employee, '2026-09-10', 10);
        $this->day($employee, '2026-09-20', 10);
        $this->day($employee, '2026-10-05', 10);
        $employee->forceFill(['return_date' => '2026-10-01'])->save();
        $this->enable(null, '2026-09-01');
        $record = $this->record($employee);
        $this->assertEqualsWithDelta(0.25, (float) $record->penalty_days, 0.001);
        $this->assertSame(1, data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details.0.occurrence'));
    }

    public function test_same_day_late_and_early_are_always_both_charged(): void
    {
        $employee = $this->employee($this->company, 'P7');
        $this->day($employee, '2026-10-05', 45, 60);
        $this->enable();
        $record = $this->record($employee);
        // 1 day (45-min late) + 0.5 day (60-min early leave): always both, no larger-only option.
        $this->assertEqualsWithDelta(1.5, (float) $record->penalty_days, 0.001);
        $this->assertCount(2, data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details'));
    }

    public function test_suggested_tiers_are_created_disabled_and_never_overwrite_a_policy(): void
    {
        $service = app(AttendancePenaltyPolicyService::class);
        $service->ensureSuggested($this->company->id);
        $policy = $service->forCompany($this->company->id);
        $this->assertFalse($policy['enabled']);
        $this->assertCount(20, $policy['tiers']);
        $this->assertSame([1, 16, 31, 61], array_values(array_unique(array_column(array_filter($policy['tiers'], fn ($t) => $t['kind'] === 'late'), 'from_minutes'))));
        $this->assertTrue($policy['deduct_time_wage']);
        $this->assertSame(['days'], array_values(array_unique(array_column($policy['tiers'], 'unit'))));
        $service->save($this->company->id, false, null, [$this->tiers()[0]]);
        $service->ensureSuggested($this->company->id);
        $this->assertCount(1, $service->forCompany($this->company->id)['tiers']);
        $employee = $this->employee($this->company, 'P9');
        $this->day($employee, '2026-10-05', 45);
        $this->assertEqualsWithDelta(0.0, (float) $this->record($employee)->penalty_days, 0.001);
    }

    public function test_the_monthly_penalty_cap_still_applies_to_the_combined_days(): void
    {
        $employee = $this->employee($this->company, 'P5');
        foreach (range(5, 11) as $d) $this->day($employee, sprintf('2026-10-%02d', $d), 45);
        $this->enable();
        $record = $this->record($employee);
        $this->assertEqualsWithDelta(5.0, (float) $record->penalty_days, 0.001);
        $this->assertEqualsWithDelta(1600.0, (float) $record->penalty_deduction, 0.01);
    }

    public function test_violations_before_the_start_date_are_not_charged_or_counted(): void
    {
        $employee = $this->employee($this->company, 'P6');
        $this->day($employee, '2026-10-05', 20);
        $this->day($employee, '2026-10-20', 20);
        $this->enable(null, '2026-10-15');
        $record = $this->record($employee);
        $this->assertEqualsWithDelta(0.25, (float) $record->penalty_days, 0.001);
        $this->assertSame(1, data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details.0.occurrence'));
    }

    public function test_another_company_policy_never_applies(): void
    {
        $other = $this->makeCompany('pen-b');
        app(AttendancePenaltyPolicyService::class)->save($other->id, true, '2026-10-01', $this->tiers());
        $employee = $this->employee($this->company, 'P7');
        $this->day($employee, '2026-10-05', 45);
        $this->assertEqualsWithDelta(0.0, (float) $this->record($employee)->penalty_deduction, 0.001);
    }

    public function test_overlapping_tiers_are_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->enable([
            ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 30, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 0.25],
            ['kind' => 'late', 'from_minutes' => 20, 'to_minutes' => null, 'occurrence_from' => 2, 'occurrence_to' => null, 'deduction_days' => 0.5],
        ]);
    }

    public function test_saving_in_settings_rebuilds_the_draft_and_changes_the_salary(): void
    {
        $employee = $this->employee($this->company, 'P8');
        $this->day($employee, '2026-10-05', 45);
        $before = $this->record($employee);
        $this->assertEqualsWithDelta(0.0, (float) $before->penalty_deduction, 0.001);

        $this->put(route('payroll.settings.attendance-penalties.update'), [
            'is_enabled' => 1, 'effective_from' => '2026-10-01',
            'tiers' => [['kind' => 'late', 'from_minutes' => 31, 'to_minutes' => '', 'occurrence_from' => 1, 'occurrence_to' => '', 'deduction_days' => 1]],
        ])->assertRedirect(route('payroll.settings.index'));

        $after = PayrollRecord::withoutGlobalScopes()->findOrFail($before->id);
        $this->assertEqualsWithDelta(320.0, (float) $after->penalty_deduction, 0.01);
        // Plus the always-on lost-time wage: 45 minutes x 12000 gross / 14400 = 37.5.
        $this->assertEqualsWithDelta(37.5, (float) $after->attendance_time_deduction, 0.01);
        $this->assertEqualsWithDelta((float) $before->net_salary - 357.5, (float) $after->net_salary, 0.01);
        $this->get(route('payroll.settings.index'))->assertOk()->assertSee('لائحة الجزاءات');
    }


    public function test_settings_save_keeps_custom_occurrence_ranges_and_mixed_units_without_reshaping(): void
    {
        $this->put(route('payroll.settings.attendance-penalties.update'), [
            'is_enabled' => 1, 'effective_from' => '2026-10-01',
            'tiers' => [
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 1, 'occurrence_to' => 1, 'deduction_days' => 30, 'unit' => 'minutes'],
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 2, 'occurrence_to' => 2, 'deduction_days' => 0.5, 'unit' => 'days'],
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 3, 'occurrence_to' => '', 'deduction_days' => 1, 'unit' => 'days'],
            ],
        ])->assertRedirect(route('payroll.settings.index'));

        $tiers = app(AttendancePenaltyPolicyService::class)->forCompany($this->company->id)['tiers'];
        $this->assertCount(3, $tiers);
        $this->assertSame(['minutes', 'days', 'days'], array_column($tiers, 'unit'));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($tiers, 'occurrence_from')));
        $this->assertNull($tiers[2]['occurrence_to']);

        // The custom shape renders with explicit occurrence range fields (not forced into the 4-step matrix).
        $this->get(route('payroll.settings.index'))->assertOk()->assertSee('لائحة الجزاءات')->assertSee('من المرة');

        // Mixed units price each offence in its own unit: 1st = 30 minutes from the basic (9600/14400 x 30 = 20), 2nd = 0.5 day (9600/30 x 0.5 = 160).
        $employee = $this->employee($this->company, 'PX');
        $this->day($employee, '2026-10-05', 10);
        $this->day($employee, '2026-10-06', 10);
        $this->assertEqualsWithDelta(180.0, (float) $this->record($employee)->penalty_deduction, 0.01);

        // The 4-step shape renders in the matrix columns with the warning tag on zero amounts.
        $this->put(route('payroll.settings.attendance-penalties.update'), [
            'is_enabled' => 1, 'effective_from' => '2026-10-01',
            'tiers' => [
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 1, 'occurrence_to' => 1, 'deduction_days' => 0, 'unit' => 'days'],
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 2, 'occurrence_to' => 2, 'deduction_days' => 0.25, 'unit' => 'days'],
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 3, 'occurrence_to' => 3, 'deduction_days' => 0.5, 'unit' => 'days'],
                ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 4, 'occurrence_to' => '', 'deduction_days' => 1, 'unit' => 'days'],
            ],
        ])->assertRedirect(route('payroll.settings.index'));
        $this->get(route('payroll.settings.index'))->assertOk()->assertSee('الرابعة فأكثر')->assertSee('إنذار');
    }

    public function test_settings_save_rejects_enabling_without_tiers_and_needs_the_permission(): void
    {
        $this->put(route('payroll.settings.attendance-penalties.update'), ['is_enabled' => 1, 'effective_from' => '2026-10-01'])
            ->assertSessionHasErrors('tiers');

        $role = Role::create(['name' => 'plain_staff', 'display_name' => 'Staff', 'company_id' => $this->company->id]);
        $staff = User::create(['name' => 'S', 'email' => 'staff-pen@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($staff)->putJson(route('payroll.settings.attendance-penalties.update'), ['is_enabled' => 0])->assertForbidden();
    }

    public function test_a_zero_deduction_tier_is_a_recorded_warning_that_counts(): void
    {
        $employee = $this->employee($this->company, 'W1');
        $this->day($employee, '2026-10-05', 10);
        $this->day($employee, '2026-10-06', 10);
        $this->enableSuggested();
        $record = $this->record($employee);
        // First short late: warning only; second: 0.25 day from the basic (0.25 x 320).
        $this->assertEqualsWithDelta(0.25, (float) $record->penalty_days, 0.001);
        $this->assertEqualsWithDelta(80.0, (float) $record->penalty_deduction, 0.01);
        $details = data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details');
        $this->assertCount(2, $details);
        $this->assertTrue($details[0]['warning']);
        $this->assertStringContainsString('إنذار', $details[0]['reason']);
        $this->assertFalse($details[1]['warning']);
        $this->assertSame(1, $details[0]['occurrence']);
        $this->assertSame(2, $details[1]['occurrence']);
    }

    public function test_each_minute_band_escalates_on_its_own_counter(): void
    {
        $employee = $this->employee($this->company, 'W2');
        $this->day($employee, '2026-10-05', 10);
        $this->day($employee, '2026-10-06', 45);
        $this->day($employee, '2026-10-07', 10);
        $this->day($employee, '2026-10-08', 45);
        $this->enableSuggested();
        $record = $this->record($employee);
        // Band 1-15: warning + 0.25. Band 31-60: 0.25 + 0.5. Total 1 day.
        $this->assertEqualsWithDelta(1.0, (float) $record->penalty_days, 0.001);
        $details = data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details');
        $this->assertSame([1, 1, 2, 2], array_column($details, 'occurrence'));
    }

    public function test_the_fifth_occurrence_repeats_the_fourth_penalty(): void
    {
        $employee = $this->employee($this->company, 'W3');
        foreach (range(5, 9) as $d) $this->day($employee, sprintf('2026-10-%02d', $d), 10);
        $this->enableSuggested();
        $record = $this->record($employee);
        // 0 + 0.25 + 0.5 + 1 + 1 = 2.75 days from the basic.
        $this->assertEqualsWithDelta(2.75, (float) $record->penalty_days, 0.001);
        $this->assertEqualsWithDelta(880.0, (float) $record->penalty_deduction, 0.01);
        $details = data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details');
        $this->assertSame(5, $details[4]['occurrence']);
        $this->assertEqualsWithDelta(1.0, (float) $details[4]['deduction_days'], 0.001);
    }

    public function test_the_lost_time_wage_is_a_separate_line_priced_from_the_gross(): void
    {
        $employee = $this->employee($this->company, 'W4');
        $this->day($employee, '2026-10-05', 60);
        $baseline = $this->record($employee);

        $this->enableSuggested('2026-10-01');
        $withPolicy = $this->record($employee);

        // Disciplinary: band 31-60 first occurrence = 0.25 day from the basic (80).
        $this->assertEqualsWithDelta(80.0, (float) $withPolicy->penalty_deduction, 0.01);
        // Time wage: 60 minutes x 12000 gross / (240 x 60) = 50, stored on its own column.
        $this->assertEqualsWithDelta(50.0, (float) $withPolicy->attendance_time_deduction, 0.01);
        $this->assertEqualsWithDelta((float) $baseline->net_salary - 130.0, (float) $withPolicy->net_salary, 0.01);
        $this->assertSame(60, (int) data_get($withPolicy->calculation_snapshot, 'inputs.attendance_time_minutes'));
    }

    public function test_the_lost_time_wage_is_always_deducted_once_the_policy_is_enabled(): void
    {
        $employee = $this->employee($this->company, 'W5');
        $this->day($employee, '2026-10-05', 60);
        $baseline = $this->record($employee);
        $this->enableSuggested('2026-10-01');
        $withPolicy = $this->record($employee);
        // No opt-in any more: 60 minutes x 12000 gross / 14400 = 50 is always deducted.
        $this->assertEqualsWithDelta(50.0, (float) $withPolicy->attendance_time_deduction, 0.01);
        $this->assertEqualsWithDelta((float) $baseline->net_salary - 130.0, (float) $withPolicy->net_salary, 0.01);
    }

    public function test_legacy_saved_option_values_no_longer_change_the_fixed_behaviour(): void
    {
        // A policy row saved before the options were removed: larger-only, no time wage, and a
        // manual type marked as replacing the automatic penalty. All three are ignored now.
        $service = app(AttendancePenaltyPolicyService::class);
        $service->save($this->company->id, true, '2026-10-01', $this->tiers());
        DB::table('attendance_penalty_policies')->where('company_id', $this->company->id)
            ->update(['charge_both_same_day' => false, 'deduct_time_wage' => false, 'manual_replaces_types' => '[1]']);
        $policy = $service->forCompany($this->company->id);
        $this->assertTrue($policy['charge_both_same_day']);
        $this->assertTrue($policy['deduct_time_wage']);
        $this->assertSame([], $policy['manual_replaces_types']);

        $employee = $this->employee($this->company, 'W8');
        $this->day($employee, '2026-10-05', 45, 60);
        $type = PenaltyType::create(['name' => 'legacy manual', 'is_active' => true, 'default_deduction_days' => 1, 'company_id' => $this->company->id]);
        Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => '2026-10-05', 'title' => 'm', 'deduction_days' => 1]);
        $record = $this->record($employee);
        // Both violations charged (1 + 0.5) plus the independent manual day; time wage still deducted.
        $this->assertEqualsWithDelta(2.5, (float) $record->penalty_days, 0.001);
        $this->assertEqualsWithDelta(105.0 * 12000 / 14400, (float) $record->attendance_time_deduction, 0.01);

        // Saving the settings form normalises the stored row to the fixed values.
        $service->save($this->company->id, true, '2026-10-01', $this->tiers());
        $stored = DB::table('attendance_penalty_policies')->where('company_id', $this->company->id)->first();
        $this->assertSame(1, (int) $stored->charge_both_same_day);
        $this->assertSame(1, (int) $stored->deduct_time_wage);
        $this->assertSame('[]', (string) $stored->manual_replaces_types);
    }

    public function test_automatic_policy_days_are_priced_from_the_basic_even_on_a_gross_penalty_basis(): void
    {
        // The seeded basis row is global; claim it for this company with a Gross basis (rolled back after the test).
        DB::table('payroll_calculation_rules')->where('rule_group', 'penalty')->where('rule_key', 'calculation_basis')
            ->update(['company_id' => $this->company->id, 'rule_value' => 'Gross', 'updated_at' => now()]);
        $employee = $this->employee($this->company, 'W7');
        $this->day($employee, '2026-10-05', 45);
        $type = PenaltyType::create(['name' => 'gross-basis manual', 'is_active' => true, 'default_deduction_days' => 1, 'company_id' => $this->company->id]);
        Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => '2026-10-06', 'title' => 'manual', 'deduction_days' => 1]);
        $this->enable();
        $record = $this->record($employee);
        // Policy day: ALWAYS the basic (9600/30 = 320). Manual day: the configured Gross basis (12000/30 = 400).
        $this->assertEqualsWithDelta(720.0, (float) $record->penalty_deduction, 0.01);
        $this->assertEqualsWithDelta(320.0, (float) data_get($record->calculation_snapshot, 'attendance_penalty_deduction'), 0.01);
    }

    public function test_a_tier_can_penalize_in_minutes_priced_from_the_basic(): void
    {
        $employee = $this->employee($this->company, 'M1');
        $this->day($employee, '2026-10-05', 10);
        $baseline = $this->record($employee);
        $this->enable([['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 30, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 30, 'unit' => 'minutes']]);
        $record = $this->record($employee);
        // 30 penalty minutes from the basic: 30 x 9600 / 14400 = 20. Day-equivalent 30/480 = 0.0625.
        $this->assertEqualsWithDelta(20.0, (float) $record->penalty_deduction, 0.01);
        $this->assertEqualsWithDelta(0.06, (float) $record->penalty_days, 0.001); // 0.0625 rounded by the decimal(2) column
        // Plus the always-on lost-time wage: 10 minutes x 12000 gross / 14400 = 8.33.
        $this->assertEqualsWithDelta(8.33, (float) $record->attendance_time_deduction, 0.01);
        $this->assertEqualsWithDelta((float) $baseline->net_salary - 28.33, (float) $record->net_salary, 0.01);
        $details = data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details');
        $this->assertSame('minutes', $details[0]['unit']);
        $this->assertStringContainsString('جزاء 30 دقيقة', $details[0]['reason']);
    }

    public function test_mixed_unit_tiers_price_each_row_in_its_own_unit(): void
    {
        $employee = $this->employee($this->company, 'M2');
        $this->day($employee, '2026-10-05', 10);
        $this->day($employee, '2026-10-06', 30);
        $this->enable([
            ['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 15, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 0.5, 'unit' => 'days'],
            ['kind' => 'late', 'from_minutes' => 16, 'to_minutes' => 60, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 60, 'unit' => 'minutes'],
        ]);
        $record = $this->record($employee);
        // 0.5 day (160) + 60 minutes (60 x 9600/14400 = 40) = 200; days column 0.5 + 0.125.
        $this->assertEqualsWithDelta(200.0, (float) $record->penalty_deduction, 0.01);
        $this->assertEqualsWithDelta(0.63, (float) $record->penalty_days, 0.001); // 0.625 rounded by the decimal(2) column
    }

    public function test_minute_penalties_count_toward_the_monthly_cap_in_day_equivalents(): void
    {
        $employee = $this->employee($this->company, 'M3');
        $type = PenaltyType::create(['name' => 'cap-manual', 'is_active' => true, 'default_deduction_days' => 4, 'company_id' => $this->company->id]);
        Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => '2026-10-04', 'title' => 'm', 'deduction_days' => 4]);
        $this->day($employee, '2026-10-05', 60);
        $this->day($employee, '2026-10-06', 60);
        // Two 300-minute penalties = 600 minutes = 1.25 day-equivalents; with 4 manual days that is 5.25, capped at 5.
        $this->enable([['kind' => 'late', 'from_minutes' => 1, 'to_minutes' => 120, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 300, 'unit' => 'minutes']]);
        $record = $this->record($employee);
        $this->assertEqualsWithDelta(5.0, (float) $record->penalty_days, 0.001);
        $this->assertEqualsWithDelta(1600.0, (float) $record->penalty_deduction, 0.01);
    }

    public function test_the_lost_time_wage_is_not_limited_by_the_monthly_penalty_cap(): void
    {
        $employee = $this->employee($this->company, 'W6');
        foreach (range(5, 11) as $d) $this->day($employee, sprintf('2026-10-%02d', $d), 45);
        $baseline = $this->record($employee);
        $this->enableSuggested('2026-10-01');
        $record = $this->record($employee);
        // Disciplinary days: 0.25+0.5+1+2+2+2+2 = 9.75, capped at 5 days (1600).
        $this->assertEqualsWithDelta(5.0, (float) $record->penalty_days, 0.001);
        $this->assertEqualsWithDelta(1600.0, (float) $record->penalty_deduction, 0.01);
        // Time wage: 7 x 45 = 315 minutes x 12000/14400 = 262.5, in full.
        $this->assertEqualsWithDelta(262.5, (float) $record->attendance_time_deduction, 0.01);
        $this->assertEqualsWithDelta((float) $baseline->net_salary - 1862.5, (float) $record->net_salary, 0.01);
    }
}
