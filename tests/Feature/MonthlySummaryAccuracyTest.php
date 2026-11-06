<?php

namespace Tests\Feature;

use App\Http\Controllers\JobDataController;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression coverage for the monthly summary accuracy fixes:
 *  - leave columns show working days (company calendar), prorated to the period;
 *  - a request entered after its owning period closed still counts in that period;
 *  - the Excel export honors the screen's department/search filters.
 */
class MonthlySummaryAccuracyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $user;
    private Employee $employee;
    private LeaveType $annualType;

    protected function setUp(): void
    {
        parent::setUp();

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );
        $this->company = Company::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        CompanySubscription::create([
            'company_id' => $this->company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => today(),
        ]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create([
            'name' => 'User A', 'email' => 'a@example.test', 'password' => bcrypt('secret'),
            'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $this->employee = Employee::create([
            'employee_code' => '9001', 'first_name' => 'EmpA', 'full_name' => 'Employee Alpha A',
            'company_id' => $this->company->id, 'status_id' => $status->id,
            'shift_start' => '09:00', 'shift_end' => '17:00', 'manual_annual_balance' => 21,
        ]);
        $this->annualType = LeaveType::create([
            'name' => 'إجازة سنوية', 'policy_code' => 'annual', 'is_paid' => true,
            'is_active' => true, 'deducts_annual_balance' => true, 'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    private function setSetting(string $key, string $value): void
    {
        \Illuminate\Support\Facades\DB::table('hr_operational_settings')->insert([
            'company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value,
            'label' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeLeave(string $start, string $end, float $days): Leave
    {
        return Leave::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annualType->id,
            'start_date' => $start,
            'end_date' => $end,
            'days' => $days,
            'status' => 'Approved',
        ]);
    }

    private function summaryRow(Carbon $month, ?Employee $employee = null): array
    {
        $method = new \ReflectionMethod(JobDataController::class, 'buildMonthlyRows');
        $rows = $method->invoke(app(JobDataController::class), collect([($employee ?? $this->employee)->fresh()]), $month);
        return $rows->first();
    }

    public function test_leave_days_use_working_days_not_calendar_days(): void
    {
        // Thursday 2026-09-03 through Sunday 2026-09-06 is 4 calendar days but
        // only 2 working days when Friday/Saturday are the weekly rest; the
        // stored leave days carry the company-calendar result and are kept
        // authoritative for a leave fully inside the period.
        $this->setSetting('weekly_rest_pattern', '0000110');
        $this->makeLeave('2026-09-03', '2026-09-06', 2);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(2.0, (float) $row['annual_leave_days']);
        $this->assertSame(2.0, (float) $row['total_leave_days']);
    }

    public function test_clipped_leave_counts_exact_working_days_not_calendar_proration(): void
    {
        // Wednesday 2026-09-30 through Sunday 2026-10-04 with Fri/Sat rest:
        // only Sep 30 falls in September, and it is a working day, so the
        // summary must show exactly 1 - not a 1/5 calendar proration (0.6).
        $this->setSetting('weekly_rest_pattern', '0000110');
        $this->makeLeave('2026-09-30', '2026-10-04', 3);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(1.0, (float) $row['annual_leave_days']);
    }

    public function test_clipped_leave_skips_rest_days_at_the_overlap_edge(): void
    {
        // Friday 2026-09-25 (rest) through Friday 2026-10-02 (rest), Fri/Sat
        // rest: the September overlap is Sep 25-30, whose working days are
        // Sep 27-30 = 4 exactly.
        $this->setSetting('weekly_rest_pattern', '0000110');
        $this->makeLeave('2026-09-25', '2026-10-02', 4);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(4.0, (float) $row['annual_leave_days']);
    }

    public function test_clipped_leave_skips_holidays_inside_the_overlap(): void
    {
        // Tuesday 2026-09-29 through Friday 2026-10-02 with an official
        // holiday on Wednesday 2026-09-30: the September overlap is
        // Sep 29-30, but the holiday leaves exactly 1 working day.
        $this->setSetting('weekly_rest_pattern', '0000110');
        \Illuminate\Support\Facades\DB::table('hr_holidays')->insert([
            'company_id' => $this->company->id, 'holiday_date' => '2026-09-30',
            'name' => 'عيد رسمي', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeLeave('2026-09-29', '2026-10-02', 2);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(1.0, (float) $row['annual_leave_days']);
    }

    public function test_calendar_mode_counts_plain_overlap_days_when_exclusion_is_off(): void
    {
        // With rest/holiday exclusion disabled, leaves count calendar days:
        // the September overlap of Fri 2026-09-25 to Fri 2026-10-02 is 6 days.
        $this->setSetting('weekly_rest_pattern', '0000110');
        $this->setSetting('leave_exclude_rest_holidays', '0');
        $this->makeLeave('2026-09-25', '2026-10-02', 8);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(6.0, (float) $row['annual_leave_days']);
    }

    public function test_request_entered_after_period_close_still_counts_in_its_owning_period(): void
    {
        $advanceType = RequestType::create([
            'code' => 'advance', 'display_name' => 'سلفة', 'source_key' => 'advance',
            'is_active' => true, 'company_id' => $this->company->id,
        ]);

        // Created today (long after the September period closed) but dated
        // inside September: the September summary must still count it.
        HrRequest::create([
            'employee_id' => $this->employee->id,
            'request_type' => 'advance',
            'request_type_id' => $advanceType->id,
            'title' => 'سلفة',
            'payload' => ['request_date' => '2026-09-10', 'amount' => 100],
            'status' => 'Approved',
            'created_by' => $this->user->id,
        ]);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(1, $row['advance_request_count']);
    }

    public function test_request_outside_the_period_is_not_counted(): void
    {
        $advanceType = RequestType::create([
            'code' => 'advance', 'display_name' => 'سلفة', 'source_key' => 'advance',
            'is_active' => true, 'company_id' => $this->company->id,
        ]);

        HrRequest::create([
            'employee_id' => $this->employee->id,
            'request_type' => 'advance',
            'request_type_id' => $advanceType->id,
            'title' => 'سلفة',
            'payload' => ['request_date' => '2026-08-10', 'amount' => 100],
            'status' => 'Approved',
            'created_by' => $this->user->id,
        ]);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(0, $row['advance_request_count']);
    }

    public function test_export_honors_department_and_search_filters(): void
    {
        $departmentA = Department::create(['name' => 'Engineering', 'is_active' => true, 'company_id' => $this->company->id]);
        $departmentB = Department::create(['name' => 'Sales', 'is_active' => true, 'company_id' => $this->company->id]);
        $this->employee->update(['department_id' => $departmentA->id]);
        $other = Employee::create([
            'employee_code' => '9002', 'first_name' => 'EmpB', 'full_name' => 'Employee Bravo B',
            'company_id' => $this->company->id, 'status_id' => $this->employee->status_id,
            'department_id' => $departmentB->id,
        ]);

        $this->get(route('jobs.export', ['month' => '2026-09', 'department_id' => $departmentA->id]))
            ->assertOk()
            ->assertSee('Employee Alpha A')
            ->assertDontSee('Employee Bravo B');

        $this->get(route('jobs.export', ['month' => '2026-09', 'search' => 'Bravo']))
            ->assertOk()
            ->assertSee('Employee Bravo B')
            ->assertDontSee('Employee Alpha A');
    }

    public function test_export_without_filters_includes_every_company_employee(): void
    {
        Employee::create([
            'employee_code' => '9002', 'first_name' => 'EmpB', 'full_name' => 'Employee Bravo B',
            'company_id' => $this->company->id, 'status_id' => $this->employee->status_id,
        ]);

        $this->get(route('jobs.export', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Employee Alpha A')
            ->assertSee('Employee Bravo B');
    }

    public function test_unpaid_leave_is_separate_from_other_leave_days(): void
    {
        // «بدون أجر» has its own column; unpaid days must not also count in
        // «أنواع أخرى», so the leave-type columns add up to «إجمالي الإجازات».
        $this->setSetting('weekly_rest_pattern', '0000100');
        $unpaidType = LeaveType::create([
            'name' => 'إجازة بدون أجر', 'policy_code' => 'unpaid', 'is_paid' => false,
            'is_active' => true, 'deducts_annual_balance' => false, 'company_id' => $this->company->id,
        ]);
        $otherType = LeaveType::create([
            'name' => 'إجازة حج', 'policy_code' => 'pilgrimage', 'is_paid' => true,
            'is_active' => true, 'deducts_annual_balance' => false, 'company_id' => $this->company->id,
        ]);
        // Monday+Tuesday 2026-09-07/08 unpaid; Wednesday+Thursday 09/10 pilgrimage;
        // Sunday+Monday 13/14 annual - all fully inside the September period.
        Leave::create(['employee_id' => $this->employee->id, 'leave_type_id' => $unpaidType->id,
            'start_date' => '2026-09-07', 'end_date' => '2026-09-08', 'days' => 2, 'status' => 'Approved']);
        Leave::create(['employee_id' => $this->employee->id, 'leave_type_id' => $otherType->id,
            'start_date' => '2026-09-09', 'end_date' => '2026-09-10', 'days' => 2, 'status' => 'Approved']);
        $this->makeLeave('2026-09-13', '2026-09-14', 2);

        $row = $this->summaryRow(Carbon::create(2026, 9, 1));

        $this->assertSame(2.0, (float) $row['unpaid_leave_days']);
        $this->assertSame(2.0, (float) $row['annual_leave_days']);
        $this->assertSame(2.0, (float) $row['other_leave_days']);
        $this->assertSame(6.0, (float) $row['total_leave_days']);
    }
}
