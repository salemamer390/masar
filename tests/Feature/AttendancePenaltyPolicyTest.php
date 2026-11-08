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

    private function enable(?array $tiers = null, string $from = '2026-10-01', bool $both = true, array $replacing = []): void
    {
        app(AttendancePenaltyPolicyService::class)->save($this->company->id, true, $from, $tiers ?? $this->tiers(), $both, $replacing);
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
        $this->assertEqualsWithDelta((float) $baseline->net_salary - 640.0, (float) $withPolicy->net_salary, 0.01);
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

    public function test_a_manual_penalty_on_the_same_date_is_not_doubled(): void
    {
        $employee = $this->employee($this->company, 'P4');
        $this->day($employee, '2026-10-05', 45);
        $this->day($employee, '2026-10-06', 45);
        $type = PenaltyType::create(['name' => 'manual', 'is_active' => true, 'default_deduction_days' => 1, 'company_id' => $this->company->id]);
        Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => '2026-10-05', 'title' => 'manual', 'deduction_days' => 1]);
        $this->enable(null, '2026-10-01', true, [$type->id]);
        $record = $this->record($employee);
        // 1 manual day + 1 policy day (only the 6th); the 5th is already penalised manually.
        $this->assertEqualsWithDelta(2.0, (float) $record->penalty_days, 0.001);
        $this->assertCount(1, data_get($record->calculation_snapshot, 'inputs.attendance_penalty_details'));
    }

    public function test_a_manual_penalty_of_an_unlisted_type_does_not_block_the_automatic_one(): void
    {
        $employee = $this->employee($this->company, 'P4b');
        $this->day($employee, '2026-10-05', 45);
        $type = PenaltyType::create(['name' => 'other', 'is_active' => true, 'default_deduction_days' => 1, 'company_id' => $this->company->id]);
        Penalty::create(['employee_id' => $employee->id, 'penalty_type_id' => $type->id, 'penalty_date' => '2026-10-05', 'title' => 'x', 'deduction_days' => 1]);
        $this->enable();
        $this->assertEqualsWithDelta(2.0, (float) $this->record($employee)->penalty_days, 0.001);
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

    public function test_same_day_late_and_early_are_both_charged_by_default(): void
    {
        $employee = $this->employee($this->company, 'P7');
        $this->day($employee, '2026-10-05', 45, 60);
        $this->enable(null, '2026-10-01', true);
        $this->assertEqualsWithDelta(1.5, (float) $this->record($employee)->penalty_days, 0.001);
    }

    public function test_same_day_larger_only_option(): void
    {
        $employee = $this->employee($this->company, 'P8');
        $this->day($employee, '2026-10-05', 45, 60);
        $this->enable(null, '2026-10-01', false);
        $this->assertEqualsWithDelta(1.0, (float) $this->record($employee)->penalty_days, 0.001);
    }

    public function test_suggested_tiers_are_created_disabled_and_never_overwrite_a_policy(): void
    {
        $service = app(AttendancePenaltyPolicyService::class);
        $service->ensureSuggested($this->company->id);
        $policy = $service->forCompany($this->company->id);
        $this->assertFalse($policy['enabled']);
        $this->assertCount(20, $policy['tiers']);
        $this->assertSame([1, 16, 31, 61, 121], array_values(array_unique(array_column(array_filter($policy['tiers'], fn ($t) => $t['kind'] === 'late'), 'from_minutes'))));
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
        $this->assertEqualsWithDelta((float) $before->net_salary - 320.0, (float) $after->net_salary, 0.01);
        $this->get(route('payroll.settings.index'))->assertOk()->assertSee('لائحة التأخير والانصراف المبكر');
    }

    public function test_settings_save_rejects_enabling_without_tiers_and_needs_the_permission(): void
    {
        $this->put(route('payroll.settings.attendance-penalties.update'), ['is_enabled' => 1, 'effective_from' => '2026-10-01'])
            ->assertSessionHasErrors('tiers');

        $role = Role::create(['name' => 'plain_staff', 'display_name' => 'Staff', 'company_id' => $this->company->id]);
        $staff = User::create(['name' => 'S', 'email' => 'staff-pen@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($staff)->putJson(route('payroll.settings.attendance-penalties.update'), ['is_enabled' => 0])->assertForbidden();
    }
}
