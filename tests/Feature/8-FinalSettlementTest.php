<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, HrRequest, PayrollRecord, PayrollRun, Role, Salary, Settlement, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use App\Services\SettlementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinalSettlementTest extends TestCase
{
    use DatabaseTransactions;

    private EmployeeStatus $active;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'Africa/Cairo'));
        $this->active = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        EmployeeStatus::firstOrCreate(['name' => 'منتهي الخدمة']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function makeCompany(string $slug): Company
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'settle-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Manager', 'email' => $slug . '@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($user);
        return $company;
    }

    private function employee(Company $company, string $code): Employee
    {
        return Employee::create([
            'employee_code' => $code, 'first_name' => 'Set', 'full_name' => 'Set Employee '.$code, 'company_id' => $company->id,
            'status_id' => $this->active->id, 'hire_date' => '2024-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00',
            'manual_annual_balance' => 12,
        ]);
    }

    private function salary(Company $company, Employee $employee, float $amount = 6000): Salary
    {
        return Salary::create([
            'company_id' => $company->id, 'employee_id' => $employee->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => $amount,
            'basic_salary' => $amount, 'allowances' => 0,
            'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => $amount, 'net_salary' => $amount, 'no_insurance' => true, 'status' => 'Active',
        ]);
    }

    private function advance(Employee $employee, float $amount, string $date): HrRequest
    {
        return HrRequest::create([
            'employee_id' => $employee->id, 'request_type' => 'advance', 'title' => 'سلفة',
            'payload' => ['amount' => $amount, 'request_date' => $date],
        ]);
    }

    private function start(Employee $employee, string $lastDay = '2026-11-10'): Settlement
    {
        return app(SettlementService::class)->start($employee, [
            'reason' => 'resignation', 'last_working_day' => $lastDay,
        ]);
    }

    public function test_start_collects_period_leave_balance_and_outstanding_advances(): void
    {
        $company = $this->makeCompany('settle-a');
        $employee = $this->employee($company, 'SA1');
        $this->salary($company, $employee);
        $this->advance($employee, 3000, '2026-09-01');

        $settlement = $this->start($employee);

        $this->assertSame('Draft', $settlement->status);
        $this->assertSame('2026-11-01', $settlement->anchor_month->toDateString());
        $this->assertSame('2026-11-01', $settlement->period_start->toDateString());
        $this->assertSame('2026-11-30', $settlement->period_end->toDateString());
        $this->assertEqualsWithDelta(12.0, (float) $settlement->leave_balance_days, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $settlement->advance_outstanding, 0.01);
        $this->assertSame('pending', $settlement->leave_payout_mode);
        $this->assertSame('pending', $settlement->advance_recovery_mode);

        // One active settlement per employee.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->start($employee);
    }

    public function test_approval_is_blocked_until_leave_and_advance_decisions_are_made(): void
    {
        $company = $this->makeCompany('settle-b');
        $employee = $this->employee($company, 'SB1');
        $this->salary($company, $employee);
        $this->advance($employee, 1000, '2026-09-01');
        $settlement = $this->start($employee);

        try {
            app(SettlementService::class)->approve($settlement);
            $this->fail('approval must be blocked while decisions are pending');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        // The employee must not be terminated by a blocked approval.
        $this->assertSame($this->active->id, $employee->fresh()->status_id);
    }

    public function test_approval_terminates_employee_and_folds_settlement_into_the_draft_run(): void
    {
        $company = $this->makeCompany('settle-c');
        $employee = $this->employee($company, 'SC1');
        $this->salary($company, $employee);
        $this->advance($employee, 3000, '2026-09-01');
        $settlement = $this->start($employee);

        app(SettlementService::class)->updateDraft($settlement, [
            'leave_payout_mode' => 'basic',
            'advance_recovery_mode' => 'full',
            'gratuity_amount' => 500,
        ]);
        $settlement = app(SettlementService::class)->approve($settlement->fresh());

        // Employee terminated with the settlement's reason and last working day.
        $fresh = $employee->fresh();
        $this->assertSame('منتهي الخدمة', $fresh->status?->name);
        $this->assertSame('resignation', $fresh->termination_type);
        $this->assertSame('2026-11-10', $fresh->termination_date->toDateString());

        // Amounts: leave payout 12 days x 6000/30 = 2400; gratuity 500.
        $this->assertEqualsWithDelta(2400.0, (float) $settlement->leave_payout_amount, 0.01);
        $this->assertEqualsWithDelta(2900.0, (float) $settlement->total_earnings, 0.01);
        $this->assertSame('draft_run', $settlement->reconciliation);

        // The payroll record carries the settlement lines and the salary accrued
        // through today (the current period pays 1-7 Nov = 7/30; the window clamp
        // at today is existing payroll behavior, unchanged by the settlement).
        $record = PayrollRecord::withoutGlobalScopes()->findOrFail($settlement->payroll_record_id);
        $this->assertEqualsWithDelta(1400.0, (float) $record->basic_salary, 0.01);
        $this->assertEqualsWithDelta(2900.0, (float) $record->other_earnings, 0.01);
        $this->assertSame($settlement->id, (int) data_get($record->calculation_snapshot, 'settlement.id'));

        // Full recovery: scheduled installment + settlement recovery clear the whole 3000.
        $details = (array) data_get($record->calculation_snapshot, 'advance_details', []);
        $this->assertNotEmpty($details);
        $this->assertEqualsWithDelta(3000.0, (float) $record->loans, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) collect($details)->sum('remaining_after'), 0.01);
        $this->assertGreaterThan(0, (float) $settlement->advance_recovery_amount);
        $this->assertEqualsWithDelta(
            (float) $settlement->advance_recovery_amount,
            (float) collect($settlement->advance_recovery_details)->sum('amount'),
            0.01
        );
    }

    public function test_regenerating_the_draft_month_neither_drops_nor_doubles_the_settlement(): void
    {
        $company = $this->makeCompany('settle-d');
        $employee = $this->employee($company, 'SD1');
        $this->salary($company, $employee);
        $this->advance($employee, 3000, '2026-09-01');
        $settlement = $this->start($employee);
        app(SettlementService::class)->updateDraft($settlement, [
            'leave_payout_mode' => 'basic', 'advance_recovery_mode' => 'full', 'gratuity_amount' => 500,
        ]);
        $settlement = app(SettlementService::class)->approve($settlement->fresh());
        $before = PayrollRecord::withoutGlobalScopes()->findOrFail($settlement->payroll_record_id);

        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), [$employee->id], $company->id);
        $after = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

        $this->assertEqualsWithDelta((float) $before->other_earnings, (float) $after->other_earnings, 0.01);
        $this->assertEqualsWithDelta((float) $before->loans, (float) $after->loans, 0.01);
        $this->assertSame($settlement->id, (int) data_get($after->calculation_snapshot, 'settlement.id'));
        $this->assertSame($run->id, $settlement->fresh()->payroll_run_id);
    }

    public function test_later_runs_never_deduct_a_settlement_recovered_advance_again(): void
    {
        $company = $this->makeCompany('settle-e');
        $employee = $this->employee($company, 'SE1');
        $this->salary($company, $employee);
        $this->advance($employee, 3000, '2026-09-01');
        $settlement = $this->start($employee);
        app(SettlementService::class)->updateDraft($settlement, [
            'leave_payout_mode' => 'skip', 'advance_recovery_mode' => 'full',
        ]);
        $settlement = app(SettlementService::class)->approve($settlement->fresh());
        app(SettlementService::class)->markPaid($settlement);

        // December run: the advance is fully recovered, nothing left to deduct.
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $company->id);
        $record = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) ($record?->loans ?? 0), 0.01);
    }

    public function test_approved_run_forces_standalone_reconciliation_without_touching_payroll(): void
    {
        $company = $this->makeCompany('settle-f');
        $employee = $this->employee($company, 'SF1');
        $this->salary($company, $employee);
        $this->advance($employee, 2000, '2026-09-01');

        // Generate and approve November BEFORE the settlement starts.
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $company->id);
        $recordBefore = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $loansBefore = (float) $recordBefore->loans;
        $earningsBefore = (float) $recordBefore->other_earnings;
        $run->forceFill(['status' => 'Approved'])->save();

        $settlement = $this->start($employee);
        app(SettlementService::class)->updateDraft($settlement, [
            'leave_payout_mode' => 'gross', 'advance_recovery_mode' => 'full', 'gratuity_amount' => 0,
        ]);
        $settlement = app(SettlementService::class)->approve($settlement->fresh());

        $this->assertSame('standalone', $settlement->reconciliation);

        // The approved record is untouched: same loans, same earnings, no settlement tag.
        $recordAfter = $recordBefore->fresh();
        $this->assertEqualsWithDelta($loansBefore, (float) $recordAfter->loans, 0.01);
        $this->assertEqualsWithDelta($earningsBefore, (float) $recordAfter->other_earnings, 0.01);
        $this->assertNull(data_get($recordAfter->calculation_snapshot, 'settlement'));

        // The recovery still lands on the settlement itself, and later runs respect it.
        $this->assertGreaterThan(0, (float) $settlement->advance_recovery_amount);
        $this->assertNotEmpty($settlement->advance_recovery_details);
        $next = app(PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $company->id);
        $nextRecord = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $next->id)->where('employee_id', $employee->id)->first();
        $this->assertEqualsWithDelta(0.0, (float) ($nextRecord?->loans ?? 0), 0.01);
    }

    public function test_cancel_is_only_allowed_from_draft_and_paid_is_final(): void
    {
        $company = $this->makeCompany('settle-g');
        $employee = $this->employee($company, 'SG1');
        $this->salary($company, $employee);
        $settlement = $this->start($employee);

        app(SettlementService::class)->cancel($settlement);
        $this->assertSame('Canceled', $settlement->fresh()->status);
        // Employee untouched by a canceled draft.
        $this->assertSame($this->active->id, $employee->fresh()->status_id);

        $settlement2 = $this->start($employee);
        app(SettlementService::class)->updateDraft($settlement2, ['leave_payout_mode' => 'basic', 'advance_recovery_mode' => 'scheduled']);
        $settlement2 = app(SettlementService::class)->approve($settlement2->fresh());

        try {
            app(SettlementService::class)->cancel($settlement2);
            $this->fail('approved settlements cannot be canceled');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $settlement2 = app(SettlementService::class)->markPaid($settlement2->fresh());
        $this->assertSame('Paid', $settlement2->status);

        foreach (['markPaid', 'cancel'] as $method) {
            try {
                app(SettlementService::class)->{$method}($settlement2->fresh());
                $this->fail($method.' must not run on a paid settlement');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_terminated_employee_stays_in_departure_period_and_structure_excluded_next_period(): void
    {
        $company = $this->makeCompany('settle-i');
        $employee = $this->employee($company, 'SI1');
        $salary = $this->salary($company, $employee);
        $settlement = $this->start($employee);
        app(SettlementService::class)->updateDraft($settlement, [
            'leave_payout_mode' => 'basic', 'advance_recovery_mode' => 'scheduled',
        ]);
        $settlement = app(SettlementService::class)->approve($settlement->fresh());

        // Same month, FULL regeneration: the terminated employee stays in the payroll
        // (paid through the last working day) and keeps the settlement lines.
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $company->id);
        $record = PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->first();
        $this->assertNotNull($record, 'terminated employee must remain in the departure-period payroll');
        $this->assertGreaterThan(0, (float) $record->basic_salary, 'departure period still pays through the last working day');
        $this->assertSame($settlement->id, (int) data_get($record->calculation_snapshot, 'settlement.id'));

        // Salary structure is preserved by the settlement flow.
        $this->assertSame('Active', $salary->fresh()->status);

        // Next period: the employee is no longer payable at all.
        $next = app(PayrollRunService::class)->generate(Carbon::parse('2026-12-01'), null, $company->id);
        $this->assertNull(
            PayrollRecord::withoutGlobalScopes()->where('payroll_run_id', $next->id)->where('employee_id', $employee->id)->first(),
            'terminated employee must not enter the period after departure'
        );
    }

    public function test_zero_balance_and_zero_advances_need_no_decisions(): void
    {
        $company = $this->makeCompany('settle-h');
        $employee = Employee::create([
            'employee_code' => 'SH1', 'first_name' => 'No', 'full_name' => 'No Balance', 'company_id' => $company->id,
            'status_id' => $this->active->id, 'hire_date' => '2024-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00',
            'manual_annual_balance' => 0,
        ]);
        $this->salary($company, $employee);

        $settlement = $this->start($employee);
        $this->assertSame('skip', $settlement->leave_payout_mode);
        $this->assertSame('scheduled', $settlement->advance_recovery_mode);
        $this->assertSame([], $settlement->pendingDecisions());

        $settlement = app(SettlementService::class)->approve($settlement);
        $this->assertSame('Approved', $settlement->status);
        $this->assertSame('draft_run', $settlement->reconciliation);
    }
}
