<?php

namespace Tests\Feature;

use App\Models\{Attendance, AttendancePeriodArchive, Company, CompanySubscription, Employee, EmployeeStatus, Role, SubscriptionPlan, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceArchiveRequestSyncTest extends TestCase
{
    use DatabaseTransactions;

    private function makeCompany(string $slug, int $start, int $end): array
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'sync-unlimited'], ['name' => 'T', 'employee_limit' => 1000, 'features' => []]);
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => '2026-01-01']);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'M '.$slug, 'email' => $slug.'@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        foreach (['payroll_period_start_day' => $start, 'payroll_period_end_day' => $end, 'attendance_correction_days' => 3] as $k => $v) {
            DB::table('hr_operational_settings')->insert(['company_id' => $company->id, 'setting_key' => $k, 'setting_value' => (string) $v, 'label' => $k, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($user);
        $emp = Employee::create(['employee_code' => 'E-'.$slug, 'first_name' => 'Emp', 'full_name' => 'Employee '.$slug, 'company_id' => $company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01']);
        foreach (['2026-10-25', '2026-11-10', '2026-11-18'] as $d) {
            Attendance::create(['employee_id' => $emp->id, 'attendance_date' => $d, 'check_in' => '09:00:00', 'check_out' => '17:00:00', 'status' => 'Present']);
        }

        return [$company, $user, $emp];
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_page_visit_opens_the_ended_period_and_its_rows_are_reachable_from_the_attendance_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', 'Africa/Cairo'));
        [$company] = $this->makeCompany('sync-a', 20, 19);
        $this->assertSame(0, AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $company->id)->count());

        $this->get(route('attendance.index'))->assertOk()
            ->assertSee('ما زالت متاحة للتصحيح', false)->assertSee('عرض حضور الفترة', false);

        $row = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $this->assertCount(1, $row, 'only the latest period still inside its correction days, no older backfill');
        $this->assertSame('2026-10-20', $row[0]->period_start->toDateString());
        $this->assertSame('2026-11-19', $row[0]->period_end->toDateString());
        $this->assertSame('2026-11-23', $row[0]->closes_on->toDateString());
        $this->assertSame(AttendancePeriodArchive::PENDING, $row[0]->status);

        // The banner link shows the records of that period on the main attendance page.
        $this->get(route('attendance.index', ['from_date' => '2026-10-20', 'to_date' => '2026-11-19']))->assertOk()
            ->assertSee('25/10/2026', false)->assertSee('10/11/2026', false)->assertSee('18/11/2026', false);
        $this->assertSame(3, Attendance::withoutGlobalScopes()->where('company_id', $company->id)->count(), 'records untouched');
    }

    public function test_period_closes_after_its_correction_days_and_other_companies_are_not_affected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 10:00:00', 'Africa/Cairo'));
        [$a] = $this->makeCompany('sync-a2', 20, 19);
        $this->get(route('attendance.index'))->assertOk();
        [$b] = $this->makeCompany('sync-b2', 1, 30); // current period 01/11 to 30/11 contains today
        $this->get(route('attendance.index'))->assertOk();
        $this->assertSame(0, AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $b->id)->count());

        Carbon::setTestNow(Carbon::parse('2026-11-23 00:30:00', 'Africa/Cairo'));
        Cache::forget("attendance-archive-sync:{$a->id}");
        $this->actingAs(User::where('company_id', $a->id)->first());
        $this->get(route('attendance.index'))->assertOk();
        $closed = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $a->id)->first();
        $this->assertSame(AttendancePeriodArchive::CLOSED, $closed->status);
        $this->assertSame(3, (int) $closed->record_count);
        $this->assertSame(3, Attendance::withoutGlobalScopes()->where('company_id', $a->id)->count(), 'records untouched');
        $this->get(route('attendance.archive.index'))->assertOk()->assertSee('20/10/2026', false);
        $this->assertSame(0, AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $b->id)->count());
        $this->assertSame(0, DB::table('payroll_runs')->where('company_id', $a->id)->count(), 'payroll untouched');
    }
}
