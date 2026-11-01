<?php

namespace Tests\Feature;

use App\Http\Controllers\JobDataController;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Imports\WorkbookRequestImporter;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Integration coverage for timed overtime requests: a request earns only its
 * window's overlap with actual fingerprint presence (partial overlap earns
 * the partial duration), in both the monthly summary and payroll, and only
 * inside the employee's own company.
 */
class OvertimeTimeWindowTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $user;
    private Employee $employee;

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
        RequestType::create([
            'code' => 'overtime', 'display_name' => 'إضافي', 'source_key' => 'overtime',
            'is_active' => true, 'company_id' => $this->company->id,
        ]);

        $this->actingAs($this->user);
    }

    private function attendance(string $date, string $in, string $out): Attendance
    {
        return Attendance::create([
            'employee_id' => $this->employee->id,
            'attendance_date' => $date,
            'check_in' => $in,
            'check_out' => $out,
            'status' => 'Present',
        ]);
    }

    private function overtimeRequest(string $date, string $period, float $hours, ?string $from, ?string $to): HrRequest
    {
        $payload = ['request_date' => $date, 'hours' => $hours, 'overtime_period' => $period];
        if ($from !== null) $payload['from_time'] = $from;
        if ($to !== null) $payload['to_time'] = $to;

        return HrRequest::create([
            'employee_id' => $this->employee->id,
            'request_type' => 'overtime',
            'title' => 'إضافي',
            'payload' => $payload,
            'status' => 'Approved',
            'created_by' => $this->user->id,
        ]);
    }

    private function summaryRow(Carbon $month): array
    {
        $method = new \ReflectionMethod(JobDataController::class, 'buildMonthlyRows');
        $rows = $method->invoke(app(JobDataController::class), collect([$this->employee->fresh()]), $month);
        return $rows->first();
    }

    private function payrollOvertime(): array
    {
        $method = new \ReflectionMethod(PayrollRunService::class, 'earnedOvertime');
        return $method->invoke(
            app(PayrollRunService::class),
            $this->employee->fresh(),
            Attendance::where('employee_id', $this->employee->id)->get()
        );
    }

    public function test_partial_window_overlap_earns_partial_minutes_in_summary(): void
    {
        // Amer's rule: request says one hour 08:00-09:00, fingerprint in at
        // 08:30, so half an hour counts.
        $this->attendance('2026-11-01', '08:30', '17:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, '08:00', '09:00');

        $row = $this->summaryRow(Carbon::parse('2026-11-01'));

        $this->assertSame(30, $row['day_overtime_minutes']);
        $this->assertSame(0, $row['night_overtime_minutes']);
    }

    public function test_full_window_coverage_earns_full_request_in_summary(): void
    {
        $this->attendance('2026-11-01', '07:30', '17:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, '08:00', '09:00');

        $this->assertSame(60, $this->summaryRow(Carbon::parse('2026-11-01'))['day_overtime_minutes']);
    }

    public function test_post_work_presence_does_not_cover_pre_work_window(): void
    {
        // Stayed two hours late, never came early: a pre-work request earns 0.
        $this->attendance('2026-11-01', '09:00', '19:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, '08:00', '09:00');

        $this->assertSame(0, $this->summaryRow(Carbon::parse('2026-11-01'))['day_overtime_minutes']);
    }

    public function test_earned_minutes_capped_at_requested_hours(): void
    {
        // Three hours of actual pre-work presence, one hour requested.
        $this->attendance('2026-11-01', '06:30', '17:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, '08:00', '09:00');

        $this->assertSame(60, $this->summaryRow(Carbon::parse('2026-11-01'))['day_overtime_minutes']);
    }

    public function test_night_window_earns_night_column_only(): void
    {
        // Night window default starts 19:00; request 19:00-21:00, out at 21:00.
        $this->attendance('2026-11-01', '09:00', '21:00');
        $this->overtimeRequest('2026-11-01', 'night', 2.0, '19:00', '21:00');

        $row = $this->summaryRow(Carbon::parse('2026-11-01'));
        $this->assertSame(0, $row['day_overtime_minutes']);
        $this->assertSame(120, $row['night_overtime_minutes']);
    }

    public function test_overnight_request_window_across_midnight(): void
    {
        // Request 22:00-01:00, fingerprint out 00:30 next day: 2.5h overlap,
        // requested 3h, so 150 minutes.
        $this->attendance('2026-11-01', '09:00', '00:30');
        $this->overtimeRequest('2026-11-01', 'night', 3.0, '22:00', '01:00');

        $this->assertSame(150, $this->summaryRow(Carbon::parse('2026-11-01'))['night_overtime_minutes']);
    }

    public function test_untimed_overtime_request_earns_nothing(): void
    {
        // Legacy requests without a window no longer count (Amer: test data
        // to be removed by the scoped cleanup).
        $this->attendance('2026-11-01', '07:30', '17:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, null, null);

        $this->assertSame(0, $this->summaryRow(Carbon::parse('2026-11-01'))['day_overtime_minutes']);
    }

    public function test_payroll_engine_uses_the_same_window_matching(): void
    {
        $this->attendance('2026-11-01', '08:30', '17:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, '08:00', '09:00');

        $result = $this->payrollOvertime();

        $this->assertSame(30.0, $result['day_minutes']);
        $this->assertSame(0.0, $result['night_minutes']);
    }

    public function test_payroll_engine_ignores_untimed_requests(): void
    {
        $this->attendance('2026-11-01', '07:30', '17:00');
        $this->overtimeRequest('2026-11-01', 'day', 1.0, null, null);

        $this->assertSame(0.0, $this->payrollOvertime()['day_minutes']);
    }

    public function test_other_company_requests_and_attendance_do_not_leak(): void
    {
        // A second company with an employee on the same code and date.
        // Fixtures are built with no authenticated actor so the tenant write
        // guard keeps the explicitly assigned company.
        auth()->logout();
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $companyB = Company::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);
        CompanySubscription::create([
            'company_id' => $companyB->id,
            'subscription_plan_id' => SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []])->id,
            'status' => 'active', 'starts_at' => today(),
        ]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager B', 'company_id' => $companyB->id]);
        $userB = User::create(['name' => 'User B', 'email' => 'b@example.test', 'password' => bcrypt('secret'), 'role_id' => $roleB->id, 'company_id' => $companyB->id, 'is_active' => true]);
        $employeeB = Employee::create(['employee_code' => '9001', 'first_name' => 'EmpB', 'full_name' => 'Employee Bravo B', 'company_id' => $companyB->id, 'status_id' => $status->id, 'shift_start' => '09:00', 'shift_end' => '17:00', 'manual_annual_balance' => 21]);

        // Company B has an identical request and matching presence...
        $this->actingAs($userB);
        Attendance::create(['employee_id' => $employeeB->id, 'attendance_date' => '2026-11-01', 'check_in' => '07:30', 'check_out' => '17:00', 'status' => 'Present']);
        HrRequest::create(['employee_id' => $employeeB->id, 'request_type' => 'overtime', 'title' => 'إضافي', 'payload' => ['request_date' => '2026-11-01', 'hours' => 1.0, 'overtime_period' => 'day', 'from_time' => '08:00', 'to_time' => '09:00'], 'status' => 'Approved', 'created_by' => $userB->id]);

        // ...but company A has only presence, no request of its own.
        $this->actingAs($this->user);
        $this->attendance('2026-11-01', '07:30', '17:00');

        $this->assertSame(0, $this->summaryRow(Carbon::parse('2026-11-01'))['day_overtime_minutes']);
    }

    public function test_store_requires_time_window_for_overtime(): void
    {
        $type = RequestType::where('source_key', 'overtime')->firstOrFail();

        $response = $this->post(route('requests.store'), [
            'employee_id' => $this->employee->id,
            'request_type_id' => $type->id,
            'title' => 'إضافي تجريبي',
            'details' => 'تفاصيل',
            'overtime_request_date' => '2026-11-01',
            'overtime_period' => 'day',
            'day_overtime_hours' => 1,
        ]);

        $response->assertSessionHasErrors(['from_time', 'to_time']);
        $this->assertSame(0, HrRequest::count());
    }

    public function test_store_rejects_equal_window_bounds(): void
    {
        $type = RequestType::where('source_key', 'overtime')->firstOrFail();

        $response = $this->post(route('requests.store'), [
            'employee_id' => $this->employee->id,
            'request_type_id' => $type->id,
            'title' => 'إضافي تجريبي',
            'details' => 'تفاصيل',
            'overtime_request_date' => '2026-11-01',
            'overtime_period' => 'day',
            'day_overtime_hours' => 1,
            'from_time' => '08:00',
            'to_time' => '08:00',
        ]);

        $response->assertSessionHasErrors(['to_time']);
        $this->assertSame(0, HrRequest::count());
    }

    public function test_store_persists_the_time_window(): void
    {
        $type = RequestType::where('source_key', 'overtime')->firstOrFail();

        $response = $this->post(route('requests.store'), [
            'employee_id' => $this->employee->id,
            'request_type_id' => $type->id,
            'title' => 'إضافي تجريبي',
            'details' => 'تفاصيل',
            'overtime_request_date' => '2026-11-01',
            'overtime_period' => 'day',
            'day_overtime_hours' => 1,
            'from_time' => '08:00',
            'to_time' => '09:00',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $request = HrRequest::firstOrFail();
        $this->assertSame('08:00', $request->payload['from_time']);
        $this->assertSame('09:00', $request->payload['to_time']);
        $this->assertSame('overtime', $request->payload['overtime_period'] === 'day' ? 'overtime' : 'night');
        $this->assertSame($this->company->id, $request->company_id);
    }

    public function test_import_overtime_row_with_window_columns(): void
    {
        $csv = "كود الموظف,اسم الموظف,تاريخ الإضافي,عدد الساعات,نوع الإضافي,من الساعة,إلى الساعة\n"
            . "9001,Employee Alpha A,2026-10-15,1,نهاري,08:00,09:00\n";
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $csv);

        $result = app(WorkbookRequestImporter::class)->import($path, 'csv', app(PayrollPeriodGuard::class), app(PayrollRunService::class));

        $this->assertSame(1, $result['imported'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        $request = HrRequest::firstOrFail();
        $this->assertSame('08:00', $request->payload['from_time']);
        $this->assertSame('09:00', $request->payload['to_time']);
        unlink($path);
    }

    public function test_import_overtime_row_without_window_is_skipped_with_clear_error(): void
    {
        $csv = "كود الموظف,اسم الموظف,تاريخ الإضافي,عدد الساعات,نوع الإضافي\n"
            . "9001,Employee Alpha A,2026-10-15,1,نهاري\n";
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $csv);

        $result = app(WorkbookRequestImporter::class)->import($path, 'csv', app(PayrollPeriodGuard::class), app(PayrollRunService::class));

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('من الساعة', implode(' ', $result['errors']));
        $this->assertSame(0, HrRequest::count());
        unlink($path);
    }

    public function test_import_advance_rows_still_work_without_window_columns(): void
    {
        $csv = "كود الموظف,اسم الموظف,تاريخ الطلب,مبلغ السلفة\n"
            . "9001,Employee Alpha A,2026-10-15,500\n";
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $csv);
        RequestType::create(['code' => 'advance', 'display_name' => 'سلفة', 'source_key' => 'advance', 'is_active' => true, 'company_id' => $this->company->id]);

        $result = app(WorkbookRequestImporter::class)->import($path, 'csv', app(PayrollPeriodGuard::class), app(PayrollRunService::class));

        $this->assertSame(1, $result['imported'], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        $request = HrRequest::firstOrFail();
        $this->assertSame('advance', $request->request_type);
        $this->assertArrayNotHasKey('from_time', (array) $request->payload);
        unlink($path);
    }
}
