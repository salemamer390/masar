<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendancePeriodArchive;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AttendanceMetricsService;
use App\Services\AttendancePeriodArchiveService;
use App\Services\HrOperationalSettingsService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Attendance shows the current payroll period only; finished periods are saved
 * once in "الشهور السابقة" and never change afterwards.
 */
class AttendancePeriodArchiveTest extends TestCase
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
        $this->company = Company::create(['name' => 'Company P', 'slug' => 'company-p', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create(['name' => 'User P', 'email' => 'p@example.test', 'password' => bcrypt('secret'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->employee = Employee::create([
            'employee_code' => '9101', 'first_name' => 'Emp', 'full_name' => 'Employee Period', 'company_id' => $this->company->id,
            'status_id' => $status->id, 'shift_start' => '09:00', 'shift_end' => '17:00',
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

    private function attend(string $date, string $in = '09:00', string $out = '17:00'): Attendance
    {
        return Attendance::create([
            'employee_id' => $this->employee->id, 'attendance_date' => $date, 'check_in' => $in, 'check_out' => $out,
            'status' => 'Present', 'late_minutes' => 0, 'source' => 'manual',
        ]);
    }

    private function service(): AttendancePeriodArchiveService
    {
        return app(AttendancePeriodArchiveService::class);
    }

    public function test_period_containing_follows_start_and_end_days_all_month(): void
    {
        $s = app(HrOperationalSettingsService::class);
        $early = $s->periodContaining(Carbon::parse('2026-11-02'));
        $this->assertSame(['2026-10-26', '2026-11-25'], [$early['start']->toDateString(), $early['end']->toDateString()]);
        // After the end day the CURRENT period is already the next one.
        $late = $s->periodContaining(Carbon::parse('2026-11-28'));
        $this->assertSame(['2026-11-26', '2026-12-25'], [$late['start']->toDateString(), $late['end']->toDateString()]);
        $edge = $s->periodContaining(Carbon::parse('2026-11-25'));
        $this->assertSame('2026-11-25', $edge['end']->toDateString());
    }

    public function test_attendance_page_shows_only_current_period_days(): void
    {
        $this->attend('2026-11-24');
        $this->attend('2026-10-20'); // previous period
        Carbon::setTestNow('2026-11-02 10:00:00');
        $html = $this->get(route('attendance.index'))->assertOk()->getContent();
        $this->assertStringContainsString('24/11/2026', $html);
        $this->assertStringNotContainsString('20/10/2026', $html);
        $this->assertStringContainsString('الشهور السابقة', $html);
        $this->assertStringContainsString('26/10/2026', $html);
        $this->assertStringContainsString('25/11/2026', $html);
        Carbon::setTestNow('2026-11-28 10:00:00');
        $html = $this->get(route('attendance.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('24/11/2026', $html, 'after the end day the page moves to the next period');
        Carbon::setTestNow();
    }

    public function test_finished_period_is_saved_with_exact_boundaries_and_rows(): void
    {
        $this->attend('2026-10-25'); // before
        $this->attend('2026-10-26');
        $this->attend('2026-11-25', '09:30', '18:00');
        $this->attend('2026-11-26'); // next period
        $created = $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $this->assertSame(2, $created, 'opened + closed');
        $archive = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('2026-10-26', $archive->period_start->toDateString());
        $this->assertSame('2026-11-25', $archive->period_end->toDateString());
        $this->assertSame(2, $archive->record_count);
        $this->assertSame(['2026-10-26', '2026-11-25'], array_column($archive->rows, 'date'));
        $this->assertSame(60, $archive->rows[1]['night_overtime_minutes'] + $archive->rows[1]['day_overtime_minutes']);
        $this->assertSame('26', (string) $archive->settings['payroll_period_start_day']);
    }

    public function test_period_opens_when_it_ends_and_closes_three_full_days_later_idempotently(): void
    {
        $this->attend('2026-11-10');
        $this->assertSame(['opened' => 0, 'closed' => 0], $this->service()->sync($this->company->id, Carbon::parse('2026-11-25')));
        $this->assertSame(['opened' => 1, 'closed' => 0], $this->service()->sync($this->company->id, Carbon::parse('2026-11-26')));
        $a = $this->archives()[0];
        $this->assertSame('pending', $a->status);
        $this->assertSame('2026-11-29', $a->closes_on->toDateString());
        $this->assertNull($a->rows);
        $this->assertSame(['opened' => 0, 'closed' => 0], $this->service()->sync($this->company->id, Carbon::parse('2026-11-28')), 'still correctable on the 3rd day');
        $this->assertSame(['opened' => 0, 'closed' => 1], $this->service()->sync($this->company->id, Carbon::parse('2026-11-29')));
        $this->assertSame('closed', $this->archives()[0]->status);
        $this->assertSame(['opened' => 0, 'closed' => 0], $this->service()->sync($this->company->id, Carbon::parse('2026-11-30')));
        $this->assertSame(1, $this->archives()->count());
    }

    public function test_saved_period_is_never_rewritten_by_later_changes(): void
    {
        $record = $this->attend('2026-11-10');
        $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $before = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        // live data edited later (late arrival / correction) + period days changed + re-run
        \DB::table((new \App\Models\Attendance)->getTable())->where('id', $record->id)->update(['check_in' => '12:00', 'status' => 'Late', 'late_minutes' => 180]); // bypass guards: simulate raw drift
        \DB::table((new \App\Models\Attendance)->getTable())->insert(\DB::table((new \App\Models\Attendance)->getTable())->where('id', $record->id)->get()->map(fn ($r) => collect((array) $r)->except('id')->merge(['attendance_date' => '2026-11-12'])->all())->all());
        $this->setting('payroll_period_start_day', '1');
        $this->setting('payroll_period_end_day', '30');
        $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $this->service()->archivePeriod($this->company->id, Carbon::parse('2026-10-26'), Carbon::parse('2026-11-25'));

        $after = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($before->rows, $after->rows);
        $this->assertSame('2026-10-26', $after->period_start->toDateString());
        $this->assertSame(1, $after->record_count);

        $this->expectException(\LogicException::class);
        $after->update(['record_count' => 99]);
    }

    public function test_saved_period_cannot_be_deleted(): void
    {
        $this->attend('2026-11-10');
        $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $archive = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->expectException(\LogicException::class);
        $archive->delete();
    }

    public function test_settings_recalculation_skips_closed_dates_and_updates_open_dates(): void
    {
        $closed = $this->attend('2026-11-10', '10:00', '17:00');
        $closed->update(['late_minutes' => 0, 'status' => 'Present']);
        $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $open = $this->attend('2026-11-30', '10:00', '17:00');
        $open->update(['late_minutes' => 0, 'status' => 'Present']);

        $this->setting('grace_minutes', '15');
        app(AttendanceMetricsService::class)->recalculateCompany($this->company->id);

        $this->assertSame(0, (int) $closed->fresh()->late_minutes, 'closed date keeps its saved value');
        $this->assertSame(60, (int) $open->fresh()->late_minutes, 'open date is recalculated');
    }

    public function test_previous_months_pages_render_and_export(): void
    {
        $this->attend('2026-11-10');
        $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $archive = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        $list = $this->get(route('attendance.archive.index'))->assertOk()->getContent();
        $this->assertStringContainsString('26/10/2026 - 25/11/2026', $list);
        $show = $this->get(route('attendance.archive.show', $archive))->assertOk()->getContent();
        $this->assertStringContainsString('Employee Period', $show);
        $csv = $this->get(route('attendance.archive.export', $archive))->assertOk()->streamedContent();
        $this->assertStringContainsString('Employee Period', $csv);
        $this->assertStringContainsString('10/11/2026', $csv);
    }

    public function test_another_company_cannot_open_a_saved_period(): void
    {
        $this->attend('2026-11-10');
        $this->service()->archiveEndedPeriods($this->company->id, Carbon::parse('2026-11-29'));
        $archive = AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        $other = Company::create(['name' => 'Company Q', 'slug' => 'company-q', 'status' => 'active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'test-unlimited'], ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]);
        CompanySubscription::create(['company_id' => $other->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $other->id]);
        $userQ = User::create(['name' => 'User Q', 'email' => 'q@example.test', 'password' => bcrypt('secret'), 'role_id' => $role->id, 'company_id' => $other->id, 'is_active' => true]);

        $this->actingAs($userQ)->get(route('attendance.archive.show', $archive))->assertNotFound();
        $this->get(route('attendance.archive.index'))->assertOk()->assertDontSee('26/10/2026');
    }

    private function archives(): \Illuminate\Support\Collection
    {
        return AttendancePeriodArchive::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('period_start')->get();
    }

    private function ranges(): array
    {
        return $this->archives()->map(fn ($a) => $a->period_start->toDateString() . '..' . $a->period_end->toDateString())->all();
    }

    public function test_downtime_longer_than_a_week_still_saves_every_missed_period_in_order(): void
    {
        $this->attend('2026-10-10');
        $this->attend('2026-11-10');
        $this->attend('2026-12-10');
        $this->service()->sync($this->company->id, Carbon::parse('2026-10-26')); // baseline: Sep26..Oct25 (pending)
        $this->assertSame(['2026-09-26..2026-10-25'], $this->ranges());
        // scheduler down until 2 Jan: two periods ended meanwhile
        $r = $this->service()->sync($this->company->id, Carbon::parse('2027-01-02'));
        $this->assertSame(['opened' => 2, 'closed' => 3], $r);
        $this->assertSame(['2026-09-26..2026-10-25', '2026-10-26..2026-11-25', '2026-11-26..2026-12-25'], $this->ranges());
        $this->assertSame(1, $this->archives()[0]->record_count);
        $this->assertSame(1, $this->archives()[1]->record_count);
        $this->assertSame(1, $this->archives()[2]->record_count);
    }

    private function saveDays(string $start, string $end, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->put(route('payroll.settings.operational.update'), $extra + [
            'default_shift_start' => '09:00', 'default_shift_end' => '17:00', 'absence_cutoff_time' => '17:00', 'grace_minutes' => '15',
            'weekly_rest_pattern' => '0000100', 'night_start' => '19:00', 'night_end' => '07:00',
            'payroll_period_start_day' => $start, 'payroll_period_end_day' => $end, 'unpaid_leave_as_deduction' => '1',
        ]);
    }

    private function frozenRun(int $companyId, string $month, string $from, string $to, string $status = 'Approved'): int
    {
        $runId = DB::table('payroll_runs')->insertGetId(['company_id' => $companyId, 'payroll_month' => $month, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $employeeId = DB::table('employees')->where('company_id', $companyId)->value('id');
        DB::table('payroll_records')->insert(['employee_id' => $employeeId, 'payroll_month' => $month, 'payroll_run_id' => $runId, 'status' => $status,
            'calculation_snapshot' => json_encode(['service_start' => $from, 'service_end' => $to]), 'created_at' => now(), 'updated_at' => now()]);

        return $runId;
    }

    private function settingValue(string $key): ?string
    {
        return DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', $key)->value('setting_value');
    }

    public function test_save_that_reuses_already_saved_attendance_days_is_rejected_and_changes_nothing(): void
    {
        $this->attend('2026-11-10');
        Carbon::setTestNow('2026-11-26 09:00:00'); // 26/10..25/11 just ended and is saved (pending) when settings are saved
        $this->service()->sync($this->company->id);
        $this->assertSame(['2026-10-26..2026-11-25'], $this->ranges());
        $draft = DB::table('payroll_runs')->insertGetId(['company_id' => $this->company->id, 'payroll_month' => '2026-11-01', 'status' => 'Draft', 'created_at' => now(), 'updated_at' => '2026-01-01 00:00:00']);

        $response = $this->saveDays('1', '30', ['grace_minutes' => '40']);
        $response->assertRedirect()->assertSessionHasErrors('payroll_period');
        $errors = implode(' | ', session('errors')->get('payroll_period'));
        $this->assertStringContainsString('01/11/2026', $errors);
        $this->assertStringContainsString('25/11/2026', $errors);
        $this->assertSame('26', $this->settingValue('payroll_period_start_day'), 'period days untouched');
        $this->assertNull($this->settingValue('grace_minutes'), 'other fields of the same form are not saved either');
        $this->assertSame(26, app(HrOperationalSettingsService::class)->payrollPeriodStartDay(), 'no stale or poisoned cache');
        $this->assertSame('2026-01-01 00:00:00', (string) DB::table('payroll_runs')->where('id', $draft)->value('updated_at'), 'drafts not regenerated');
        $this->assertSame(['2026-10-26..2026-11-25'], $this->ranges(), 'saved period unchanged');
        Carbon::setTestNow();
    }

    public function test_json_request_gets_422_with_the_overlapping_dates(): void
    {
        Carbon::setTestNow('2026-11-30 09:00:00');
        $this->service()->archivePeriod($this->company->id, Carbon::parse('2026-10-26'), Carbon::parse('2026-11-25'));
        $this->putJson(route('payroll.settings.operational.update'), [
            'default_shift_start' => '09:00', 'default_shift_end' => '17:00', 'absence_cutoff_time' => '17:00', 'grace_minutes' => '15',
            'weekly_rest_pattern' => '0000100', 'payroll_period_start_day' => '1', 'payroll_period_end_day' => '30',
        ])->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('overlaps.0.from', '2026-11-01')->assertJsonPath('overlaps.0.to', '2026-11-25');
        $this->assertSame('closed', $this->archives()[0]->status);
        Carbon::setTestNow();
    }

    public function test_change_that_does_not_reuse_calculated_days_is_saved_exactly_as_configured(): void
    {
        $this->attend('2026-11-10');
        Carbon::setTestNow('2026-11-26 09:00:00');
        $this->saveDays('26', '24', ['confirm_close_pending' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('24', $this->settingValue('payroll_period_end_day'));
        $this->assertSame(['2026-10-26..2026-11-25'], $this->ranges(), 'ended period kept with the boundaries it had');
        $period = app(HrOperationalSettingsService::class)->periodContaining(today());
        $this->assertSame(['2026-11-26', '2026-12-24'], [$period['start']->toDateString(), $period['end']->toDateString()], 'exactly 26..24');
        $this->get(route('attendance.index'))->assertSee('26/11/2026', false)->assertSee('24/12/2026', false);
        Carbon::setTestNow('2026-12-26 00:10:00');
        $this->service()->sync($this->company->id);
        $this->assertSame(['2026-10-26..2026-11-25', '2026-11-26..2026-12-24'], $this->ranges());
        Carbon::setTestNow();
    }

    public function test_boundary_change_needs_confirmation_then_closes_pending_period_now_atomically(): void
    {
        $this->attend('2026-11-10');
        Carbon::setTestNow('2026-11-26 09:00:00');
        $this->service()->sync($this->company->id); // 26/10..25/11 pending until 29/11

        // No confirmation: nothing is saved, nothing is closed.
        $this->saveDays('26', '24', ['grace_minutes' => '40'])->assertSessionHas('period_confirm');
        $this->assertSame('25', $this->settingValue('payroll_period_end_day'));
        $this->assertNull($this->settingValue('grace_minutes'));
        $this->assertSame('pending', $this->archives()[0]->status);
        $this->assertNull($this->archives()[0]->rows);

        // Confirmed: closed now (deadline not reached) with its exact dates, then settings are saved.
        $this->saveDays('26', '24', ['confirm_close_pending' => '1'])->assertSessionHasNoErrors();
        $a = $this->archives()[0];
        $this->assertSame('closed', $a->status);
        $this->assertSame(['2026-10-26', '2026-11-25'], [$a->period_start->toDateString(), $a->period_end->toDateString()]);
        $this->assertSame('24', $this->settingValue('payroll_period_end_day'));
        Carbon::setTestNow();
    }

    public function test_confirmation_never_overrides_overlap_rejection_and_rolls_the_early_close_back(): void
    {
        $this->attend('2026-11-10');
        Carbon::setTestNow('2026-11-26 09:00:00');
        $this->service()->sync($this->company->id);
        $this->saveDays('1', '30', ['confirm_close_pending' => '1'])->assertSessionHasErrors('payroll_period');
        $this->assertSame('26', $this->settingValue('payroll_period_start_day'));
        $this->assertSame('pending', $this->archives()[0]->status, 'early close rolled back with the rejected save');
        Carbon::setTestNow();
    }

    public function test_approval_stores_the_exact_period_and_readers_keep_it_after_a_settings_change(): void
    {
        Carbon::setTestNow('2026-11-26 09:00:00'); // 26/10..25/11 has ended
        $draft = $this->frozenRun($this->company->id, '2026-11-01', '2026-10-26', '2026-11-25', 'Draft');
        $old = $this->frozenRun($this->company->id, '2026-09-01', '2026-08-26', '2026-09-25', 'Approved'); // approved before dates were stored
        $this->assertNull(DB::table('payroll_runs')->where('id', $old)->value('period_start'));

        app(\App\Services\PayrollRunService::class)->approve(\App\Models\PayrollRun::withoutGlobalScopes()->find($draft), true, true);
        $run = DB::table('payroll_runs')->where('id', $draft)->first();
        $this->assertSame(['2026-10-26', '2026-11-25'], [substr($run->period_start, 0, 10), substr($run->period_end, 0, 10)]);

        DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', 'payroll_period_start_day')->update(['setting_value' => '1']);
        DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', 'payroll_period_end_day')->update(['setting_value' => '30']);
        app(HrOperationalSettingsService::class)->flush();

        \App\Support\CompanyContext::run($this->company->id, function () {
            $svc = app(\App\Services\PayrollRunService::class);
            $p = $svc->periodFor(Carbon::parse('2026-11-01'));
            $this->assertSame(['2026-10-26', '2026-11-25'], [$p['start']->toDateString(), $p['end']->toDateString()], 'approved run keeps its original period');
            $q = $svc->periodFor(Carbon::parse('2026-09-01'));
            $this->assertSame(['2026-09-01', '2026-09-30'], [$q['start']->toDateString(), $q['end']->toDateString()], 'run without stored dates is not guessed: configured period');
        });
        Carbon::setTestNow();
    }

    public function test_reconciliation_and_monthly_summary_use_the_stored_period_after_a_settings_change(): void
    {
        Carbon::setTestNow('2026-11-26 09:00:00');
        $this->attend('2026-10-28'); // inside the original 26/10..25/11, outside a later 1/11..30/11
        $draft = $this->frozenRun($this->company->id, '2026-11-01', '2026-10-26', '2026-11-25', 'Draft');
        app(\App\Services\PayrollRunService::class)->approve(\App\Models\PayrollRun::withoutGlobalScopes()->find($draft), true, true);
        DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', 'payroll_period_start_day')->update(['setting_value' => '1']);
        DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', 'payroll_period_end_day')->update(['setting_value' => '30']);
        app(HrOperationalSettingsService::class)->flush();

        \App\Support\CompanyContext::run($this->company->id, function () use ($draft) {
            $svc = app(\App\Services\PayrollRunService::class);
            $windows = collect($svc->payableEmployees(Carbon::parse('2026-11-01'), $this->company->id))->pluck('window');
            $this->assertNotEmpty($windows);
            $this->assertSame('2026-10-26', $windows->first()['start']->toDateString(), 'reconciliation judges the run against its original period');
            $this->assertSame('2026-11-25', $windows->first()['end']->toDateString());
            $this->assertArrayHasKey('eligible', $svc->reconciliationReport(\App\Models\PayrollRun::withoutGlobalScopes()->find($draft)));

            $employees = \App\Models\Employee::where('company_id', $this->company->id)->get();
            $controller = app(\App\Http\Controllers\JobDataController::class);
            $m = new \ReflectionMethod($controller, 'buildMonthlyRows'); $m->setAccessible(true);
            $row = $m->invoke($controller, $employees, Carbon::parse('2026-11-01'))->first();
            $this->assertGreaterThan(0, $row['attendance_days'], 'summary of the finalized month still counts 28/10 (original period)');
        });
        Carbon::setTestNow();
    }

    public function test_overlap_guard_uses_the_stored_run_period_not_only_record_windows(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $run = $this->frozenRun($this->company->id, '2026-10-01', '2026-10-01', '2026-10-10', 'Approved'); // narrow record window
        DB::table('payroll_runs')->where('id', $run)->update(['period_start' => '2026-09-26', 'period_end' => '2026-10-25']);
        $this->saveDays('20', '19')->assertSessionHasErrors('payroll_period'); // new Nov = 20/10.. reuses 20/10-25/10 of the stored period
        $this->assertSame('26', $this->settingValue('payroll_period_start_day'));
        Carbon::setTestNow();
    }

    public function test_approved_payroll_blocks_only_a_change_that_reuses_its_days(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->frozenRun($this->company->id, '2026-10-01', '2026-09-26', '2026-10-25', 'Locked');
        $this->frozenRun($this->company->id, '2026-11-01', '2026-10-26', '2026-11-25', 'Approved');
        // 26/25 -> 1/30: the next unfinished month (Dec 1..30) reuses nothing, so an existing approved run does not forbid it.
        $this->saveDays('1', '30')->assertSessionHasNoErrors();
        $this->assertSame('1', $this->settingValue('payroll_period_start_day'));
        $this->assertStringContainsString('26/11/2026', implode(' ', (array) session('period_notes')), 'uncovered days are reported, not invented');
        // back to 26/25: Dec would be 26/11..25/12 and reuses 26/11? No - Nov is final (..25/11), so it is fine too.
        $this->saveDays('26', '25')->assertSessionHasNoErrors();
        // Oct is final with 1/10..30/10 in this variant: switching to 26/25 would make Nov start on 26/10 and reuse 26..30/10.
        DB::table('payroll_records')->where('payroll_month', '2026-11-01')->delete();
        DB::table('payroll_runs')->where('payroll_month', '2026-11-01')->delete();
        DB::table('payroll_records')->where('payroll_month', '2026-10-01')->update(['calculation_snapshot' => json_encode(['service_start' => '2026-10-01', 'service_end' => '2026-10-30'])]);
        $this->setting('payroll_period_start_day', '1'); $this->setting('payroll_period_end_day', '30');
        $this->saveDays('26', '25')->assertSessionHasErrors('payroll_period');
        $this->assertStringContainsString('26/10/2026', implode(' ', session('errors')->get('payroll_period')));
        $this->assertSame('1', $this->settingValue('payroll_period_start_day'));
        Carbon::setTestNow();
    }

    public function test_another_companys_payroll_never_blocks_this_company(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $other = Company::create(['name' => 'Other', 'slug' => 'other-co', 'status' => 'active']);
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        Employee::withoutEvents(fn () => Employee::create(['employee_code' => '9201', 'first_name' => 'X', 'full_name' => 'Other Emp', 'company_id' => $other->id, 'status_id' => $status->id, 'shift_start' => '09:00', 'shift_end' => '17:00']));
        $this->frozenRun($other->id, '2026-12-01', '2026-11-26', '2026-12-25', 'Locked');
        $this->frozenRun($other->id, '2026-11-01', '2026-10-26', '2026-11-25', 'Locked');
        $this->saveDays('1', '30')->assertSessionHasNoErrors();
        $this->assertSame('1', $this->settingValue('payroll_period_start_day'));
        $this->assertSame('26', (string) DB::table('hr_operational_settings')->where('company_id', $other->id)->value('setting_value') ?: '26');
        Carbon::setTestNow();
    }

    public function test_correction_days_are_a_setting_and_a_change_never_moves_an_already_opened_period(): void
    {
        $this->assertSame(3, app(HrOperationalSettingsService::class)->attendanceCorrectionDays(), 'default stays 3');
        Carbon::setTestNow('2026-11-26 09:00:00');
        $this->service()->sync($this->company->id); // 26/10..25/11 pending, closes 29/11 (3 days)
        $this->assertSame('2026-11-29', $this->archives()[0]->closes_on->toDateString());

        $this->saveDays('26', '25', ['attendance_correction_days' => '6'])->assertSessionHasNoErrors();
        $this->assertSame('6', $this->settingValue('attendance_correction_days'));
        $this->assertSame('2026-11-29', $this->archives()[0]->closes_on->toDateString(), 'open correction window keeps its stored deadline');
        $this->assertSame('pending', $this->archives()[0]->status);

        Carbon::setTestNow('2026-12-26 00:10:00');
        $this->service()->sync($this->company->id); // closes the old one, opens 26/11..25/12 with 6 days
        $archives = $this->archives()->keyBy(fn ($a) => $a->period_start->toDateString());
        $this->assertSame('closed', $archives['2026-10-26']->status);
        $this->assertSame('2027-01-01', $archives['2026-11-26']->closes_on->toDateString(), '25/12 + 6 days + 1');

        $this->saveDays('26', '25', ['attendance_correction_days' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('2027-01-01', $this->archives()->last()->closes_on->toDateString(), 'shortening does not close an open window early');
        $this->saveDays('26', '25', ['attendance_correction_days' => '5000'])->assertSessionHasNoErrors();
        $this->assertSame('5000', $this->settingValue('attendance_correction_days'), 'long windows are allowed');
        foreach (['-1', '2.5', 'abc', '99999999999999999999', '3000000'] as $bad) {
            $this->saveDays('26', '25', ['attendance_correction_days' => $bad])->assertSessionHasErrors('attendance_correction_days');
        }
        $this->assertSame('5000', $this->settingValue('attendance_correction_days'), 'invalid values change nothing');
        Carbon::setTestNow();
    }

    public function test_exact_periods_are_archived_even_after_a_direct_settings_change_without_inventing_days(): void
    {
        $this->service()->sync($this->company->id, Carbon::parse('2026-10-26')); // Sep26..Oct25 pending
        $this->setting('payroll_period_start_day', '1'); // changed behind the form's back
        $this->setting('payroll_period_end_day', '30');
        $this->service()->sync($this->company->id, Carbon::parse('2026-12-05'));
        $ranges = $this->ranges();
        $this->assertSame(['2026-09-26..2026-10-25', '2026-10-01..2026-10-30', '2026-11-01..2026-11-30'], $ranges, 'exactly the configured period, no short transition and nothing skipped silently');
    }

    public function test_duplicate_save_attempt_keeps_the_first_saved_period(): void
    {
        $this->attend('2026-11-10');
        $first = $this->service()->archivePeriod($this->company->id, Carbon::parse('2026-10-26'), Carbon::parse('2026-11-25'));
        $this->assertNotNull($first);
        $this->attend('2026-11-27');
        $this->assertNull($this->service()->archivePeriod($this->company->id, Carbon::parse('2026-10-26'), Carbon::parse('2026-11-25')));
        $this->assertSame(1, $this->archives()->count());
        $this->assertSame(1, $this->archives()[0]->record_count);
    }

    public function test_corrections_are_allowed_during_the_grace_days_and_blocked_after_close_on_every_write_path(): void
    {
        $row = $this->attend('2026-11-10');
        Carbon::setTestNow('2026-11-28 09:00:00');
        $this->service()->sync($this->company->id);
        $this->assertSame('pending', $this->archives()[0]->status);

        // manual correction during grace works
        $this->put(route('attendance.update', $row), ['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-10', 'check_in' => '09:30', 'check_out' => '17:00', 'status' => 'Present', 'source' => 'manual'])->assertRedirect();
        $this->assertSame('09:30:00', substr((string) $row->fresh()->check_in, 0, 8));
        $this->get(route('attendance.index'))->assertOk()->assertSee('ما زالت متاحة للتصحيح حتى 28/11/2026', false);

        Carbon::setTestNow('2026-11-29 00:10:00');
        $this->service()->sync($this->company->id);
        $this->assertSame('closed', $this->archives()[0]->status);
        $savedIn = $this->archives()[0]->rows[0]['check_in'];

        $this->put(route('attendance.update', $row), ['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-10', 'check_in' => '12:00', 'check_out' => '17:00', 'status' => 'Present', 'source' => 'manual'])->assertStatus(422);
        $this->post(route('attendance.store'), ['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-11', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present'])->assertStatus(422);
        $this->delete(route('attendance.destroy', $row))->assertStatus(422);
        // model backstop (sync, jobs, anything else)
        try { Attendance::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-12', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'source' => 'fingerprint']); $this->fail('write allowed'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        try { $row->fresh()->update(['check_out' => '23:00']); $this->fail('update allowed'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->assertSame(1, Attendance::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame('09:30:00', substr((string) $row->fresh()->check_in, 0, 8), 'raw closed value did not drift');
        $this->assertSame($savedIn, $this->archives()[0]->rows[0]['check_in']);
        // the current period is still editable
        $this->post(route('attendance.store'), ['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-30', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present'])->assertRedirect();
        Carbon::setTestNow();
    }

    public function test_deadline_locks_dates_even_if_the_nightly_job_has_not_run_and_banner_matches_stored_deadline(): void
    {
        $row = $this->attend('2026-10-10');
        Carbon::setTestNow('2026-10-26 09:00:00');
        $this->service()->sync($this->company->id);
        $a = $this->archives()[0];
        $this->assertSame('2026-10-25', $a->period_end->toDateString());
        $this->assertSame('2026-10-29', $a->closes_on->toDateString(), '3 full days (26,27,28) then closed from 29th 00:00');
        $this->get(route('attendance.index'))->assertSee('حتى 28/10/2026', false);

        Carbon::setTestNow('2026-10-28 23:59:00');
        $row->update(['check_out' => '18:00']); // still allowed on the 3rd day
        Carbon::setTestNow('2026-10-29 00:01:00'); // job has not run yet
        $this->assertSame('pending', $this->archives()[0]->status);
        try { $row->fresh()->update(['check_out' => '23:00']); $this->fail('allowed after deadline'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
        $this->get(route('attendance.index'))->assertDontSee('ما زالت متاحة للتصحيح');
        $this->service()->sync($this->company->id);
        $this->assertSame('18:00:00', $this->archives()[0]->rows[0]['check_out']);
        Carbon::setTestNow();
    }
}
