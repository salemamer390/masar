<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\LeaveAttendanceConflictService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeaveAttendanceConflictNoticeTest extends TestCase
{
    use DatabaseTransactions;

    protected Company $company;
    protected User $manager;
    protected Employee $employee;
    protected Leave $leave;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'conf-unlimited'], ['name' => 'T', 'employee_limit' => 1000, 'features' => []]);
        $this->company = Company::create(['name' => 'ConfCo', 'slug' => 'conf-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->manager = User::create(['name' => 'M', 'email' => 'conf@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->manager);
        $this->employee = Employee::create(['employee_code' => 'CF1', 'first_name' => 'CF', 'full_name' => 'Conflict Emp', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01']);
        $type = LeaveType::withoutGlobalScopes()->first() ?? LeaveType::create(['name' => 'Annual', 'company_id' => $this->company->id]);
        $this->leave = Leave::withoutGlobalScopes()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-03', 'end_date' => '2026-11-05', 'days' => 3, 'status' => 'Approved', 'company_id' => $this->company->id]);
        Attendance::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-04', 'check_in' => '09:00:00', 'check_out' => '17:00:00', 'status' => 'Present']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_notice_links_to_the_attendance_row_and_repeats_until_confirmed_without_touching_data(): void
    {
        $svc = app(LeaveAttendanceConflictService::class);
        $this->assertSame(1, $svc->notify());
        $this->assertSame(0, $svc->notify(), 'no repeat inside 24h');
        $n = $this->manager->notifications()->first();
        $this->assertSame('leave_attendance_conflict', $n->data['kind']);
        $this->assertStringContainsString('highlight=2026-11-04', $n->data['url']);
        $this->assertStringContainsString('employee_id='.$this->employee->id, $n->data['url']);

        Carbon::setTestNow(now()->addHours(25));
        $this->assertSame(1, $svc->notify(), 'reminder after 24h');
        $this->assertSame(2, $this->manager->notifications()->count());

        $latest = $this->manager->notifications()->latest()->first();
        $this->get(route('notifications.index'))->assertOk()->assertSee('data-conflict-confirm', false)->assertSee('data-open-notification="'.$latest->id.'"', false);
        // Opening the notice marks it read and redirects to the attendance row.
        $this->post(route('notifications.read', $latest->id))->assertRedirect($latest->data['url']);
        $this->post(route('notifications.confirm', $latest->id))->assertRedirect();
        $this->get(route('notifications.index'))->assertOk()->assertSee('تم التأكيد');

        Carbon::setTestNow(now()->addHours(50));
        $this->assertSame(0, $svc->notify(), 'confirmed: no more reminders');
        // Confirm is an acknowledgement only.
        $this->assertSame('Approved', Leave::withoutGlobalScopes()->find($this->leave->id)->status);
        $this->assertSame(1, Attendance::where('employee_id', $this->employee->id)->count());
    }

    public function test_conflict_shows_in_the_top_bell_with_badge_row_link_and_confirm(): void
    {
        app(LeaveAttendanceConflictService::class)->notify();
        $n = $this->manager->notifications()->first();
        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('class="notification-dot">1<', $html);
        $this->assertStringContainsString(route('notifications.read', $n->id), $html);
        $this->assertStringContainsString(route('notifications.confirm', $n->id), $html);
        $this->assertStringContainsString('data-bell-conflict-confirm', $html);
        // Clicking the bell item marks it read and lands on the affected attendance row.
        $this->post(route('notifications.read', $n->id))->assertRedirect($n->data['url']);
        $this->assertStringContainsString('highlight=2026-11-04', $n->data['url']);
    }

    public function test_attendance_page_highlights_the_linked_day(): void
    {
        $this->get(route('attendance.index', ['employee_id' => $this->employee->id, 'from_date' => '2026-11-04', 'to_date' => '2026-11-04', 'highlight' => '2026-11-04']))
            ->assertOk()->assertSee('data-highlight="1"', false);
    }

    public function test_reminders_also_run_from_page_requests_when_there_is_no_cron(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $this->get(route('notifications.index'))->assertOk();
        $this->assertSame(1, $this->manager->notifications()->count());
        $this->get(route('notifications.index'))->assertOk();
        $this->assertSame(1, $this->manager->notifications()->count(), 'throttled to once per hour');
    }

    public function test_open_draft_period_is_covered_and_other_companies_get_nothing(): void
    {
        // Old leave day inside an October payroll period that is still an open draft.
        $type = $this->leave->leave_type_id;
        Leave::withoutGlobalScopes()->create(['employee_id' => $this->employee->id, 'leave_type_id' => $type, 'start_date' => '2026-10-08', 'end_date' => '2026-10-10', 'days' => 3, 'status' => 'Approved', 'company_id' => $this->company->id]);
        Attendance::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-10-09', 'check_in' => '09:00:00', 'check_out' => '17:00:00', 'status' => 'Present']);
        $svc = app(LeaveAttendanceConflictService::class);
        $dates = fn () => collect($svc->conflicts())->map(fn ($c) => $c['date']->toDateString())->all();
        $this->assertNotContains('2026-10-09', $dates(), 'no open draft yet: October is closed territory');
        \App\Models\PayrollRun::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'payroll_month' => '2026-10-01', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'Draft']);
        $this->assertContains('2026-10-09', $dates(), 'open draft period must be covered');

        // A manager of another company never receives this company's conflicts.
        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'status' => 'active']);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $other->id]);
        $stranger = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $other->id, 'is_active' => true]);
        $svc->notify();
        $this->assertSame(0, $stranger->notifications()->count());
        $this->assertGreaterThan(0, $this->manager->notifications()->count());
    }

    public function test_no_notice_without_attendance_on_leave_days(): void
    {
        Attendance::where('employee_id', $this->employee->id)->delete();
        $this->assertSame(0, app(LeaveAttendanceConflictService::class)->notify());
    }
}
