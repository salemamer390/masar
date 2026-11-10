<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\{EmployeeLeaveStatusService, EmployeePeriodMetrics, PayrollDefaultsService, PregnancyNursingRightsService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 (Amer's 2026-11-09 answers): the «اجازة وضع» guided status action
 * creates the maternity leave from the expected delivery date; nursing (m56)
 * and pregnancy hour reduction (m54) offset lateness/early departure; the
 * overtime ban blocks requests during pregnancy and the postpartum months.
 */
class LeaveLegalPhaseTwoTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company; private User $user; private EmployeeStatus $active; private LeaveType $maternity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->active = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        EmployeeStatus::firstOrCreate(['name' => 'Leave']);
        EmployeeStatus::firstOrCreate(['name' => 'اجازة وضع']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'p2'], ['name' => 'T', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'p2', 'slug' => 'p2', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'M', 'company_id' => $this->company->id]);
        $this->user = User::create(['name' => 'M', 'email' => 'p2@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        app(PayrollDefaultsService::class)->ensureForCompany($this->company->id);
        $id = DB::table('leave_types')->insertGetId(['company_id' => null, 'is_active' => 1, 'default_days' => 120, 'name' => 'وضع تجريبي', 'policy_code' => 'maternity_t', 'is_paid' => 1, 'deducts_annual_balance' => 0, 'max_days_per_request' => 120, 'max_occurrences_service' => 3, 'requires_document' => 0, 'min_service_months' => 0]);
        DB::table('leave_types')->where('id', $id)->update(['policy_code' => 'maternity']);
        $this->maternity = LeaveType::withoutGlobalScopes()->find($id);
        $this->actingAs($this->user);
    }

    private function employee(string $code = 'E1'): Employee
    {
        return Employee::create(['employee_code' => $code, 'first_name' => 'E', 'full_name' => 'Emp '.$code, 'company_id' => $this->company->id, 'status_id' => $this->active->id, 'hire_date' => '2020-01-01', 'gender' => 'أنثى']);
    }

    private function maternityLeaveFor(Employee $e, array $attrs = []): Leave
    {
        return Leave::create(array_merge([
            'employee_id' => $e->id, 'leave_type_id' => $this->maternity->id,
            'start_date' => today()->subMonths(2)->toDateString(), 'end_date' => today()->addMonths(2)->toDateString(),
            'days' => 120, 'status' => 'Approved', 'company_id' => $this->company->id,
        ], $attrs));
    }

    private function rights(): PregnancyNursingRightsService
    {
        $service = app(PregnancyNursingRightsService::class);
        return $service;
    }

    public function test_guided_action_creates_leave_and_sets_status(): void
    {
        $e = $this->employee();
        $expected = today()->addMonths(2)->startOfDay();
        $response = $this->postJson(route('employees.maternity-leave', $e), ['expected_delivery_date' => $expected->toDateString()]);
        $response->assertOk()->assertJson(['success' => true]);
        $leave = Leave::where('employee_id', $e->id)->first();
        $this->assertNotNull($leave);
        $this->assertSame($expected->toDateString(), $leave->expected_delivery_date->toDateString());
        // Start defaults to the registration day; the duration is the policy's
        // calendar months from that start, like every leave on the leaves page.
        $this->assertSame(today()->toDateString(), $leave->start_date->toDateString());
        $this->assertSame(today()->addMonthsNoOverflow(4)->subDay()->toDateString(), $leave->end_date->toDateString());
        $this->assertSame('Approved', $leave->status);
        $e->refresh();
        $this->assertSame('اجازة وضع', $e->status?->name);
        $this->assertSame($this->active->id, (int) $e->pre_leave_status_id);
    }

    public function test_future_start_keeps_working_status_until_leave_begins(): void
    {
        $e = $this->employee();
        // Due in about 5.5 months, starting one month before the due date:
        // the 4-month window easily covers 45 days after the expected date.
        $expected = today()->addMonths(5)->addDays(15)->startOfDay();
        $start = $expected->copy()->subMonth();
        $this->postJson(route('employees.maternity-leave', $e), [
            'expected_delivery_date' => $expected->toDateString(),
            'start_date' => $start->toDateString(),
        ])->assertOk()->assertJson(['success' => true]);
        // Still working: the status must NOT move before the leave begins.
        $this->assertSame($this->active->id, (int) $e->fresh()->status_id);
        $this->assertNull($e->fresh()->pre_leave_status_id);
        // On the start day the daily sync moves her to the maternity status.
        app(EmployeeLeaveStatusService::class)->sync($start);
        $this->assertSame('اجازة وضع', $e->fresh()->status?->name);
        $this->assertSame($this->active->id, (int) $e->fresh()->pre_leave_status_id);
    }

    public function test_start_too_early_for_the_due_date_fails_clearly(): void
    {
        $e = $this->employee();
        // Due 6 months ahead: a 4-month leave starting today can never cover 45
        // days after the expected delivery, so it must be rejected, not silent.
        $this->postJson(route('employees.maternity-leave', $e), ['expected_delivery_date' => today()->addMonths(6)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->assertDatabaseMissing('leaves', ['employee_id' => $e->id]);
        $this->assertSame($this->active->id, (int) $e->fresh()->status_id);
    }

    public function test_guided_action_requires_document_when_type_does(): void
    {
        DB::table('leave_types')->where('id', $this->maternity->id)->update(['requires_document' => 1]);
        $e = $this->employee();
        $this->postJson(route('employees.maternity-leave', $e), ['expected_delivery_date' => today()->addMonth()->toDateString()])
            ->assertStatus(422);
        $this->assertDatabaseMissing('leaves', ['employee_id' => $e->id]);
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->postJson(route('employees.maternity-leave', $e), [
            'expected_delivery_date' => today()->addMonth()->toDateString(),
            'document' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull(Leave::where('employee_id', $e->id)->first()?->document_path);
    }

    public function test_bare_status_edit_into_maternity_is_rejected(): void
    {
        $e = $this->employee();
        $statusId = EmployeeStatus::where('name', 'اجازة وضع')->value('id');
        $this->putJson(route('employees.update', $e), ['full_name' => $e->full_name, 'status_id' => $statusId])
            ->assertStatus(422);
        $this->assertSame($this->active->id, (int) $e->fresh()->status_id);
    }

    public function test_status_restores_after_leave_ends(): void
    {
        $e = $this->employee();
        $this->postJson(route('employees.maternity-leave', $e), ['expected_delivery_date' => today()->addMonth()->toDateString()])->assertOk();
        $leave = Leave::where('employee_id', $e->id)->first();
        $leave->update(['start_date' => today()->subMonths(5)->toDateString(), 'end_date' => today()->subDay()->toDateString()]);
        app(EmployeeLeaveStatusService::class)->sync(today());
        $e->refresh();
        $this->assertSame($this->active->id, (int) $e->status_id);
        $this->assertNull($e->pre_leave_status_id);
    }

    public function test_nursing_allowance_offsets_lateness_and_early_departure(): void
    {
        $e = $this->employee();
        // Policy defaults: 2 sessions x 30 minutes = 60 minutes/day, birth 100 days ago.
        $this->maternityLeaveFor($e, ['birth_date' => today()->subDays(100)->toDateString()]);
        $day = today();
        Attendance::create(['employee_id' => $e->id, 'company_id' => $this->company->id, 'attendance_date' => $day->toDateString(), 'check_in' => '10:30', 'check_out' => '16:00', 'status' => 'Late', 'late_minutes' => 90, 'early_leave_minutes' => 60]);
        $metrics = app(EmployeePeriodMetrics::class)->days($e->fresh(), $day->copy(), $day->copy(), Attendance::where('employee_id', $e->id)->get(), collect(), collect());
        // 60 minutes offset: late 90-60=30 first, early fully covered by nothing left.
        $this->assertSame(30, $metrics[0]['late_minutes']);
        $this->assertSame(60, $metrics[0]['early_leave_minutes']);
    }

    public function test_early_actual_birth_supersedes_the_expected_date(): void
    {
        // Birth recorded before the expected date: pregnancy rules stop at the
        // actual birth even though the expected date is still in the future.
        $e = $this->employee();
        $this->maternityLeaveFor($e, [
            'expected_delivery_date' => today()->addMonth()->toDateString(),
            'birth_date' => today()->subDays(3)->toDateString(),
            'pregnancy_reduction_start' => today()->subMonths(2)->toDateString(),
            'start_date' => today()->subMonths(2)->toDateString(),
            'end_date' => today()->addMonths(2)->toDateString(),
        ]);
        // After the recorded birth: no pregnancy reduction, postpartum ban.
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        $this->assertStringContainsString('بعد الوضع', $this->rights()->overtimeBlockReason($e->fresh(), today()) ?? '');
        // Before the recorded birth (expected date still ahead): reduction applied.
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()->subDays(4)));
        // And nursing runs from the actual birth.
        $this->assertSame(60, $this->rights()->nursingAllowanceMinutes($e->fresh(), today()));
    }

    public function test_late_actual_birth_revalidates_the_postpartum_minimum(): void
    {
        $e = $this->employee();
        // End exactly 45 days after the EXPECTED date: valid as planned.
        $expected = today()->addMonth();
        $leave = $this->maternityLeaveFor($e, [
            'expected_delivery_date' => $expected->toDateString(),
            'start_date' => today()->toDateString(),
            'end_date' => $expected->copy()->addDays(45)->toDateString(),
        ]);
        // Actual birth 20 days after the expected date leaves only 25
        // postpartum days inside the window: the update must be refused until
        // HR extends the leave end to cover 45 days after the actual birth.
        $lateBirth = $expected->copy()->addDays(20);
        $this->putJson(route('leaves.update', $leave), [
            'employee_id' => $e->id,
            'leave_type_id' => $this->maternity->id,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(),
            'birth_date' => $lateBirth->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors(['end_date']);
        $this->assertNull($leave->fresh()->birth_date);
        // Extending the end to 45 days after the actual birth passes.
        $this->putJson(route('leaves.update', $leave), [
            'employee_id' => $e->id,
            'leave_type_id' => $this->maternity->id,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $lateBirth->copy()->addDays(45)->toDateString(),
            'birth_date' => $lateBirth->toDateString(),
        ])->assertOk();
        $this->assertSame($lateBirth->toDateString(), $leave->fresh()->birth_date->toDateString());
    }

    public function test_certificate_reduction_start_activates_the_reduction(): void
    {
        $e = $this->employee();
        // The certificate's recorded reduction start already began: it governs
        // even while the expected date is far ahead.
        $this->maternityLeaveFor($e, [
            'expected_delivery_date' => today()->addMonths(5)->toDateString(),
            'pregnancy_reduction_start' => today()->subDays(10)->toDateString(),
            'start_date' => today()->subMonths(2)->toDateString(),
            'end_date' => today()->addMonths(6)->toDateString(),
        ]);
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        // Without the certificate field the same expected date yields nothing.
        $e2 = $this->employee('E2');
        $this->maternityLeaveFor($e2, [
            'expected_delivery_date' => today()->addMonths(5)->toDateString(),
            'start_date' => today()->subMonths(2)->toDateString(),
            'end_date' => today()->addMonths(6)->toDateString(),
        ]);
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e2->fresh(), today()));
    }

    public function test_nursing_allowance_expires_two_years_after_birth(): void
    {
        $e = $this->employee();
        $this->maternityLeaveFor($e, ['birth_date' => today()->subYears(2)->subDay()->toDateString(), 'start_date' => today()->subYears(2)->subMonths(3)->toDateString(), 'end_date' => today()->subYears(2)->subMonths(2)->toDateString()]);
        $this->assertSame(0, $this->rights()->nursingAllowanceMinutes($e->fresh(), today()));
        $e2 = $this->employee('E2');
        $this->maternityLeaveFor($e2, ['birth_date' => today()->subYears(2)->addDays(3)->toDateString(), 'start_date' => today()->subYears(2)->subMonths(2)->toDateString(), 'end_date' => today()->subYears(2)->toDateString()]);
        $this->assertSame(60, $this->rights()->nursingAllowanceMinutes($e2->fresh(), today()));
    }

    public function test_nursing_window_months_is_company_adjustable(): void
    {
        // Default law window: 24 months from the recorded birth.
        $e = $this->employee();
        $this->maternityLeaveFor($e, ['birth_date' => today()->subMonths(25)->toDateString()]);
        $this->assertSame(0, $this->rights()->nursingAllowanceMinutes($e->fresh(), today()));

        // A company may extend the window for itself (art. 56 sets a minimum).
        \Illuminate\Support\Facades\DB::table('leave_policy_settings')->updateOrInsert(
            ['company_id' => $this->company->id, 'setting_key' => 'nursing_window_months'],
            ['setting_value' => '30', 'created_at' => now(), 'updated_at' => now()]
        );
        $e2 = $this->employee('E2');
        $this->maternityLeaveFor($e2, ['birth_date' => today()->subMonths(25)->toDateString()]);
        $this->assertSame(60, app(\App\Services\PregnancyNursingRightsService::class)->nursingAllowanceMinutes($e2->fresh(), today()));
    }

    public function test_pregnancy_reduction_requires_the_certificate_start_date(): void
    {
        // The expected delivery date alone never activates the paid reduction:
        // a pregnancy month computed from it is an estimate, not eligibility.
        $e = $this->employee();
        $this->maternityLeaveFor($e, ['expected_delivery_date' => today()->addMonths(2)->toDateString()]);
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        // With the certificate's reduction start recorded, it applies until delivery.
        $e2 = $this->employee('E2');
        $this->maternityLeaveFor($e2, ['expected_delivery_date' => today()->addMonths(2)->toDateString(), 'pregnancy_reduction_start' => today()->subDays(5)->toDateString()]);
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e2->fresh(), today()));
        // Before the certificate start: not yet.
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e2->fresh(), today()->subDays(6)));
        // After the expected date with no recorded birth: it stops.
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e2->fresh(), today()->addMonths(2)->addDay()));
    }

    public function test_overtime_blocked_during_pregnancy_and_postpartum(): void
    {
        $e = $this->employee();
        $this->maternityLeaveFor($e, ['expected_delivery_date' => today()->addMonth()->toDateString()]);
        $this->assertNotNull($this->rights()->overtimeBlockReason($e->fresh(), today()));
        // After the recorded birth: postpartum ban for the configured 6 months.
        $leave = Leave::where('employee_id', $e->id)->first();
        $leave->update(['birth_date' => today()->subMonths(2)->toDateString()]);
        $freshService = app(PregnancyNursingRightsService::class);
        $this->assertNotNull($freshService->overtimeBlockReason($e->fresh(), today()));
        $leave->update(['birth_date' => today()->subMonths(7)->toDateString()]);
        $this->assertNull(app(PregnancyNursingRightsService::class)->overtimeBlockReason($e->fresh(), today()));
    }

    public function test_employee_record_pregnancy_rights_without_leave(): void
    {
        // HR records the certificate dates on the employee file while she keeps
        // working - no leave, no status change - and the rights apply (Amer's
        // approved 22:32 flow).
        $e = $this->employee();
        $e->update([
            'expected_delivery_date' => today()->addMonths(3)->toDateString(),
            'pregnancy_reduction_start' => today()->subDays(10)->toDateString(),
        ]);
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()->subDays(11)));
        $reason = $this->rights()->overtimeBlockReason($e->fresh(), today());
        $this->assertNotNull($reason);
        $this->assertStringContainsString('الحمل', $reason);
        // Without the certificate reduction start, the expected date alone pays nothing.
        $e2 = $this->employee('E2');
        $e2->update(['expected_delivery_date' => today()->addMonths(3)->toDateString()]);
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e2->fresh(), today()));
    }

    public function test_guided_action_carries_and_clears_employee_pregnancy_dates(): void
    {
        $e = $this->employee();
        $expected = today()->addMonths(2);
        $e->update([
            'expected_delivery_date' => $expected->toDateString(),
            'pregnancy_reduction_start' => today()->subMonth()->toDateString(),
        ]);
        // No date in the request: the guided action uses the employee file.
        $this->postJson(route('employees.maternity-leave', $e), [])->assertOk()->assertJson(['success' => true]);
        $leave = Leave::where('employee_id', $e->id)->first();
        $this->assertSame($expected->toDateString(), $leave->expected_delivery_date->toDateString());
        $this->assertSame(today()->subMonth()->toDateString(), $leave->pregnancy_reduction_start->toDateString());
        // Cleared from the employee file: stale data cannot re-activate later.
        $this->assertNull($e->fresh()->expected_delivery_date);
        $this->assertNull($e->fresh()->pregnancy_reduction_start);
    }

    public function test_stale_employee_dates_do_not_reactivate_rights_after_birth(): void
    {
        $e = $this->employee();
        // Birth 7 months ago; stale employee-file dates predate the birth.
        $this->maternityLeaveFor($e, [
            'expected_delivery_date' => today()->subMonths(8)->toDateString(),
            'birth_date' => today()->subMonths(7)->toDateString(),
            'pregnancy_reduction_start' => today()->subMonths(10)->toDateString(),
            'start_date' => today()->subMonths(9)->toDateString(),
            'end_date' => today()->subMonths(5)->toDateString(),
        ]);
        $e->update([
            'expected_delivery_date' => today()->subMonths(8)->toDateString(),
            'pregnancy_reduction_start' => today()->subMonths(10)->toDateString(),
        ]);
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        $this->assertNull($this->rights()->overtimeBlockReason($e->fresh(), today()));
    }

    public function test_new_pregnancy_after_birth_reads_the_employee_file(): void
    {
        $e = $this->employee();
        // Previous maternity a year ago, nursing window still open but the new
        // pregnancy (expected date after the recorded birth) governs pregnancy.
        $this->maternityLeaveFor($e, [
            'expected_delivery_date' => today()->subYears(1)->subMonth()->toDateString(),
            'birth_date' => today()->subYear()->toDateString(),
            'start_date' => today()->subYears(1)->subMonths(2)->toDateString(),
            'end_date' => today()->subMonths(10)->toDateString(),
        ]);
        $e->update([
            'expected_delivery_date' => today()->addMonths(3)->toDateString(),
            'pregnancy_reduction_start' => today()->subDays(2)->toDateString(),
        ]);
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        $this->assertStringContainsString('الحمل', $this->rights()->overtimeBlockReason($e->fresh(), today()) ?? '');
        // Nursing from the first birth still runs in parallel.
        $this->assertSame(60, $this->rights()->nursingAllowanceMinutes($e->fresh(), today()));
    }

    public function test_overtime_ban_expires_after_the_protection_window(): void
    {
        $e = $this->employee();
        // Birth 7 months ago: the 6-month postpartum ban has expired, and the
        // pregnancy ban must not re-activate from the stale expected date.
        $this->maternityLeaveFor($e, [
            'expected_delivery_date' => today()->subMonths(6)->toDateString(),
            'birth_date' => today()->subMonths(7)->toDateString(),
            'start_date' => today()->subMonths(8)->toDateString(),
            'end_date' => today()->subMonths(4)->toDateString(),
        ]);
        $this->assertNull($this->rights()->overtimeBlockReason($e->fresh(), today()));
        // And the pregnancy reduction is long over.
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
    }

    public function test_overtime_request_http_blocked_for_pregnant_employee(): void
    {
        $e = $this->employee();
        $this->maternityLeaveFor($e, ['expected_delivery_date' => today()->addMonth()->toDateString()]);
        $typeId = DB::table('request_types')->where('source_key', 'overtime')->value('id')
            ?? DB::table('request_types')->where('code', 'overtime')->value('id');
        $this->assertNotNull($typeId, 'overtime request type must exist');
        $this->postJson(route('requests.store'), [
            'employee_id' => $e->id,
            'request_type_id' => $typeId,
            'title' => 'إضافي',
            'details' => 'طلب إضافي',
            'overtime_request_date' => today()->toDateString(),
            'overtime_period' => 'day',
            'from_time' => '18:00',
            'to_time' => '20:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['overtime_request_date']);
        // An employee with no pregnancy dates passes the art. 54 check.
        $e2 = $this->employee('E2');
        $this->postJson(route('requests.store'), [
            'employee_id' => $e2->id,
            'request_type_id' => $typeId,
            'title' => 'إضافي',
            'details' => 'طلب إضافي',
            'overtime_request_date' => today()->toDateString(),
            'overtime_period' => 'day',
            'from_time' => '18:00',
            'to_time' => '20:00',
        ])->assertJsonMissingValidationErrors(['overtime_request_date']);
    }

    public function test_maternity_validation_accepts_expected_date_without_birth(): void
    {
        $e = $this->employee();
        $expected = today()->addMonth();
        $leave = new Leave([
            'employee_id' => $e->id, 'leave_type_id' => $this->maternity->id,
            'start_date' => $expected->copy()->addDays(45)->subMonthsNoOverflow(4)->addDay()->toDateString(),
            'end_date' => $expected->copy()->addDays(45)->toDateString(),
            'days' => 90, 'status' => 'Approved',
            'expected_delivery_date' => $expected->toDateString(),
        ]);
        app(\App\Services\LeaveBalanceService::class)->validateForApproval($leave);
        $this->assertTrue(true); // no exception: the plan satisfies the postpartum check
        // Ending before 45 days after the expected date still fails.
        $short = new Leave([
            'employee_id' => $e->id, 'leave_type_id' => $this->maternity->id,
            'start_date' => $expected->copy()->subMonthsNoOverflow(3)->toDateString(),
            'end_date' => $expected->copy()->addDays(20)->toDateString(),
            'days' => 80, 'status' => 'Approved',
            'expected_delivery_date' => $expected->toDateString(),
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\LeaveBalanceService::class)->validateForApproval($short);
    }

    public function test_rights_settings_are_all_editable_with_no_locked_reference_controls(): void
    {
        $response = $this->get(route('payroll.settings.index'));
        $response->assertOk();
        $html = $response->getContent();
        // Applied keys are editable inputs.
        foreach (['pregnancy_reduced_hours_per_day', 'pregnancy_overtime_protection_months', 'nursing_sessions_per_day', 'nursing_minutes_per_session'] as $key) {
            $this->assertStringContainsString('name="policies['.$key.']"', $html, $key.' must be an editable policy input now');
        }
        // Owner rule (2026-11-10): no field stays locked as a legal reference;
        // every policy key renders as an editable per-company control, with an
        // honest note when the value is stored-only for now.
        $this->assertStringContainsString('name="policies[annual_prorate_first_year]"', $html);
        $this->assertStringContainsString('name="policies[pregnancy_reduced_hours_from_month]"', $html);
        $this->assertStringNotContainsString('مرجع فقط', $html);
    }

    public function test_future_scheduled_maternity_leave_carries_rights_before_start(): void
    {
        // HR registers the leave ahead of time: the certificate dates on the
        // future leave already drive the working-pregnancy rights today, while
        // the status still waits for the leave start day.
        $e = $this->employee();
        $this->maternityLeaveFor($e, [
            'start_date' => today()->addMonth()->toDateString(),
            'end_date' => today()->addMonths(5)->toDateString(),
            'expected_delivery_date' => today()->addMonths(2)->toDateString(),
            'pregnancy_reduction_start' => today()->subDays(3)->toDateString(),
        ]);
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        $this->assertStringContainsString('الحمل', $this->rights()->overtimeBlockReason($e->fresh(), today()) ?? '');
    }

    public function test_rejecting_maternity_leave_restores_the_employee_pregnancy_dates(): void
    {
        $e = $this->employee();
        $e->update([
            'expected_delivery_date' => today()->addMonths(2)->toDateString(),
            'pregnancy_reduction_start' => today()->subMonth()->toDateString(),
        ]);
        $this->postJson(route('employees.maternity-leave', $e), [])->assertOk();
        $this->assertNull($e->fresh()->expected_delivery_date);
        $leave = Leave::where('employee_id', $e->id)->firstOrFail();
        // Rejecting the leave must not silently drop her working-pregnancy rights.
        $this->patchJson(route('leaves.reject', $leave), [])->assertOk();
        $e = $e->fresh();
        $this->assertSame(today()->addMonths(2)->toDateString(), $e->expected_delivery_date->toDateString());
        $this->assertSame(today()->subMonth()->toDateString(), $e->pregnancy_reduction_start->toDateString());
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e, today()));
    }

    public function test_deleting_maternity_leave_restores_the_employee_pregnancy_dates(): void
    {
        $e = $this->employee();
        $e->update([
            'expected_delivery_date' => today()->addMonths(2)->toDateString(),
            'pregnancy_reduction_start' => today()->subMonth()->toDateString(),
        ]);
        $this->postJson(route('employees.maternity-leave', $e), [])->assertOk();
        $leave = Leave::where('employee_id', $e->id)->firstOrFail();
        $this->deleteJson(route('leaves.destroy', $leave))->assertOk();
        $e = $e->fresh();
        $this->assertSame(today()->addMonths(2)->toDateString(), $e->expected_delivery_date->toDateString());
        $this->assertSame(today()->subMonth()->toDateString(), $e->pregnancy_reduction_start->toDateString());
        $this->assertSame(60, $this->rights()->pregnancyReductionMinutes($e, today()));
    }

    public function test_rights_are_isolated_to_the_employee_company(): void
    {
        $e = $this->employee();
        // A maternity leave carrying pregnancy dates in another company must
        // never feed this employee's rights or payroll metrics.
        $other = Company::create(['name' => 'p2b', 'slug' => 'p2b', 'status' => 'active']);
        $leave = Leave::create([
            'employee_id' => $e->id, 'leave_type_id' => $this->maternity->id,
            'start_date' => today()->toDateString(), 'end_date' => today()->addMonths(3)->toDateString(),
            'days' => 90, 'status' => 'Approved',
            'expected_delivery_date' => today()->addMonths(2)->toDateString(),
            'pregnancy_reduction_start' => today()->toDateString(),
        ]);
        // Move the row to the other company at the DB level (company_id is not
        // mass-assignable on Leave): the rights lookup must not cross companies.
        DB::table('leaves')->where('id', $leave->id)->update(['company_id' => $other->id]);
        $this->assertSame(0, $this->rights()->pregnancyReductionMinutes($e->fresh(), today()));
        $this->assertNull($this->rights()->overtimeBlockReason($e->fresh(), today()));
    }
}
