<?php

namespace Tests\Feature;

use App\Http\Controllers\JobDataController;
use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, HrRequest, Leave, LeaveType, Penalty, RequestType, Role, SubscriptionPlan, User};
use App\Services\{EmployeePeriodMetrics, LeaveBalanceService, PayrollRunService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImmediateHrIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private User $user;
    private Company $company;
    private LeaveType $annual;
    private int $penaltyTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'integration-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'Integration', 'slug' => 'integration', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create(['name' => 'Manager', 'email' => 'integration@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        $this->employee = Employee::create(['employee_code' => 'INTEGRATION', 'first_name' => 'Test', 'full_name' => 'Integration Employee', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'manual_annual_balance' => 21, 'shift_start' => '09:00', 'shift_end' => '17:00']);
        $this->annual = LeaveType::create(['name' => 'Annual', 'policy_code' => 'annual', 'is_paid' => true, 'deducts_annual_balance' => true, 'is_active' => true, 'company_id' => $this->company->id]);
        $this->penaltyTypeId = \App\Models\PenaltyType::create(['name'=>'Test penalty','is_active'=>true,'company_id'=>$this->company->id])->id;
        foreach (['permission', 'mission', 'advance'] as $source) RequestType::create(['code' => $source, 'display_name' => $source, 'source_key' => $source, 'is_active' => true, 'company_id' => $this->company->id]);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function setting(string $key, string $value): void
    {
        DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function record(string $day, ?string $in = null, ?string $out = null, string $status = 'Absent', int $late = 0, int $early = 0): Attendance
    {
        return Attendance::create(['employee_id' => $this->employee->id, 'attendance_date' => $day, 'check_in' => $in, 'check_out' => $out, 'status' => $status, 'late_minutes' => $late, 'early_leave_minutes' => $early, 'approval_status' => 'Pending']);
    }

    private function request(string $source, array $payload, string $status = 'Pending'): HrRequest
    {
        return HrRequest::create(['employee_id' => $this->employee->id, 'request_type' => $source, 'request_type_id' => RequestType::where('code', $source)->value('id'), 'title' => $source, 'payload' => $payload, 'status' => $status, 'created_by' => $this->user->id]);
    }

    private function leave(string $day, string $status = 'Pending'): Leave
    {
        return Leave::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'start_date' => $day, 'end_date' => $day, 'days' => 1, 'status' => $status]);
    }

    private function daily(string $from = '2026-11-01', ?string $to = null): array
    {
        return app(EmployeePeriodMetrics::class)->calculate($this->employee->fresh(), Carbon::parse($from), Carbon::parse($to ?? $from), Attendance::effective()->get(), Leave::effective()->get(), HrRequest::effective()->with('requestType')->get());
    }

    private function row(): array
    {
        return (new \ReflectionMethod(JobDataController::class, 'buildMonthlyRows'))->invoke(app(JobDataController::class), collect([$this->employee->fresh()]), Carbon::parse('2026-11-01'))->first();
    }

    public function test_new_records_are_confirmed_without_an_approval_queue(): void
    {
        $this->assertSame('Approved', $this->record('2026-11-01')->approval_status);
        $this->assertSame('Approved', $this->leave('2026-11-01')->status);
        $this->assertSame('Approved', $this->request('permission', ['request_date' => '2026-11-01'])->status);
        $penalty = Penalty::create(['penalty_type_id'=>$this->penaltyTypeId, 'employee_id' => $this->employee->id, 'penalty_date' => '2026-11-01', 'title' => 'Test', 'reason' => 'Test', 'status' => 'Pending']);
        $this->assertSame('Approved', $penalty->status);
        $this->assertSame(0, app(\App\Services\UnifiedRequestService::class)->pendingCount($this->user));
    }

    public function test_legacy_pending_is_effective_in_summary_and_balance_without_rewriting_it(): void
    {
        $leave = $this->leave('2026-11-01');
        DB::table('leaves')->where('id', $leave->id)->update(['status' => 'Pending']);
        $this->assertSame(1.0, $this->row()['total_leave_days']);
        $this->assertSame(20.0, app(LeaveBalanceService::class)->balance($this->employee, now())['remaining']);
        $this->assertSame('Pending', $leave->fresh()->status);
        $this->assertSame(0.0, $this->daily()['absence_days']);
    }

    public function test_rejected_leave_request_and_penalty_do_not_count(): void
    {
        $this->leave('2026-11-01', 'Rejected');
        $this->request('advance', ['request_date' => '2026-11-01', 'amount' => 100], 'Rejected');
        Penalty::create(['penalty_type_id'=>$this->penaltyTypeId, 'employee_id' => $this->employee->id, 'penalty_date' => '2026-11-01', 'title' => 'Test', 'reason' => 'Test', 'deduction_days' => 1, 'status' => 'Rejected']);
        $row = $this->row();
        $this->assertSame(0.0, $row['total_leave_days']);
        $this->assertSame(0, $row['advance_request_count']);
        $this->assertSame(0, $row['penalty_count']);
        $this->assertSame(21.0, (float) $row['annual_balance_remaining']);
        $this->assertSame(1.0, $this->daily()['absence_days']);
    }

    public function test_missing_workday_counts_but_today_before_cutoff_and_future_do_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00', 'Africa/Cairo'));
        $this->assertSame(1.0, $this->daily('2026-11-01', '2026-11-05')['absence_days']);
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00', 'Africa/Cairo'));
        $this->assertSame(2.0, $this->daily('2026-11-01', '2026-11-05')['absence_days']);
    }

    public function test_hire_termination_rest_holiday_and_inactive_employees_limit_inferred_absence(): void
    {
        $this->employee->update(['hire_date' => '2026-11-02', 'termination_date' => '2026-11-02']);
        $this->assertSame(1.0, $this->daily('2026-11-01', '2026-11-05')['absence_days']);
        DB::table('hr_holidays')->insert(['company_id' => $this->company->id, 'holiday_date' => '2026-11-02', 'name' => 'Holiday', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0.0, $this->daily('2026-11-01', '2026-11-05')['absence_days']);
        $this->employee->update(['hire_date' => '2025-01-01', 'status_id' => EmployeeStatus::firstOrCreate(['name' => 'Suspended'])->id]);
        $this->assertSame(0.0, $this->daily()['absence_days']);
        $this->assertSame(0.0, $this->daily('2026-10-30')['absence_days']); // default Friday rest
    }

    public function test_absence_deduction_follows_configured_basis_and_allowance_selection(): void
    {
        // Contractual row: basic 6000, meal 500, transport 500, housing 600,
        // clothing 1000 (gross 8600). Confirmed rule: an absence day deducts
        // basic/30 + meal/30 + transport/30; housing and clothing are not
        // reduced by absence.
        $salary = new \App\Models\Salary([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id, 'effective_from' => '2026-11-01',
            'basic_salary' => 6000, 'meal_allowance' => 500, 'transportation_allowance' => 500,
            'housing_allowance' => 600, 'clothing_allowance' => 1000, 'other_allowances' => 0,
            'allowances' => 2600, 'gross_salary' => 8600, 'net_salary' => 8000, 'no_insurance' => true,
        ]);
        $rule = function (string $key, string $value): void {
            DB::table('payroll_calculation_rules')->updateOrInsert(
                ['company_id' => $this->company->id, 'rule_group' => 'absence', 'rule_key' => $key],
                ['rule_value' => $value, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
            );
        };
        $calculate = fn () => app(PayrollRunService::class)->calculate($salary, ['gross' => 8600], ['absence_days' => 1], false, 1.0);

        // Confirmed rule: basis Basic + meal,transportation marked absence-deducted
        // deducts (contractual basic + meal + transport)/30 per absent day.
        $rule('calculation_basis', 'Basic');
        $rule('deducted_allowances', 'meal,transportation');
        $this->assertEqualsWithDelta(233.33, $calculate()['absence_deduction'], 0.01); // (6000+500+500)/30

        // Only the configured allowances join the base; adding housing adds its daily share.
        $rule('deducted_allowances', 'meal,transportation,housing');
        $this->assertEqualsWithDelta(253.33, $calculate()['absence_deduction'], 0.01); // (6000+500+500+600)/30

        // Gross is unchanged and never double-deducts: every allowance is already
        // inside the gross, so the allowance selection adds nothing there.
        $rule('calculation_basis', 'Gross');
        $this->assertEqualsWithDelta(286.67, $calculate()['absence_deduction'], 0.01); // 8600/30

        // Basic with no allowance selected is the contractual basic alone - never
        // the engine's internal split (gross / 1.25 = 6880 here).
        $rule('calculation_basis', 'Basic');
        $rule('deducted_allowances', '');
        $this->assertEqualsWithDelta(200.00, $calculate()['absence_deduction'], 0.01); // 6000/30
    }

    public function test_rules_settings_save_accepts_the_allowance_basis_and_its_allowance_selection(): void
    {
        // Mimic the settings page load so the seeded rule rows exist, then start from
        // values the PUT must change so the assertions prove the write, not the defaults.
        app(\App\Services\PayrollDefaultsService::class)->ensureForCompany($this->company->id);
        DB::table('payroll_calculation_rules')->where('company_id', $this->company->id)->where('rule_group', 'absence')
            ->whereIn('rule_key', ['calculation_basis', 'deducted_allowances'])
            ->update(['rule_value' => 'Gross']);
        $rules = [
            'absence_basis' => 'Basic', 'penalty_basis' => 'Gross',
            'overtime_basis' => 'Gross', 'insurance_basis' => 'Gross',
        ];

        $this->put(route('payroll.settings.rules'), [
            'effective_from' => '2026-11-01', 'rules' => $rules,
            'absence_deducted_allowances' => ['meal', 'transportation'],
        ])->assertSessionHasNoErrors();

        $read = fn (string $key) => DB::table('payroll_calculation_rules')
            ->where('company_id', $this->company->id)->where('rule_group', 'absence')->where('rule_key', $key)->value('rule_value');
        $this->assertSame('Basic', $read('calculation_basis'));
        $this->assertSame('meal,transportation', $read('deducted_allowances'));
        // The change is audited with before/after snapshots.
        $change = DB::table('payroll_setting_changes')->where('company_id', $this->company->id)->latest('id')->first();
        $this->assertNotNull($change);
        $this->assertStringContainsString('meal,transportation', (string) $change->after_snapshot);

        // Only Gross and Basic are accepted as rule values, on every field.
        $this->put(route('payroll.settings.rules'), [
            'effective_from' => '2026-11-01',
            'rules' => array_merge($rules, ['absence_basis' => 'BasicPlusAllowances']),
        ])->assertSessionHasErrors('rules.absence_basis');
        $this->assertSame('Basic', $read('calculation_basis'));
    }

    public function test_leave_status_is_not_a_present_day_and_leave_excuses_explicit_absence(): void
    {
        $this->record('2026-11-01', null, null, 'On Leave');
        $this->leave('2026-11-01');
        $this->assertSame(0, $this->daily()['attendance_days']);
        $this->assertSame(0.0, $this->daily()['absence_days']);
    }

    public function test_timed_permission_only_excuses_the_matching_late_interval(): void
    {
        $this->record('2026-11-01', '10:00', '16:00', 'Late', 60, 60);
        $this->request('permission', ['request_date' => '2026-11-01', 'from_time' => '09:00', 'to_time' => '09:30']);
        $d = $this->daily();
        $this->assertSame(30, $d['late_minutes']);
        $this->assertSame(60, $d['early_leave_minutes']);
        $this->assertSame(1, $d['late_days']);
    }

    public function test_overlapping_permissions_are_merged_and_clipped(): void
    {
        $this->record('2026-11-01', '10:00', '16:00', 'Late', 60, 60);
        foreach ([['08:00','09:40'], ['09:20','10:00'], ['16:00','18:00']] as [$a,$b]) $this->request('permission', ['request_date'=>'2026-11-01','from_time'=>$a,'to_time'=>$b]);
        $d = $this->daily();
        $this->assertSame(0, $d['late_minutes']);
        $this->assertSame(0, $d['early_leave_minutes']);
        $this->assertSame(0, $d['late_days']);
        $this->assertSame(1, $d['attendance_days']);
    }

    public function test_untimed_legacy_permission_never_guesses_which_part_of_shift_is_excused(): void
    {
        $this->record('2026-11-01', '10:00', '17:00', 'Late', 60);
        $this->request('permission', ['request_date'=>'2026-11-01','hours'=>1]);
        $this->assertSame(60, $this->daily()['late_minutes']);
    }

    public function test_partial_permission_reduces_missing_day_proportionally_and_full_shift_removes_absence(): void
    {
        $this->request('permission', ['request_date'=>'2026-11-01','from_time'=>'09:00','to_time'=>'13:00']);
        $this->assertSame(0.5, $this->daily()['absence_days']);
        $this->request('permission', ['request_date'=>'2026-11-01','from_time'=>'13:00','to_time'=>'17:00']);
        $this->assertSame(0.0, $this->daily()['absence_days']);
    }

    public function test_mission_spanning_period_excuses_days_without_manufacturing_fingerprint_presence(): void
    {
        $this->record('2026-11-01');
        $this->request('mission', ['start_date'=>'2026-10-31','end_date'=>'2026-11-02']);
        $d = $this->daily('2026-11-01','2026-11-02');
        $this->assertSame(0.0, $d['absence_days']);
        $this->assertSame(0, $d['attendance_days']);
        $this->assertSame(1, $this->row()['mission_request_count']);
    }

    public function test_summary_and_payroll_share_missing_days_and_excuses(): void
    {
        $this->request('permission', ['request_date'=>'2026-11-01','from_time'=>'09:00','to_time'=>'13:00']);
        $this->request('mission', ['request_date'=>'2026-11-02']);
        $method = new \ReflectionMethod(PayrollRunService::class, 'approvedInputs');
        $inputs = $method->invoke(app(PayrollRunService::class), $this->employee, Carbon::parse('2026-11-01'), ['gross'=>10000], ['start'=>Carbon::parse('2026-11-01'), 'end'=>Carbon::parse('2026-11-30')]);
        $this->assertSame(0.5, (float) $inputs['absence_days']);
        $this->assertSame($this->row()['absence_days'], (float) $inputs['absence_days']);
    }

    public function test_new_leave_http_path_confirms_and_updates_balance_immediately(): void
    {
        $response = $this->postJson(route('leaves.store'), ['employee_id'=>$this->employee->id,'leave_type_id'=>$this->annual->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-01','reason'=>'Test']);
        $response->assertOk()->assertJsonPath('leave.status', 'Approved');
        $this->assertSame(20.0, app(LeaveBalanceService::class)->balance($this->employee, now())['remaining']);
    }

    public function test_leave_validation_failure_does_not_save_or_double_consume_balance(): void
    {
        $this->employee->update(['manual_annual_balance'=>1]);
        $this->postJson(route('leaves.store'), ['employee_id'=>$this->employee->id,'leave_type_id'=>$this->annual->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-02'])->assertUnprocessable();
        $this->assertSame(0, Leave::count());
        $this->postJson(route('leaves.store'), ['employee_id'=>$this->employee->id,'leave_type_id'=>$this->annual->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-01'])->assertOk();
        $this->assertEquals(0.0, app(LeaveBalanceService::class)->balance($this->employee, now())['remaining']);
    }

    public function test_permission_http_path_requires_times_and_allows_more_than_one_in_month(): void
    {
        $base = ['employee_id'=>$this->employee->id,'request_type_id'=>RequestType::where('code','permission')->value('id'),'title'=>'Permission','details'=>'Test','request_date'=>'2026-11-01'];
        $this->postJson(route('requests.store'),$base)->assertUnprocessable()->assertJsonValidationErrors(['from_time','to_time']);
        $this->postJson(route('requests.store'),$base+['from_time'=>'09:00','to_time'=>'10:00'])->assertOk()->assertJsonPath('request.status','Approved');
        $base['request_date']='2026-11-02';
        $this->postJson(route('requests.store'),$base+['from_time'=>'09:00','to_time'=>'10:00'])->assertOk();
        $this->assertSame(2,HrRequest::count());
    }

    public function test_saved_snapshot_is_not_overwritten_by_recalculation(): void
    {
        $controller=app(JobDataController::class);
        $snapshot=$controller->captureSnapshot(Carbon::parse('2026-11-01'));
        $original=$snapshot->rows;
        $this->leave('2026-11-01');
        $again=$controller->captureSnapshot(Carbon::parse('2026-11-01'));
        $this->assertSame($snapshot->id,$again->id);
        $this->assertSame($original,$again->rows);
    }
    public function test_unpaid_leave_and_absent_record_are_deducted_once_in_payroll(): void
    {
        $type = LeaveType::create(['name'=>'Unpaid','policy_code'=>'unpaid','is_paid'=>false,'is_active'=>true,'company_id'=>$this->company->id]);
        Leave::create(['employee_id'=>$this->employee->id,'leave_type_id'=>$type->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-01','days'=>1,'status'=>'Pending']);
        $this->record('2026-11-01');
        $method = new \ReflectionMethod(PayrollRunService::class, 'approvedInputs');
        $inputs = $method->invoke(app(PayrollRunService::class), $this->employee, Carbon::parse('2026-11-01'), ['gross'=>10000], ['start'=>Carbon::parse('2026-11-01'),'end'=>Carbon::parse('2026-11-01')]);
        $this->assertSame(1.0,(float)$inputs['absence_days']);
        $this->assertSame(0.0,$this->daily()['absence_days']);
    }

    public function test_night_shift_permission_matches_after_midnight_on_its_attendance_day(): void
    {
        $this->employee->update(['shift_start'=>'22:00','shift_end'=>'06:00']);
        $this->record('2026-11-01','22:00','05:00','Present',0,60);
        $this->request('permission',['request_date'=>'2026-11-01','from_time'=>'05:00','to_time'=>'06:00']);
        $this->assertSame(0,$this->daily()['early_leave_minutes']);
        $this->assertSame(1,$this->daily()['attendance_days']);
    }

    public function test_legacy_leave_attendance_status_is_excused_without_counting_presence(): void
    {
        $this->record('2026-11-01',null,null,'On Leave');
        $this->assertSame(0,$this->daily()['attendance_days']);
        $this->assertSame(0.0,$this->daily()['absence_days']);
    }

    public function test_finalized_payroll_rejects_source_writes_without_changing_snapshot(): void
    {
        $run=\App\Models\PayrollRun::create(['company_id'=>$this->company->id,'payroll_month'=>'2026-11-01','status'=>'Approved','period_start'=>'2026-10-23','period_end'=>'2026-11-22','settings_snapshot'=>['frozen'=>true]]);
        $this->postJson(route('leaves.store'),['employee_id'=>$this->employee->id,'leave_type_id'=>$this->annual->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-01'])->assertUnprocessable();
        $this->assertSame(0,Leave::count());
        $this->assertSame(['frozen'=>true],$run->fresh()->settings_snapshot);
        try { app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'),null,$this->company->id); $this->fail('Final run must not regenerate'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422,$e->getStatusCode()); }
    }

    public function test_export_and_current_summary_use_same_absence_result(): void
    {
        $this->request('mission',['request_date'=>'2026-11-02']);
        $expected=$this->row()['absence_days'];
        $response=$this->get(route('jobs.export',['month'=>'2026-11']));
        $response->assertOk()->assertSee('Integration Employee');
        $this->assertSame(1.0,$expected);
        $this->get(route('jobs.index',['month'=>'2026-11']))->assertOk()->assertSee('السجلات مؤكدة فور تسجيلها');
    }

    public function test_canceled_permission_never_erases_lateness(): void
    {
        $this->record('2026-11-01','10:00','17:00','Late',60);
        $this->request('permission',['request_date'=>'2026-11-01','from_time'=>'09:00','to_time'=>'10:00'],'Cancelled');
        $this->assertSame(60,$this->daily()['late_minutes']);
        $this->assertSame(0,$this->row()['permission_request_count']);
    }

    public function test_leave_transfer_checks_new_employee_balance_and_refreshes_both_balances(): void
    {
        $leave=$this->leave('2026-11-01');
        $other=Employee::create(['employee_code'=>'OTHER','first_name'=>'Other','full_name'=>'Other Employee','company_id'=>$this->company->id,'status_id'=>$this->employee->status_id,'hire_date'=>'2025-01-01','manual_annual_balance'=>0]);
        $data=['employee_id'=>$other->id,'leave_type_id'=>$this->annual->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-01'];
        $this->putJson(route('leaves.update',$leave),$data)->assertUnprocessable();
        $this->assertSame($this->employee->id,$leave->fresh()->employee_id);
        $other->update(['manual_annual_balance'=>5]);
        $this->putJson(route('leaves.update',$leave),$data)->assertOk();
        $this->assertSame(21.0,app(LeaveBalanceService::class)->balance($this->employee,now())['remaining']);
        $this->assertSame(4.0,app(LeaveBalanceService::class)->balance($other,now())['remaining']);
    }

    public function test_leave_cancel_and_delete_release_balance_immediately(): void
    {
        $leave=$this->leave('2026-11-01');
        $this->putJson(route('leaves.update',$leave),['employee_id'=>$this->employee->id,'leave_type_id'=>$this->annual->id,'start_date'=>'2026-11-01','end_date'=>'2026-11-01','status'=>'Rejected'])->assertOk();
        $this->assertSame(21.0,app(LeaveBalanceService::class)->balance($this->employee,now())['remaining']);
        $another=$this->leave('2026-11-02');
        $this->deleteJson(route('leaves.destroy',$another))->assertOk();
        $this->assertSame(21.0,app(LeaveBalanceService::class)->balance($this->employee,now())['remaining']);
        $this->assertSame(2.0,$this->row()['absence_days']);
    }

    public function test_form_prefixed_permission_fields_survive_hidden_overtime_fields(): void
    {
        $data=['employee_id'=>$this->employee->id,'request_type_id'=>RequestType::where('code','permission')->value('id'),'title'=>'Permission','details'=>'Test','request_date'=>'2026-11-01','permission_from_time'=>'09:00','permission_to_time'=>'10:00','from_time'=>null,'to_time'=>null];
        $this->postJson(route('requests.store'),$data)->assertOk();
        $request=HrRequest::firstOrFail();
        $this->assertSame('09:00',$request->payload['from_time']);
        $this->assertSame('10:00',$request->payload['to_time']);
        $this->putJson(route('requests.update',$request),['title'=>'Edit','details'=>'Test','request_date'=>'2026-11-01','permission_from_time'=>'09:15','permission_to_time'=>'10:30','from_time'=>null,'to_time'=>null])->assertOk();
        $this->assertSame('09:15',$request->fresh()->payload['from_time']);
        $this->assertSame('10:30',$request->fresh()->payload['to_time']);
    }

    public function test_permission_crossing_night_shift_start_is_clipped_not_shifted_a_day(): void
    {
        $this->employee->update(['shift_start'=>'22:00','shift_end'=>'06:00']);
        $this->record('2026-11-01','23:00','06:00','Late',60);
        $this->request('permission',['request_date'=>'2026-11-01','from_time'=>'21:00','to_time'=>'23:00']);
        $this->assertSame(0,$this->daily()['late_minutes']);
    }

    public function test_real_attendance_http_calculates_overnight_lateness_and_early_departure(): void
    {
        $this->employee->update(['shift_start'=>'22:00','shift_end'=>'06:00']);
        $base=['employee_id'=>$this->employee->id,'attendance_date'=>'2026-11-01','status'=>'Present'];
        $this->postJson(route('attendance.store'),$base+['check_in'=>'00:30','check_out'=>'05:00'])->assertOk();
        $record=Attendance::firstOrFail();
        $this->assertSame(150,$record->late_minutes);
        $this->assertSame(60,$record->early_leave_minutes);
        $this->assertSame(150,$this->daily()['late_minutes']);
        $this->request('permission',['request_date'=>'2026-11-01','from_time'=>'22:00','to_time'=>'00:30']);
        $this->assertSame(0,$this->daily()['late_minutes']);
        $this->postJson(route('attendance.store'),$base+['check_in'=>'22:00','check_out'=>'23:00'])->assertOk();
        $this->assertSame(420,Attendance::firstOrFail()->early_leave_minutes);
    }

    public function test_finalized_saved_range_overlap_is_checked_even_after_period_setting_changes(): void
    {
        \App\Models\PayrollRun::create(['company_id'=>$this->company->id,'payroll_month'=>'2026-11-01','status'=>'Approved','period_start'=>'2026-10-23','period_end'=>'2026-11-22']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Services\PayrollPeriodGuard::class)->ensureRangeOpen('2026-10-01','2026-10-30');
    }

    public function test_legacy_pending_cancellation_refreshes_open_draft_and_finalized_period_blocks_it(): void
    {
        $request=$this->request('mission',['request_date'=>'2026-11-01']);
        DB::table('hr_requests')->where('id',$request->id)->update(['status'=>'Pending']);
        $mock=$this->createMock(PayrollRunService::class);
        $mock->expects($this->once())->method('refreshEmployeeInOpenDrafts')->with($this->employee->id);
        $this->app->instance(PayrollRunService::class,$mock);
        $this->patchJson(route('requests.reject',$request),[])->assertOk();
        $this->assertSame('Rejected',$request->fresh()->status);
        $blocked=$this->request('permission',['request_date'=>'2026-11-02','from_time'=>'09:00','to_time'=>'10:00']);
        DB::table('hr_requests')->where('id',$blocked->id)->update(['status'=>'Pending']);
        \App\Models\PayrollRun::create(['company_id'=>$this->company->id,'payroll_month'=>'2026-11-01','status'=>'Approved','period_start'=>'2026-10-23','period_end'=>'2026-11-22']);
        $this->patchJson(route('requests.reject',$blocked),[])->assertUnprocessable();
        $this->assertSame('Pending',$blocked->fresh()->status);
    }

    public function test_fingerprint_upsert_calculates_metrics_before_summary_reads_it(): void
    {
        $method=new \ReflectionMethod(\App\Services\FingerprintAttendanceSyncService::class,'processPunch');
        $service=app(\App\Services\FingerprintAttendanceSyncService::class);
        $method->invoke($service,$this->employee,new \App\Data\FingerprintRawPunch('INTEGRATION',\Carbon\CarbonImmutable::parse('2026-11-01 10:00'),\App\Enums\RawAttendanceType::CheckIn));
        $method->invoke($service,$this->employee,new \App\Data\FingerprintRawPunch('INTEGRATION',\Carbon\CarbonImmutable::parse('2026-11-01 16:00'),\App\Enums\RawAttendanceType::CheckOut));
        $record=Attendance::firstOrFail();
        $this->assertSame(60,$record->late_minutes);
        $this->assertSame(60,$record->early_leave_minutes);
        $this->assertSame(60,$this->daily()['late_minutes']);
        $this->assertSame(60,$this->daily()['early_leave_minutes']);
    }

    public function test_fingerprint_overnight_punches_share_the_shift_attendance_date(): void
    {
        $this->employee->update(['shift_start'=>'22:00','shift_end'=>'06:00']);
        $method=new \ReflectionMethod(\App\Services\FingerprintAttendanceSyncService::class,'processPunch');
        $service=app(\App\Services\FingerprintAttendanceSyncService::class);
        $method->invoke($service,$this->employee,new \App\Data\FingerprintRawPunch('INTEGRATION',\Carbon\CarbonImmutable::parse('2026-11-01 22:00'),\App\Enums\RawAttendanceType::CheckIn));
        $method->invoke($service,$this->employee,new \App\Data\FingerprintRawPunch('INTEGRATION',\Carbon\CarbonImmutable::parse('2026-11-02 05:00'),\App\Enums\RawAttendanceType::CheckOut));
        $this->assertSame(1,Attendance::count());
        $record=Attendance::firstOrFail();
        $this->assertSame('2026-11-01',$record->attendance_date->toDateString());
        $this->assertSame(60,$record->early_leave_minutes);
        $this->assertSame(60,$this->daily()['early_leave_minutes']);
    }

    public function test_individually_approved_record_survives_fingerprint_refresh_and_stale_cleanup_in_draft_parent(): void
    {
        $salary=\App\Models\Salary::create(['employee_id'=>$this->employee->id,'effective_from'=>'2025-01-01','salary_type'=>'gross','gross_salary'=>10000,'basic_salary'=>5000,'allowances'=>5000,'net_salary'=>9000,'status'=>'Active']);
        $run=app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'),null,$this->company->id);
        $record=\App\Models\PayrollRecord::where('employee_id',$this->employee->id)->firstOrFail();
        $record->update(['status'=>'Approved','is_locked'=>false,'net_salary'=>8765.43,'calculation_snapshot'=>['frozen'=>'individual approval']]);
        $before=$record->fresh()->toArray();
        $method=new \ReflectionMethod(\App\Services\FingerprintAttendanceSyncService::class,'processPunch');
        $method->invoke(app(\App\Services\FingerprintAttendanceSyncService::class),$this->employee,new \App\Data\FingerprintRawPunch('INTEGRATION',\Carbon\CarbonImmutable::parse('2026-11-01 10:00'),\App\Enums\RawAttendanceType::CheckIn));
        $this->assertSame($before,$record->fresh()->toArray());
        $this->employee->update(['status_id'=>EmployeeStatus::firstOrCreate(['name'=>'Suspended'])->id]);
        app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'),null,$this->company->id);
        $this->assertSame($before,$record->fresh()->toArray());
        $this->assertSame('Draft',$run->fresh()->status);
    }

    public function test_locked_and_paid_individual_records_are_not_refreshed_or_stale_deleted(): void
    {
        foreach (['Locked','معتمدة','Paid'] as $status) {
            $run=\App\Models\PayrollRun::firstOrCreate(['company_id'=>$this->company->id,'payroll_month'=>'2026-11-01'],['status'=>'Draft']);
            $record=\App\Models\PayrollRecord::create(['employee_id'=>$this->employee->id,'payroll_run_id'=>$run->id,'payroll_month'=>'2026-11-01','status'=>$status,'is_locked'=>false,'net_salary'=>1234,'calculation_snapshot'=>['frozen'=>$status]]);
            $before=$record->fresh()->toArray();
            app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'),null,$this->company->id);
            $this->assertSame($before,$record->fresh()->toArray());
            $record->delete();
        }
    }

}
