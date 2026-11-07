<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\MonthlySummarySnapshot;
use App\Models\PayrollRecord;
use App\Models\PayrollRun;
use App\Models\Role;
use App\Models\Salary;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\HrOperationalSettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cycle changes create marked transition runs: no gaps, no overlaps, wages by
 * actual days, and every calendar month's insurance and advance installment is
 * charged by exactly one run (the one whose period contains the month's 1st).
 */
class PayrollCycleTransitionTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'Company T', 'slug' => 'company-t', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create(['name' => 'User T', 'email' => 't@example.test', 'password' => bcrypt('secret'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->employee = Employee::create([
            'employee_code' => '9301', 'first_name' => 'Trans', 'full_name' => 'Transition Employee', 'company_id' => $this->company->id,
            'status_id' => $status->id, 'shift_start' => '09:00', 'shift_end' => '17:00', 'hire_date' => '2025-01-01',
        ]);
        Salary::create([
            'employee_id' => $this->employee->id, 'effective_from' => '2025-01-01', 'salary_type' => 'gross',
            'salary_input' => 6000, 'gross_salary' => 6000, 'basic_salary' => 6000, 'allowances' => 0, 'net_salary' => 5100,
            'insurance_wage' => 6000, 'applied_insurance_wage' => 6000, 'employee_insurance' => 300,
            'employer_insurance' => 600, 'income_tax' => 0, 'martyrs_fund' => 0, 'status' => 'Active',
        ]);
        $this->setting('payroll_period_start_day', '26');
        $this->setting('payroll_period_end_day', '25');
        $this->actingAs($this->user);
    }

    private function setting(string $key, string $value): void
    {
        DB::table('hr_operational_settings')->updateOrInsert(
            ['company_id' => $this->company->id, 'setting_key' => $key],
            ['setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]
        );
        app(HrOperationalSettingsService::class)->flush();
    }

    private function saveDays(string $start, string $end, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('payroll.settings.operational.update'), $extra + [
            'default_shift_start' => '09:00', 'default_shift_end' => '17:00', 'absence_cutoff_time' => '17:00', 'grace_minutes' => '15',
            'weekly_rest_pattern' => '0000100', 'night_start' => '19:00', 'night_end' => '07:00',
            'payroll_period_start_day' => $start, 'payroll_period_end_day' => $end, 'unpaid_leave_as_deduction' => '1',
        ]);
    }

    private function finalizedRun(string $month, string $from, string $to, ?int $advanceRequestId = null, float $installment = 0.0): PayrollRun
    {
        $run = PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_month' => $month, 'period_start' => $from, 'period_end' => $to,
            'status' => 'Approved', 'created_by' => $this->user->id,
        ]);
        $details = $advanceRequestId ? [['request_id' => $advanceRequestId, 'amount' => 3000.0, 'paid_before' => 0.0, 'installment' => $installment, 'remaining_after' => 3000.0 - $installment, 'start_month' => '2026-10-01']] : [];
        PayrollRecord::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->employee->id,
            'payroll_month' => $month, 'status' => 'Approved', 'loans' => $installment,
            'calculation_snapshot' => ['service_start' => $from, 'service_end' => $to, 'advance_details' => $details],
        ]);

        return $run;
    }

    private function transitionRun(): ?PayrollRun
    {
        return PayrollRun::withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_transition', true)->first();
    }

    private function recordOf(PayrollRun $run): ?PayrollRecord
    {
        return PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $this->employee->id)->first();
    }

    public function test_transition_pays_the_exact_gap_by_actual_days_and_charges_each_month_once(): void
    {
        Carbon::setTestNow('2026-12-05 09:00:00');
        $this->finalizedRun('2026-10-01', '2026-09-26', '2026-10-25');
        $this->saveDays('1', '31')->assertSessionHasNoErrors();

        $run = $this->transitionRun();
        $this->assertNotNull($run);
        $this->assertSame('2026-10-26', $run->payroll_month->toDateString());
        $this->assertSame(['2026-10-26', '2026-10-31'], [$run->period_start->toDateString(), $run->period_end->toDateString()], 'from the first uncovered day to the day before the new cycle starts on 1/11');
        $this->assertSame('2026-11-04', $run->closes_at->toDateString(), 'period end + 3 grace days + 1');
        $this->assertSame('Draft', $run->status);

        $record = $this->recordOf($run);
        $this->assertNotNull($record);
        $snapshot = (array) $record->calculation_snapshot;
        $this->assertSame(6, $snapshot['paid_days']);
        $this->assertEqualsWithDelta(0.2, $snapshot['proration_factor'], 0.0001, 'the transition pays its actual six days');
        $this->assertEqualsWithDelta(1200.0, (float) $record->basic_salary, 0.01, '6000 x 6/30');
        // The gap contains no 1st of a month: October was charged by the old run, November by its own.
        $this->assertEqualsWithDelta(0.0, (float) $record->employee_insurance, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $record->employer_insurance, 0.001);
        $this->assertSame(0, $snapshot['insurance_months']);
        Carbon::setTestNow();
    }

    public function test_transition_inside_one_month_charges_no_insurance_no_installment_but_grants_dated_advances(): void
    {
        Carbon::setTestNow('2026-12-05 09:00:00');
        $advance = HrRequest::create([
            'employee_id' => $this->employee->id, 'request_type' => 'advance', 'title' => 'advance',
            'payload' => ['amount' => 3000, 'request_date' => '2026-09-10'], 'status' => 'Approved', 'created_by' => $this->user->id,
        ]);
        $dated = HrRequest::create([
            'employee_id' => $this->employee->id, 'request_type' => 'advance', 'title' => 'advance',
            'payload' => ['amount' => 500, 'request_date' => '2026-11-28'], 'status' => 'Approved', 'created_by' => $this->user->id,
        ]);
        $this->finalizedRun('2026-10-01', '2026-09-26', '2026-10-25', $advance->id, 600.0);
        // A live November draft covers 26/10..25/11 under the old cycle: it is pinned, not doubled.
        PayrollRun::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'payroll_month' => '2026-11-01', 'status' => 'Draft', 'created_by' => $this->user->id,
        ]);

        $this->saveDays('1', '31')->assertSessionHasNoErrors();

        $run = $this->transitionRun();
        $this->assertSame(['2026-11-26', '2026-11-30'], [$run->period_start->toDateString(), $run->period_end->toDateString()], 'the pinned draft covers up to 25/11');
        $record = $this->recordOf($run);
        $snapshot = (array) $record->calculation_snapshot;
        $this->assertSame(0, $snapshot['insurance_months'], 'the gap contains no 1st of a month');
        $this->assertEqualsWithDelta(0.0, (float) $record->employee_insurance, 0.001, 'November insurance belongs to the pinned November run');
        $this->assertEqualsWithDelta(0.0, (float) $record->loans, 0.001, 'no monthly installment without a charge month');
        $this->assertEqualsWithDelta(500.0, (float) (((array) $snapshot['inputs'])['advance_earnings'] ?? 0), 0.001, 'the advance handed over on 28/11 is granted by the run whose period contains that date');

        // The pinned November draft keeps its original period and charges November once.
        $november = PayrollRun::withoutGlobalScopes()->where('company_id', $this->company->id)->whereDate('payroll_month', '2026-11-01')->firstOrFail();
        $this->assertSame(['2026-10-26', '2026-11-25'], [$november->period_start->toDateString(), $november->period_end->toDateString()], 'pinned to the old-cycle period');
        $novRecord = $this->recordOf($november);
        $this->assertEqualsWithDelta(300.0, (float) $novRecord->employee_insurance, 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $novRecord->loans, 0.001, "November's installment, continuing the schedule after October's 600");

        // The new-cycle December run charges December: next installment, never a repeat.
        $december = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $this->company->id);
        $decRecord = $this->recordOf($december);
        $this->assertEqualsWithDelta(300.0, (float) $decRecord->employee_insurance, 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $decRecord->loans, 0.001, "December's installment");
        $paidTotal = collect((array) $decRecord->calculation_snapshot['advance_details'])->sum(fn ($d) => (float) $d['paid_before'] + (float) $d['installment']);
        $this->assertEqualsWithDelta(1800.0, (float) $paidTotal, 0.001, '600 (Oct) + 600 (Nov) + 600 (Dec), never a repeated month');
        Carbon::setTestNow();
    }

    public function test_the_longest_gap_charges_its_single_month_once_and_blocks_an_overlapping_run(): void
    {
        // 19->18 is the widest possible cycle move: the gap can reach 29 days but
        // still contains at most one 1st of a month, so insurance is never doubled.
        Carbon::setTestNow('2027-01-20 09:00:00');
        $this->finalizedRun('2026-11-01', '2026-10-20', '2026-11-19');
        $this->saveDays('19', '18')->assertSessionHasNoErrors();

        $run = $this->transitionRun();
        $this->assertSame(['2026-11-20', '2026-12-18'], [$run->period_start->toDateString(), $run->period_end->toDateString()], 'up to the day before the first new-cycle period');
        $record = $this->recordOf($run);
        $snapshot = (array) $record->calculation_snapshot;
        $this->assertSame(29, $snapshot['paid_days']);
        $this->assertEqualsWithDelta(29 / 30, $snapshot['proration_factor'], 0.0001);
        $this->assertEqualsWithDelta(5800.0, (float) $record->basic_salary, 0.01, '6000 x 29/30');
        $this->assertSame(1, $snapshot['insurance_months'], 'the gap contains 1/12 and charges December once');
        $this->assertEqualsWithDelta(300.0, (float) $record->employee_insurance, 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $record->employer_insurance, 0.001);

        // The December monthly run (19/11..18/12 on the new cycle) would re-cover the
        // transition's days: the overlap guard refuses to create it.
        try {
            app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $this->company->id);
            $this->fail('an overlapping new-cycle run must be rejected');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        Carbon::setTestNow();
    }

    public function test_closes_at_is_snapshotted_and_never_moves_with_a_later_grace_change(): void
    {
        Carbon::setTestNow('2026-12-05 09:00:00');
        $run = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $this->assertSame('2026-11-29', $run->closes_at->toDateString(), '25/11 + 3 days + 1');
        $this->setting('attendance_correction_days', '7');
        $this->assertSame('2026-11-29', $run->fresh()->closes_at->toDateString(), 'existing runs keep their close date');
        Carbon::setTestNow();
    }

    public function test_the_nightly_close_locks_due_runs_and_never_touches_unended_periods(): void
    {
        Carbon::setTestNow('2026-11-26 09:00:00');
        $ended = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-10-01'), null, $this->company->id); // 26/09..25/10, closes 29/10
        $current = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id); // 26/10..25/11, closes 29/11

        $cmd = app(\App\Console\Commands\ClosePayrollPeriods::class);
        $cmd->setLaravel(app());
        $exit = $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--company' => $this->company->id]), new \Symfony\Component\Console\Output\NullOutput());
        $this->assertSame(0, $exit);
        $this->assertSame('Locked', $ended->fresh()->status, 'past its closes_at: closed automatically');
        $this->assertTrue((bool) $this->recordOf($ended->fresh())->is_locked);
        $this->assertSame('Draft', $current->fresh()->status, 'still inside its correction grace');

        Carbon::setTestNow('2026-11-29 00:30:00');
        $cmd = app(\App\Console\Commands\ClosePayrollPeriods::class);
        $cmd->setLaravel(app());
        $buf = new \Symfony\Component\Console\Output\BufferedOutput();
        $exit = $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--company' => $this->company->id]), $buf);
        $this->assertSame(0, $exit, $buf->fetch());
        $this->assertSame('Locked', $current->fresh()->status, 'closed on its closes_at day');
        Carbon::setTestNow();
    }

    public function test_manual_close_needs_confirmation_and_an_ended_period_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-11-26 09:00:00');
        $ended = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-10-01'), null, $this->company->id);
        $unended = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $this->company->id); // 26/11..25/12 contains today

        $this->post(route('payroll.close-period', ['payroll_run_id' => $ended->id]))->assertSessionHasErrors('confirm');
        $this->post(route('payroll.close-period', ['payroll_run_id' => $unended->id, 'confirm' => '1']))->assertSessionHasErrors('payroll');
        $this->assertSame('Draft', $unended->fresh()->status, 'an un-ended period never closes');

        $this->post(route('payroll.close-period', ['payroll_run_id' => $ended->id, 'confirm' => '1']))->assertSessionHas('toast');
        $this->assertSame('Locked', $ended->fresh()->status);
        $this->post(route('payroll.close-period', ['payroll_run_id' => $ended->id, 'confirm' => '1']))->assertSessionHas('toast');
        $this->assertSame('Locked', $ended->fresh()->status, 'a repeated close changes nothing');
        Carbon::setTestNow();
    }

    public function test_close_schedule_migration_is_partial_install_safe(): void
    {
        // Amer's interrupted-migrate incident class: one column added, the other missing.
        if (\Illuminate\Support\Facades\Schema::hasColumn('payroll_runs', 'is_transition')) {
            \Illuminate\Support\Facades\Schema::table('payroll_runs', function ($t) { $t->dropColumn('is_transition'); });
        }
        try {
            $migration = require database_path('migrations/2026_11_05_000000_add_close_schedule_and_transition_flag_to_payroll_runs.php');
            $migration->up(); // completes only the missing column
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('payroll_runs', 'closes_at'));
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('payroll_runs', 'is_transition'));
            $migration->up(); // a re-run over the complete schema is a no-op, never an error
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('payroll_runs', 'is_transition'));
        } finally {
            $migration = require database_path('migrations/2026_11_05_000000_add_close_schedule_and_transition_flag_to_payroll_runs.php');
            $migration->up();
            // DDL commits the wrapping DatabaseTransactions transaction, so this test
            // removes its own fixture explicitly to keep repeated suite runs green.
            $companyIds = \Illuminate\Support\Facades\DB::table('companies')->where('slug', 'company-t')->pluck('id');
            foreach ($companyIds as $companyId) {
                \Illuminate\Support\Facades\DB::table('salaries')->whereIn('employee_id', \Illuminate\Support\Facades\DB::table('employees')->where('company_id', $companyId)->pluck('id'))->delete();
                \Illuminate\Support\Facades\DB::table('employees')->where('company_id', $companyId)->delete();
                \Illuminate\Support\Facades\DB::table('users')->where('company_id', $companyId)->delete();
                \Illuminate\Support\Facades\DB::table('hr_operational_settings')->where('company_id', $companyId)->delete();
                \Illuminate\Support\Facades\DB::table('company_subscriptions')->where('company_id', $companyId)->delete();
                \Illuminate\Support\Facades\DB::table('roles')->where('company_id', $companyId)->delete();
                \Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->delete();
            }
        }
    }
    public function test_nightly_close_captures_the_missing_summary_for_the_closed_period_month_without_a_web_user(): void
    {
        Carbon::setTestNow('2026-11-26 09:00:00');
        $ended = app(\App\Services\PayrollRunService::class)->generate(Carbon::parse('2026-10-01'), null, $this->company->id); // 26/09..25/10, closes 29/10
        auth()->logout();

        $cmd = app(\App\Console\Commands\ClosePayrollPeriods::class);
        $cmd->setLaravel(app());
        $buf = new \Symfony\Component\Console\Output\BufferedOutput();
        $exit = $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--company' => $this->company->id]), $buf);

        $this->assertSame(0, $exit, $buf->fetch());
        $this->assertSame('Locked', $ended->fresh()->status);
        $snapshot = MonthlySummarySnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->whereDate('summary_month', '2026-10-01')->first();
        $this->assertNotNull($snapshot, 'closing an ended period must capture its missing monthly summary even from the console');
        $this->assertSame(1, (int) $snapshot->employee_count);
        $this->assertNull($snapshot->created_by, 'the scheduled close has no web user');
        Carbon::setTestNow();
    }

    public function test_transition_close_captures_the_period_end_month_not_the_transition_anchor_month(): void
    {
        Carbon::setTestNow('2026-12-05 09:00:00');
        $old = $this->finalizedRun('2026-11-01', '2026-10-20', '2026-11-19');
        $this->saveDays('19', '18')->assertSessionHasNoErrors();
        $transition = $this->transitionRun();
        $this->assertNotNull($transition);
        $this->assertSame('2026-11-20', $transition->payroll_month->toDateString());
        $this->assertSame('2026-12-18', $transition->period_end->toDateString());
        // Keep the already-final old run out of this regression: only the transition closes here.
        PayrollRun::withoutGlobalScopes()->whereKey($old->id)->update(['status' => 'Locked']);

        Carbon::setTestNow('2026-12-22 09:00:00'); // period end + 3 grace days + 1
        auth()->logout();
        $cmd = app(\App\Console\Commands\ClosePayrollPeriods::class);
        $cmd->setLaravel(app());
        $buf = new \Symfony\Component\Console\Output\BufferedOutput();
        $exit = $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--company' => $this->company->id]), $buf);

        $this->assertSame(0, $exit, $buf->fetch());
        $this->assertSame('Locked', $transition->fresh()->status);
        $this->assertNotNull(MonthlySummarySnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->whereDate('summary_month', '2026-12-01')->first(),
            'a transition run belongs to the month containing its period end');
        $this->assertNull(MonthlySummarySnapshot::withoutGlobalScopes()
            ->where('company_id', $this->company->id)->whereDate('summary_month', '2026-11-01')->first(),
            'the transition anchor date is not a monthly-summary key');
        Carbon::setTestNow();
    }
}
