<?php

namespace Tests\Feature;

use App\Http\Controllers\JobDataController;
use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, HrRequest, Role, SubscriptionPlan, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OvertimeCoverageAlertTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'integration-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'OTAlert', 'slug' => 'otalert', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $role->permissions()->sync(\App\Models\Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'otalert@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        $this->employee = Employee::create(['employee_code' => 'OTALERT', 'first_name' => 'Test', 'full_name' => 'Overtime Alert Employee', 'company_id' => $company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function record(string $day, string $in, string $out): Attendance
    {
        return Attendance::create(['employee_id' => $this->employee->id, 'attendance_date' => $day, 'check_in' => $in, 'check_out' => $out, 'status' => 'Present', 'approval_status' => 'Pending']);
    }

    private function otRequest(string $day, string $from, string $to, float $hours, string $period): HrRequest
    {
        return HrRequest::create(['employee_id' => $this->employee->id, 'request_type' => 'overtime', 'title' => 'overtime', 'payload' => ['request_date' => $day, 'from_time' => $from, 'to_time' => $to, 'hours' => $hours, 'overtime_period' => $period], 'status' => 'Pending', 'created_by' => $this->user->id]);
    }

    private function gaps(): array
    {
        return (new \ReflectionMethod(JobDataController::class, 'collectOvertimeGaps'))
            ->invoke(app(JobDataController::class), Employee::select(['id', 'employee_code', 'full_name', 'shift_start', 'shift_end']), Carbon::parse('2026-11-01'));
    }

    public function test_presence_beyond_shift_without_a_request_is_flagged(): void
    {
        $this->record('2026-11-01', '09:05', '18:00');
        $gaps = $this->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame('2026-11-01', $gaps[0]['date']);
        $this->assertSame(60, $gaps[0]['day_minutes']);
        $this->assertSame(0, $gaps[0]['night_minutes']);
    }

    public function test_day_fully_covered_by_a_timed_request_is_not_flagged(): void
    {
        $this->record('2026-11-01', '09:00', '18:00');
        $this->otRequest('2026-11-01', '17:00', '18:00', 1, 'day');
        $this->assertCount(0, $this->gaps());
    }

    public function test_partial_coverage_flags_only_the_remainder(): void
    {
        $this->record('2026-11-01', '09:00', '19:00');
        $this->otRequest('2026-11-01', '17:00', '18:00', 1, 'day');
        $gaps = $this->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame(60, $gaps[0]['day_minutes']);
    }

    public function test_in_shift_presence_is_not_flagged(): void
    {
        $this->record('2026-11-01', '09:00', '17:00');
        $this->assertCount(0, $this->gaps());
    }

    public function test_untimed_legacy_request_does_not_cover_the_day(): void
    {
        $this->record('2026-11-01', '09:00', '18:00');
        HrRequest::create(['employee_id' => $this->employee->id, 'request_type' => 'overtime', 'title' => 'overtime', 'payload' => ['request_date' => '2026-11-01', 'hours' => 1, 'overtime_period' => 'day'], 'status' => 'Pending', 'created_by' => $this->user->id]);
        $gaps = $this->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame(60, $gaps[0]['day_minutes']);
    }

    public function test_summary_page_shows_the_banner_and_review_page_lists_the_day(): void
    {
        $this->record('2026-11-01', '09:05', '18:00');
        $summary = $this->get('/jobs?month=2026-11');
        $summary->assertOk();
        $summary->assertSee('مراجعة الأيام');
        $review = $this->get('/jobs/overtime-review?month=2026-11');
        $review->assertOk();
        $review->assertSee('OTALERT');
        $review->assertSee('01/11/2026');
    }

    public function test_summary_page_hides_the_banner_when_everything_is_covered(): void
    {
        $this->record('2026-11-01', '09:00', '17:00');
        $summary = $this->get('/jobs?month=2026-11');
        $summary->assertOk();
        $summary->assertDontSee('مراجعة الأيام');
    }
}
