<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\LeaveSmartAlertNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The compliance alerts Amer asked for: 15-days-actual and 6-consecutive
 * cycle-end planning, settlement within 3 years from known bucket ages, and
 * the setup nudge for uninitialized history — deduplicated, tenant-scoped,
 * resolving when the underlying state is fixed.
 */
class LeaveSmartAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $user;
    private EmployeeStatus $status;
    private LeaveType $annualType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create(['name' => 'U', 'email' => 'u@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->annualType = LeaveType::create(['name' => 'إجازة سنوية', 'policy_code' => 'annual', 'is_paid' => true, 'is_active' => true, 'deducts_annual_balance' => true, 'company_id' => $this->company->id]);
        $this->actingAs($this->user);
    }

    private function employee(string $code, string $hireDate, ?int $companyId = null): Employee
    {
        if ($companyId && $companyId !== $this->company->id) {
            $id = DB::table('employees')->insertGetId([
                'employee_code' => $code, 'first_name' => 'E'.$code, 'full_name' => 'Employee '.$code,
                'company_id' => $companyId, 'status_id' => $this->status->id, 'hire_date' => $hireDate,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return Employee::withoutGlobalScopes()->findOrFail($id);
        }
        return Employee::create([
            'employee_code' => $code, 'first_name' => 'E'.$code, 'full_name' => 'Employee '.$code,
            'company_id' => $this->company->id, 'status_id' => $this->status->id, 'hire_date' => $hireDate,
        ]);
    }

    private function alerts(string $kind, ?User $user = null): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('notifiable_id', ($user ?? $this->user)->id)
            ->where('type', LeaveSmartAlertNotification::class)
            ->where('data->alert_kind', $kind)->get()
            ->map(fn ($row) => json_decode($row->data, true));
    }

    public function test_cycle_end_planning_and_six_consecutive_fire_and_resolve(): void
    {
        // Cycle ends 2026-11-19, within the 30-day planning window.
        $employee = $this->employee('7201', '2025-11-20');
        Leave::create(['employee_id' => $employee->id, 'leave_type_id' => $this->annualType->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-09', 'days' => 5, 'status' => 'Approved']);

        Artisan::call('leaves:notify-smart');
        Artisan::call('leaves:notify-smart'); // daily re-run must not duplicate

        $planning = $this->alerts('leave_cycle_end_planning');
        $this->assertCount(1, $planning);
        $this->assertStringContainsString('Employee 7201', $planning[0]['message']);
        $this->assertStringContainsString('5', $planning[0]['message']);
        $this->assertStringContainsString('19/11/2026', $planning[0]['message']);
        $this->assertSame('high', $planning[0]['priority']);
        $this->assertSame('2026-11-19', $planning[0]['alert_date']);

        $six = $this->alerts('leave_six_consecutive');
        $this->assertCount(1, $six);
        $this->assertStringContainsString('19/11/2026', $six[0]['message']);

        // Resolution: a 6+ consecutive leave removes the consecutive alert on
        // the next run (no new alert, no retrigger), planning stays because
        // taken is still under 15.
        Leave::create(['employee_id' => $employee->id, 'leave_type_id' => $this->annualType->id, 'start_date' => '2026-02-01', 'end_date' => '2026-02-06', 'days' => 6, 'status' => 'Approved']);
        Artisan::call('leaves:notify-smart');
        $this->assertCount(1, $this->alerts('leave_six_consecutive')); // old one only, no retrigger
        $this->assertCount(1, $this->alerts('leave_cycle_end_planning'));
    }

    public function test_no_planning_alert_outside_window_or_when_quota_met(): void
    {
        // Cycle ends far away.
        $this->employee('7202', '2025-01-15');
        // Cycle ends soon but 15 days already taken incl. a 6-day block.
        $covered = $this->employee('7203', '2025-11-20');
        Leave::create(['employee_id' => $covered->id, 'leave_type_id' => $this->annualType->id, 'start_date' => '2026-01-01', 'end_date' => '2026-01-08', 'days' => 8, 'status' => 'Approved']);
        Leave::create(['employee_id' => $covered->id, 'leave_type_id' => $this->annualType->id, 'start_date' => '2026-03-01', 'end_date' => '2026-03-07', 'days' => 7, 'status' => 'Approved']);

        Artisan::call('leaves:notify-smart');

        $this->assertCount(0, $this->alerts('leave_cycle_end_planning'));
        $this->assertCount(0, $this->alerts('leave_six_consecutive'));
    }

    public function test_settlement_alert_keys_off_known_bucket_age_with_thresholds(): void
    {
        DB::table('leave_policy_settings')->insert(['company_id' => $this->company->id, 'setting_key' => 'leave_carryover_baseline', 'setting_value' => '2020-01-01', 'label' => 'baseline', 'created_at' => now(), 'updated_at' => now()]);
        // Anchor is the bucket's cycle START + the planning cadence (3 years):
        // an internal review date, since Art. 125 fixes the interval but no
        // anchor. Hired Jan 2022: the 2024 bucket (starts 2024-01-01) reaches
        // its planning date 01/01/2027 — inside the 90-day threshold; the
        // 2022/2023 buckets already passed theirs and stop nagging.
        $this->employee('7204', '2022-01-01');
        // Hired Dec 2022: the bucket starting 2023-12-01 reaches its planning
        // date 01/12/2026 — inside the tighter 30-day threshold.
        $this->employee('7205', '2022-12-01');

        Artisan::call('leaves:notify-smart');
        Artisan::call('leaves:notify-smart');

        $alerts = $this->alerts('leave_settlement_due');
        $this->assertCount(2, $alerts);
        $wide = $alerts->firstWhere('dedupe_key', fn ($k) => str_contains((string) $k, '7204')) ?? $alerts->first(fn ($a) => str_contains($a['message'], 'Employee 7204'));
        $this->assertNotNull($wide);
        $this->assertStringContainsString('01/01/2027', $wide['message']);
        $this->assertSame('normal', $wide['priority']);
        // Planning framing only — never a statutory due-date claim.
        $this->assertStringContainsString('تخطيطي', $wide['message']);
        $this->assertStringNotContainsString('حد الثلاث سنوات', $wide['message']);
        $tight = $alerts->first(fn ($a) => str_contains($a['message'], 'Employee 7205'));
        $this->assertNotNull($tight);
        $this->assertStringContainsString('01/12/2026', $tight['message']);
        $this->assertSame('high', $tight['priority']);
    }

    public function test_uninitialized_history_alert_links_to_real_setup_page(): void
    {
        $employee = $this->employee('7206', '2024-03-01');

        Artisan::call('leaves:notify-smart');
        Artisan::call('leaves:notify-smart');

        $alerts = $this->alerts('leave_history_uninitialized');
        $this->assertCount(1, $alerts);
        $this->assertSame(route('leaves.opening.index'), $alerts[0]['url']);
        $this->assertStringContainsString('Employee 7206', $alerts[0]['message']);

        // Resolution: once an opening entry exists, the nudge stops for the
        // next cycle's key (no new alert for the same cycle, state initialized).
        DB::table('leave_opening_entries')->insert(['company_id' => $this->company->id, 'employee_id' => $employee->id, 'cycle_year' => 2024, 'amount' => 0, 'is_confirmed_zero' => true, 'reason' => 'راجعنا: لا يوجد', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('leaves:notify-smart');
        $this->assertCount(1, $this->alerts('leave_history_uninitialized'));
    }

    public function test_alerts_are_tenant_scoped(): void
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited']);
        $other = Company::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $other->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $otherRole = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $other->id]);
        $otherUser = User::create(['name' => 'V', 'email' => 'v@example.test', 'password' => bcrypt('x'), 'role_id' => $otherRole->id, 'company_id' => $other->id, 'is_active' => true]);
        $this->employee('7301', '2025-11-20', $other->id); // foreign, cycle ending soon
        $this->employee('7302', '2024-03-01'); // ours, uninitialized

        Artisan::call('leaves:notify-smart');

        // Our user never sees the foreign employee; the foreign user gets his own.
        foreach ($this->alerts('leave_cycle_end_planning') as $alert) {
            $this->assertStringNotContainsString('Employee 7301', $alert['message']);
        }
        $foreignPlanning = DB::table('notifications')->where('notifiable_id', $otherUser->id)
            ->where('type', LeaveSmartAlertNotification::class)->where('data->alert_kind', 'leave_cycle_end_planning')->count();
        $this->assertSame(1, $foreignPlanning);
    }
}
