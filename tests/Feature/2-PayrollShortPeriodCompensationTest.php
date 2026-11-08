<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Role, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A full company payroll period shorter than 30 days pays the full fixed salary. */
class PayrollShortPeriodCompensationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2027-04-02 11:00:00', 'Africa/Cairo'));
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        EmployeeStatus::firstOrCreate(['name' => 'منتهي الخدمة']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'short-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'short-co', 'slug' => 'short-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        DB::table('hr_operational_settings')->insert([
            ['company_id' => $this->company->id, 'setting_key' => 'payroll_period_start_day', 'setting_value' => '26', 'label' => 't', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->company->id, 'setting_key' => 'payroll_period_end_day', 'setting_value' => '25', 'label' => 't', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->actingAs(User::create(['name' => 'M', 'email' => 'short@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]));
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function employee(string $code, string $hire, ?string $termination = null): Employee
    {
        $status = EmployeeStatus::where('name', $termination ? 'منتهي الخدمة' : 'Active')->first();
        return Employee::create([
            'employee_code' => $code, 'first_name' => $code, 'full_name' => $code, 'company_id' => $this->company->id,
            'status_id' => $status->id, 'hire_date' => $hire, 'shift_start' => '09:00', 'shift_end' => '17:00',
            'termination_type' => $termination ? 'استقالة' : null, 'termination_date' => $termination,
        ]);
    }

    public function test_full_28_day_period_is_paid_in_full(): void
    {
        // Period 26 Feb - 25 Mar 2027 is 28 days long.
        $window = app(PayrollRunService::class)->serviceWindow($this->employee('S1', '2025-01-01'), Carbon::parse('2027-03-01'));
        $this->assertSame('2027-02-26', $window['start']->toDateString());
        $this->assertSame('2027-03-25', $window['end']->toDateString());
        $this->assertSame(30, $window['days']);
        $this->assertSame(1.0, (float) $window['factor']);
    }

    public function test_partial_service_in_the_short_period_still_counts_days(): void
    {
        $service = app(PayrollRunService::class);
        $hired = $service->serviceWindow($this->employee('S2', '2027-03-01'), Carbon::parse('2027-03-01'));
        $this->assertSame(25, $hired['days']);

        $left = $service->serviceWindow($this->employee('S3', '2025-01-01', '2027-03-10'), Carbon::parse('2027-03-01'));
        $this->assertSame(13, $left['days']);
    }

    public function test_period_in_progress_accrues_days_until_it_ends(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-10 11:00:00', 'Africa/Cairo'));
        $window = app(PayrollRunService::class)->serviceWindow($this->employee('S4', '2025-01-01'), Carbon::parse('2027-03-01'));
        $this->assertSame(13, $window['days']);
    }

    public function test_full_short_period_pays_full_fixed_salary_and_still_deducts_absence(): void
    {
        $employee = $this->employee('S5', '2025-01-01');
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 9000,
            'basic_salary' => 9000, 'allowances' => 0, 'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 9000, 'net_salary' => 9000, 'no_insurance' => true, 'status' => 'Active',
        ]);
        // Every day of the period is attended, so nothing is inferred as absence.
        for ($d = Carbon::parse('2027-02-26'); $d->lte(Carbon::parse('2027-03-25')); $d->addDay()) {
            if ($d->toDateString() === '2027-03-03') continue;
            \App\Models\Attendance::create(['employee_id' => $employee->id, 'attendance_date' => $d->toDateString(), 'check_in' => '09:00:00', 'check_out' => '17:00:00', 'status' => 'Present']);
        }
        \App\Models\Attendance::create(['employee_id' => $employee->id, 'attendance_date' => '2027-03-03', 'check_in' => '09:00:00', 'check_out' => '17:00:00', 'status' => 'Present']);
        $service = app(PayrollRunService::class);
        $clean = \App\Models\PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $service->generate(Carbon::parse('2027-03-01'), null, $this->company->id)->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(9000.0, (float) $clean->basic_salary, 0.01); // not 8400 (9000 x 28/30)
        $this->assertEqualsWithDelta(0.0, (float) $clean->absence_deduction, 0.001);

        \App\Models\Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2027-03-03')->update(['check_in' => null, 'check_out' => null, 'status' => 'Absent']);
        $withAbsence = \App\Models\PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $service->generate(Carbon::parse('2027-03-01'), null, $this->company->id)->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEqualsWithDelta(9000.0, (float) $withAbsence->basic_salary, 0.01);
        $this->assertGreaterThan(0.0, (float) $withAbsence->absence_deduction);
        $this->assertLessThan((float) $clean->net_salary, (float) $withAbsence->net_salary);
    }
}
