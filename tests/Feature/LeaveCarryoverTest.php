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
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Amer's approved rules: usable balance = current cycle entitlement (the
 * optional manual override is a current-cycle benefit, never retro-applied)
 * + carried balance from earlier cycles (never expiring, accrued only from
 * recorded evidence, opening entries via manual_adjustment) − usage counted
 * only up to the reference date.
 */
class LeaveCarryoverTest extends TestCase
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

    private function employee(string $code, string $hireDate, ?float $manual = null): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'first_name' => 'E'.$code, 'full_name' => 'Employee '.$code,
            'company_id' => $this->company->id, 'status_id' => $this->status->id,
            'hire_date' => $hireDate, 'manual_annual_balance' => $manual,
        ]);
    }

    private function leave(Employee $employee, string $start, string $end, float $days): Leave
    {
        return Leave::create(['employee_id' => $employee->id, 'leave_type_id' => $this->annualType->id, 'start_date' => $start, 'end_date' => $end, 'days' => $days, 'status' => 'Approved']);
    }

    public function test_prebaseline_row_without_explicit_adjustment_accrues_nothing(): void
    {
        $employee = $this->employee('7001', '2025-01-01');
        // Balance rows are materialized automatically on display, so a
        // calculated pre-baseline row is NOT evidence of confirmed history —
        // only an explicit opening entry is.
        DB::table('leave_balances')->insert(['employee_id' => $employee->id, 'leave_year' => 2025, 'annual_entitlement' => 15, 'annual_used' => 3, 'annual_balance_remaining' => 12, 'created_at' => now(), 'updated_at' => now()]);
        $this->leave($employee, '2025-03-02', '2025-03-06', 5);

        $balance = app(LeaveBalanceService::class)->balance($employee->fresh(), Carbon::create(2026, 2, 1));

        $this->assertSame(0.0, (float) $balance['carried_balance']);
        $this->assertSame(21.0, (float) $balance['annual_entitlement']);
        $this->assertSame(21.0, (float) $balance['annual_balance_remaining']);
    }

    public function test_opening_adjustment_adds_confirmed_balance_without_inventing_history(): void
    {
        $employee = $this->employee('7002', '2025-01-01');
        // Opening entry: a confirmed 7-day old balance, recorded with reason.
        DB::table('leave_opening_entries')->insert(['company_id' => $this->company->id, 'employee_id' => $employee->id, 'cycle_year' => 2025, 'amount' => 7, 'is_confirmed_zero' => false, 'reason' => 'كشف موثق', 'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);

        $balance = app(LeaveBalanceService::class)->balance($employee->fresh(), Carbon::create(2026, 2, 1));

        $this->assertSame(7.0, (float) $balance['carried_balance']);
        $this->assertSame(28.0, (float) $balance['annual_balance_remaining']);
    }

    public function test_cycles_without_rows_or_recorded_leaves_contribute_nothing(): void
    {
        $employee = $this->employee('7003', '2020-01-01');

        $balance = app(LeaveBalanceService::class)->balance($employee->fresh(), Carbon::create(2026, 2, 1));

        // Six years of service but no recorded evidence: zero fabricated carry.
        $this->assertSame(0.0, (float) $balance['carried_balance']);
        $this->assertSame(21.0, (float) $balance['annual_entitlement']);
        $this->assertSame(21.0, (float) $balance['annual_balance_remaining']);
    }

    public function test_rowless_cycle_with_recorded_leaves_accrues_nothing_until_a_dated_baseline_exists(): void
    {
        $employee = $this->employee('7004', '2025-01-01');
        // Recorded leaves alone are not proof of the cycle's full history, so
        // without a persisted row or an explicit baseline nothing accrues.
        $this->leave($employee, '2025-06-01', '2025-06-04', 4);

        $balance = app(LeaveBalanceService::class)->balance($employee->fresh(), Carbon::create(2026, 2, 1));

        $this->assertSame(0.0, (float) $balance['carried_balance']);
        $this->assertSame(21.0, (float) $balance['annual_balance_remaining']);
    }

    public function test_manual_override_is_current_cycle_only_and_never_retro_applied(): void
    {
        $employee = $this->employee('7005', '2025-01-01', 25.0);
        // Tracked era: baseline precedes the 2025 cycle, so its bucket prices
        // at the policy 15 of its own start — never at today's manual 25.
        DB::table('leave_policy_settings')->insert(['company_id' => $this->company->id, 'setting_key' => 'leave_carryover_baseline', 'setting_value' => '2024-12-31', 'label' => 'baseline', 'created_at' => now(), 'updated_at' => now()]);
        $this->leave($employee, '2025-04-01', '2025-04-06', 6);

        $balance = app(LeaveBalanceService::class)->balance($employee->fresh(), Carbon::create(2026, 2, 1));

        $this->assertSame(25.0, (float) $balance['annual_entitlement']);
        $this->assertSame(9.0, (float) $balance['carried_balance']); // 15 − 6
        $this->assertSame(34.0, (float) $balance['annual_balance_remaining']);
    }

    public function test_fifo_oldest_bucket_drains_first_and_keeps_bucket_age(): void
    {
        // Amer's example shape: old bucket 6 + current 21, use 15
        // → old 0, current 12, and the old bucket is cleared for settlement.
        $employee = $this->employee('7008', '2025-01-01');
        DB::table('leave_policy_settings')->insert(['company_id' => $this->company->id, 'setting_key' => 'leave_carryover_baseline', 'setting_value' => '2024-12-31', 'label' => 'baseline', 'created_at' => now(), 'updated_at' => now()]);
        $this->leave($employee, '2025-04-01', '2025-04-09', 9); // 2025: 15 − 9 = 6
        $this->leave($employee, '2026-02-01', '2026-02-15', 15);

        $service = app(LeaveBalanceService::class);
        $balance = $service->balance($employee->fresh(), Carbon::create(2026, 3, 1));
        $this->assertSame(12.0, (float) $balance['annual_balance_remaining']);
        $this->assertSame(0.0, (float) $balance['carried_balance']);

        $employeeF = $employee->fresh();
        $cycle = ['start' => Carbon::create(2026, 1, 1), 'end' => Carbon::create(2026, 12, 31)];
        $leaves = \App\Models\Leave::with('leaveType')->where('employee_id', $employee->id)->effective()->get()
            ->filter(fn (\App\Models\Leave $leave) => $leave->leaveType && (bool) $leave->leaveType->deducts_annual_balance);
        $allocation = $service->allocate($employeeF, $leaves, $cycle, 15.0, $service->openingEntriesFor($employeeF), $this->company->id);
        $buckets = collect($allocation['buckets'])->keyBy('year');
        $this->assertSame(0.0, (float) $buckets[2025]['remaining']);
        $this->assertSame(12.0, (float) $buckets[2026]['remaining']);
    }

    public function test_midcycle_activation_keeps_current_entitlement_and_known_usage_with_unknown_history_clear(): void
    {
        // Hired mid-2025, feature activates today (baseline self-activates):
        // current cycle keeps full entitlement minus recorded usage, unknown
        // pre-baseline history carries nothing and is flagged for setup.
        $employee = $this->employee('7009', '2025-06-01');
        $this->leave($employee, today()->subDays(10)->toDateString(), today()->subDays(7)->toDateString(), 4);

        $service = app(LeaveBalanceService::class);
        $balance = $service->balance($employee->fresh(), today());
        $this->assertSame(21.0, (float) $balance['annual_entitlement']);
        $this->assertSame(4.0, (float) $balance['annual_used']);
        $this->assertSame(0.0, (float) $balance['carried_balance']);
        $this->assertSame(17.0, (float) $balance['annual_balance_remaining']);

        $cycle = ['start' => Carbon::parse('2025-06-01'), 'end' => Carbon::parse('2026-05-31')];
        // Reference inside the first cycle for the flag check below.
        $firstCycle = ['start' => Carbon::parse('2025-06-01'), 'end' => Carbon::parse('2026-05-31')];
        $this->assertTrue($service->hasUninitializedHistory($employee->fresh(), $firstCycle, collect(), $this->company->id));
    }

    public function test_usage_after_the_reference_date_is_not_consumed_yet(): void
    {
        $employee = $this->employee('7006', '2025-01-01');
        DB::table('leave_balances')->insert(['employee_id' => $employee->id, 'leave_year' => 2025, 'annual_entitlement' => 15, 'annual_used' => 15, 'annual_balance_remaining' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->leave($employee, '2026-01-10', '2026-01-12', 3);
        $this->leave($employee, '2026-05-01', '2026-05-02', 2);

        $service = app(LeaveBalanceService::class);
        $february = $service->balance($employee->fresh(), Carbon::create(2026, 2, 1));
        $june = $service->balance($employee->fresh(), Carbon::create(2026, 6, 1));

        $this->assertSame(3.0, (float) $february['annual_used']);
        $this->assertSame(18.0, (float) $february['annual_balance_remaining']);
        $this->assertSame(5.0, (float) $june['annual_used']);
        $this->assertSame(16.0, (float) $june['annual_balance_remaining']);
    }

    public function test_eligibility_alert_fires_once_with_reason_and_date(): void
    {
        $hire = today()->subMonths(6);
        $employee = $this->employee('7007', $hire->toDateString());

        // Two daily runs: the stable dedupe key must keep it to one stored
        // notification, not one per run.
        \Illuminate\Support\Facades\Artisan::call('leaves:notify-smart');
        \Illuminate\Support\Facades\Artisan::call('leaves:notify-smart');

        $rows = DB::table('notifications')->where('notifiable_id', $this->user->id)
            ->where('type', LeaveSmartAlertNotification::class)
            ->where('data->alert_kind', 'leave_eligibility')->get();
        $this->assertCount(1, $rows);
        $data = json_decode($rows->first()->data, true);
        $this->assertSame('leave_eligibility', $data['alert_kind']);
        $this->assertSame($employee->id, $data['employee_id']);
        $this->assertSame("leave_eligibility:{$employee->id}", $data['dedupe_key']);
        $this->assertSame($hire->copy()->addMonths(6)->toDateString(), $data['alert_date']);
        $this->assertStringContainsString($employee->full_name, $data['message']);
        $this->assertStringContainsString($hire->copy()->addMonths(6)->format('d/m/Y'), $data['message']);
        $this->assertNotEmpty($data['url']);
    }
}
