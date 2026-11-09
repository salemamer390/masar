<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, PayrollRecord, Role, Salary, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** End-to-end: a full-paid allowance in a partial month persists consistent payable, displayed and tax figures. */
class PayrollFullPaidAllowanceTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-30 10:00:00', 'Africa/Cairo'));
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'fpa-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'fpa-co', 'slug' => 'fpa-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'fpa-co@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($user);
        app(\App\Services\PayrollDefaultsService::class)->ensureForCompany($this->company->id);
        // Housing is configured full-paid for this company.
        DB::table('payroll_allowance_settings')->where('company_id', $this->company->id)->where('allowance_key', 'housing')->update(['is_prorated' => 0]);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function employee(string $code, string $name): Employee
    {
        // Hired 16 Nov: payable 16-30 Nov = 15/30 of the month.
        return Employee::create([
            'employee_code' => $code, 'first_name' => $name, 'full_name' => $name, 'company_id' => $this->company->id,
            'status_id' => EmployeeStatus::where('name', 'Active')->first()->id, 'hire_date' => '2026-11-16',
            'shift_start' => '09:00', 'shift_end' => '17:00',
        ]);
    }

    /** A valid contractual split: same gross 12000, same allowances total 3000, different proportions than the engine's 9600/840/720/480/360. */
    private function salary(Employee $employee, array $overrides = []): Salary
    {
        return Salary::create(array_merge([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'effective_from' => '2026-11-16', 'salary_type' => 'gross', 'salary_input' => 12000,
            'basic_salary' => 9000, 'allowances' => 3000,
            'housing_allowance' => 1200, 'transportation_allowance' => 1000, 'meal_allowance' => 500, 'clothing_allowance' => 300,
            'gross_salary' => 12000, 'net_salary' => 12000, 'income_tax' => 300, 'no_insurance' => true, 'status' => 'Active',
        ], $overrides));
    }

    /** Present 16-30 Nov except the listed absent dates, so attendance adds no deductions of its own. */
    private function seedAttendance(Employee $employee, array $absentDates = []): void
    {
        for ($day = 16; $day <= 30; $day++) {
            $date = "2026-11-{$day}";
            Attendance::create([
                'employee_id' => $employee->id, 'attendance_date' => $date,
                'check_in' => in_array($date, $absentDates, true) ? null : "{$date} 09:00:00",
                'check_out' => in_array($date, $absentDates, true) ? null : "{$date} 17:00:00",
                'status' => in_array($date, $absentDates, true) ? 'Absent' : 'Present',
                'source' => 'manual', 'approval_status' => 'approved',
            ]);
        }
    }

    private function generate(Employee $employee): PayrollRecord
    {
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);

        return PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
    }

    public function test_partial_month_persists_full_contractual_allowance_consistent_net_and_tax(): void
    {
        $employee = $this->employee('FPA1', 'Full Paid');
        $this->salary($employee);
        $this->seedAttendance($employee);
        $record = $this->generate($employee);

        // Displayed pieces: contractual basic halved, housing paid in full, the rest halved.
        $this->assertEqualsWithDelta(4500.0, (float) $record->basic_salary, 0.01);
        $this->assertEqualsWithDelta(1200.0, (float) $record->housing_allowance, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $record->transportation_allowance, 0.01);
        $this->assertEqualsWithDelta(2100.0, (float) $record->allowances, 0.01);
        // The baseline keeps the day factor (300 x 0.5 = 150); the 600 extra
        // is taxed through the engine's marginal path: annualised bracket
        // delta on top of the fixed taxable base = 120. Tax = 150 + 120.
        $this->assertEqualsWithDelta(270.0, (float) $record->income_tax, 0.01);
        // The extra also carries the flat martyrs rate: 600 x 0.0005.
        $this->assertEqualsWithDelta(0.30, (float) $record->martyrs_fund, 0.01);
        // Net pays exactly the displayed pieces minus deductions.
        $this->assertEqualsWithDelta(6600.0, (float) $record->monthly_total, 0.01);
        $this->assertEqualsWithDelta(6600.0 - 270.0 - 0.30, (float) $record->net_salary, 0.01);
        $this->assertEqualsWithDelta(270.30, (float) $record->deductions, 0.01);
        // Audit: gross extra over the all-prorated baseline and the tax it carries.
        $this->assertEqualsWithDelta(600.0, (float) ($record->calculation_snapshot['proration_adjustment'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(120.0, (float) ($record->calculation_snapshot['proration_adjustment_tax'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(0.30, (float) ($record->calculation_snapshot['proration_adjustment_martyrs'] ?? 0), 0.01);
    }

    public function test_baseline_all_prorated_keeps_engine_net_and_day_factor_tax(): void
    {
        DB::table('payroll_allowance_settings')->where('company_id', $this->company->id)->update(['is_prorated' => 1]);
        $employee = $this->employee('FPA2', 'Base Line');
        $this->salary($employee);
        $this->seedAttendance($employee);
        $record = $this->generate($employee);

        // Baseline identical to main: tax 300 x 0.5, net 6000 - 150, zero adjustment.
        $this->assertEqualsWithDelta(150.0, (float) $record->income_tax, 0.01);
        $this->assertEqualsWithDelta(5850.0, (float) $record->net_salary, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) ($record->calculation_snapshot['proration_adjustment'] ?? 0), 0.01);
    }

    public function test_partial_month_with_insurance_absence_and_martyrs_reconciles_exactly(): void
    {
        // Insurance is charged in the hire month's following months, so a
        // partial month with insurance due needs a mid-month termination:
        // hired long ago, terminated 15 Nov - payable 1-15 Nov = 15/30.
        EmployeeStatus::firstOrCreate(['name' => 'منتهي الخدمة']);
        $employee = Employee::create([
            'employee_code' => 'FPA3', 'first_name' => 'Full', 'full_name' => 'Full Statutory', 'company_id' => $this->company->id,
            'status_id' => EmployeeStatus::where('name', 'منتهي الخدمة')->first()->id, 'hire_date' => '2025-01-01',
            'termination_type' => 'استقالة', 'termination_date' => '2026-11-15',
            'shift_start' => '09:00', 'shift_end' => '17:00',
        ]);
        $this->salary($employee, [
            'effective_from' => '2025-01-01',
            'no_insurance' => false, 'insurance_wage' => 4545.45,
            'employee_insurance' => 500.0, 'employer_insurance' => 852.27, 'martyrs_fund' => 6.0,
        ]);
        // Present 1-15 Nov except one explicit absence day.
        for ($day = 1; $day <= 15; $day++) {
            $date = '2026-11-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
            Attendance::create([
                'employee_id' => $employee->id, 'attendance_date' => $date,
                'check_in' => $date === '2026-11-03' ? null : "{$date} 09:00:00",
                'check_out' => $date === '2026-11-03' ? null : "{$date} 17:00:00",
                'status' => $date === '2026-11-03' ? 'Absent' : 'Present',
                'source' => 'manual', 'approval_status' => 'approved',
            ]);
        }
        $record = $this->generate($employee);

        // Fixed earned 6600 (same split as the first test), insurance charged in
        // full (never prorated), tax and martyrs on the 55% earned share.
        $absence = (float) $record->absence_deduction;
        $this->assertGreaterThan(0.0, $absence);
        $this->assertEqualsWithDelta(270.0, (float) $record->income_tax, 0.01);
        // Martyrs: 6 x 0.5 day factor + 600 x 0.0005 on the extra = 3.30.
        $this->assertEqualsWithDelta(3.3, (float) $record->martyrs_fund, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $record->employee_insurance, 0.01);
        $this->assertEqualsWithDelta(852.27, (float) $record->employer_insurance, 0.01);
        // Exact reconciliation of the persisted record:
        // net = basic + allowances - (insurance + tax + martyrs + absence).
        $expectedNet = 6600.0 - (500.0 + 270.0 + 3.3 + $absence);
        $this->assertEqualsWithDelta($expectedNet, (float) $record->net_salary, 0.01);
        $this->assertEqualsWithDelta(500.0 + 270.0 + 3.3 + $absence, (float) $record->deductions, 0.01);
        $this->assertEqualsWithDelta(6600.0, (float) $record->monthly_total, 0.01);
        // Cost = what the company pays: entitlements + full employer insurance.
        $this->assertEqualsWithDelta(6600.0 + 852.27, (float) $record->employee_cost, 0.01);
    }
}
