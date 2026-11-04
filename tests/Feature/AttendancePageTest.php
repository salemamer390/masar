<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\EmployeePeriodMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendancePageTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo')); // Thursday, after cutoff
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'attendance-page-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'AttendancePage', 'slug' => 'attendance-page', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(\App\Models\Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'attendance-page@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        $this->employee = Employee::create(['employee_code' => 'P1', 'first_name' => 'Mona', 'full_name' => 'Mona Page', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        $this->setting('payroll_period_start_day', '1');
        $this->setting('payroll_period_end_day', '31');
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function setting(string $key, string $value): void
    {
        DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function present(string $day, string $in = '09:00', string $out = '17:00', string $status = 'Present', int $late = 0): Attendance
    {
        return Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => $day, 'check_in' => $in, 'check_out' => $out, 'status' => $status, 'late_minutes' => $late, 'approval_status' => 'Approved', 'source' => 'manual']);
    }

    /** All rendered virtual absence dates for this employee. */
    private function absentDates(string $html): array
    {
        preg_match_all('/<tr class="attendance-row-absent"\s+data-virtual="1" data-employee-id="'.$this->employee->id.'" data-date="(\d{4}-\d{2}-\d{2})"/', $html, $m);
        return $m[1];
    }

    public function test_elapsed_workdays_without_records_render_as_absent_through_today(): void
    {
        $this->present('2026-11-24');
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $dates = $this->absentDates($html);
        // Workdays 1..26 Nov = 23 after removing Fridays (6, 13, 20); minus the present day (24) = 22 absent days, today included after cutoff.
        $this->assertCount(22, $dates);
        $this->assertContains('2026-11-26', $dates);   // today after cutoff counts
        $this->assertNotContains('2026-11-24', $dates); // recorded day never doubles as absence
        $this->assertNotContains('2026-11-06', $dates); // Friday rest
        $this->assertNotContains('2026-11-13', $dates);
        $this->assertNotContains('2026-11-20', $dates);
    }

    public function test_future_days_never_appear_as_absence(): void
    {
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        // No table row may carry a date past today, real or synthesized.
        preg_match_all('/data-date="(\d{4}-\d{2}-\d{2})"/', $html, $m);
        foreach ($m[1] as $date) $this->assertLessThanOrEqual('2026-11-26', $date);
        preg_match_all('/<td data-label="التاريخ">(\d{2}\/\d{2}\/\d{4})<\/td>/', $html, $m2);
        foreach ($m2[1] as $shown) {
            $this->assertLessThanOrEqual('2026-11-26', Carbon::createFromFormat('d/m/Y', $shown)->toDateString());
        }
    }

    public function test_today_before_cutoff_is_pending_not_absent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'Africa/Cairo'));
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('لم يُسجَّل بعد', $html);
        $this->assertNotContains('2026-11-26', $this->absentDates($html));
        preg_match('/data-virtual="1" data-employee-id="'.$this->employee->id.'" data-date="2026-11-26"/', $html, $m);
        $this->assertNotEmpty($m, 'today shows a pending row');
        $this->assertStringContainsString('attendance-row-pending', $html);
    }

    public function test_leave_and_holiday_days_are_not_absence(): void
    {
        $type = LeaveType::create(['name' => 'Annual', 'policy_code' => 'annual', 'is_paid' => true, 'is_active' => true, 'company_id' => $this->company->id]);
        Leave::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-23', 'end_date' => '2026-11-23', 'days' => 1, 'status' => 'Approved']);
        DB::table('hr_holidays')->insert(['company_id' => $this->company->id, 'holiday_date' => '2026-11-25', 'name' => 'اختبار', 'created_at' => now(), 'updated_at' => now()]);
        $dates = $this->absentDates($this->get('/attendance?per_page=200')->assertOk()->getContent());
        $this->assertNotContains('2026-11-23', $dates);
        $this->assertNotContains('2026-11-25', $dates);
    }

    public function test_hire_and_termination_dates_bound_absence(): void
    {
        $this->employee->update(['hire_date' => '2026-11-10', 'termination_date' => '2026-11-20']);
        $status = EmployeeStatus::firstOrCreate(['name' => 'Terminated']);
        $this->employee->update(['status_id' => $status->id]);
        $dates = $this->absentDates($this->get('/attendance?per_page=200')->assertOk()->getContent());
        foreach ($dates as $date) {
            $this->assertGreaterThanOrEqual('2026-11-10', $date);
            $this->assertLessThanOrEqual('2026-11-20', $date);
        }
        $this->assertContains('2026-11-10', $dates);
    }

    public function test_late_and_absent_rows_carry_color_classes_and_text_labels(): void
    {
        $this->present('2026-11-24', '09:45', '17:00', 'Late', 45);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-late"/', $html);
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-absent"/', $html);
        $this->assertStringContainsString('>متأخر<', $html);
        $this->assertStringContainsString('>غائب<', $html);
        $this->assertStringContainsString('legend-swatch legend-late', $html);
        $this->assertStringContainsString('legend-swatch legend-absent', $html);
    }

    public function test_state_filter_limits_the_list(): void
    {
        $this->present('2026-11-24', '09:45', '17:00', 'Late', 45);
        $this->present('2026-11-25');
        $badge = fn (string $html, string $label) => preg_match('/badge-soft \w+">'.$label.'</', $html) === 1;
        $absentOnly = $this->get('/attendance?state=absent&per_page=200')->assertOk()->getContent();
        $this->assertFalse($badge($absentOnly, 'متأخر'));
        $this->assertFalse($badge($absentOnly, 'حاضر'));
        $this->assertTrue($badge($absentOnly, 'غائب'));
        $lateOnly = $this->get('/attendance?state=late&per_page=200')->assertOk()->getContent();
        $this->assertTrue($badge($lateOnly, 'متأخر'));
        $this->assertFalse($badge($lateOnly, 'غائب'));
    }

    public function test_period_totals_match_the_shared_day_classification(): void
    {
        $this->present('2026-11-24', '09:45', '17:00', 'Late', 45);
        $this->present('2026-11-25');
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $daily = app(EmployeePeriodMetrics::class)->calculate(
            $this->employee->fresh(), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'),
            Attendance::effective()->get(), Leave::effective()->get(), \App\Models\HrRequest::effective()->with('requestType')->get());
        $this->assertSame(2, $daily['attendance_days']);
        $this->assertSame(1, $daily['late_days']);
        preg_match('/حضور\s*<b>(\d+)<\/b>\s*يوم/', $html, $present);
        preg_match('/تأخير\s*<b>(\d+)<\/b>\s*يوم/', $html, $late);
        preg_match('/غياب\s*<b>([\d.]+)<\/b>\s*يوم/', $html, $absent);
        $this->assertSame($daily['attendance_days'], (int) $present[1]);
        $this->assertSame($daily['late_days'], (int) $late[1]);
        $this->assertSame((float) $daily['absence_days'], (float) $absent[1]);
    }

    public function test_two_companies_are_isolated(): void
    {
        $other = Company::create(['name' => 'OtherCo', 'slug' => 'other-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $other->id, 'subscription_plan_id' => \App\Models\SubscriptionPlan::firstOrCreate(['slug' => 'attendance-page-unlimited'])->id, 'status' => 'active', 'starts_at' => today()]);
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        Employee::withoutEvents(fn () => Employee::create(['employee_code' => 'X9', 'first_name' => 'Ghost', 'full_name' => 'Ghost OutsideWorker', 'company_id' => $other->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']));
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringNotContainsString('Ghost OutsideWorker', $html);
    }

    public function test_rejected_record_is_labeled_and_absence_is_still_synthesized(): void
    {
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Rejected', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-rejected"/', $html);
        $this->assertStringContainsString('(مرفوض)', $html);
        // A rejected record is not a fingerprint: the day still shows as synthesized absence.
        $this->assertContains('2026-11-24', $this->absentDates($html));
        // The rejected row never masquerades as absence under the absence filter.
        $absentOnly = $this->get('/attendance?state=absent&per_page=200')->assertOk()->getContent();
        $this->assertStringNotContainsString('(مرفوض)', $absentOnly);
    }

    public function test_absent_record_on_a_leave_day_follows_the_day_classification(): void
    {
        $type = LeaveType::create(['name' => 'Annual', 'policy_code' => 'annual', 'is_paid' => true, 'is_active' => true, 'company_id' => $this->company->id]);
        Leave::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-24', 'end_date' => '2026-11-24', 'days' => 1, 'status' => 'Approved']);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        // The raw record says Absent, but the day is leave: no red row, no absence count.
        $this->assertDoesNotMatchRegularExpression('/<tr class="attendance-row-absent"\s+data-attendance-id/', $html);
        $this->assertStringContainsString('badge-soft info">إجازة<', $html);
        $this->assertNotContains('2026-11-24', $this->absentDates($html));
    }

    public function test_permission_covered_late_is_not_colored_late(): void
    {
        \App\Models\RequestType::create(['code' => 'permission', 'display_name' => 'permission', 'source_key' => 'permission', 'is_active' => true, 'company_id' => $this->company->id]);
        \App\Models\HrRequest::create(['employee_id' => $this->employee->id, 'request_type' => 'permission', 'request_type_id' => \App\Models\RequestType::where('code', 'permission')->value('id'), 'title' => 'permission', 'payload' => ['request_date' => '2026-11-24', 'from_time' => '09:00', 'to_time' => '10:00'], 'status' => 'Approved', 'created_by' => $this->user->id]);
        $this->present('2026-11-24', '09:30', '17:00', 'Late', 30);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        // The permission covers the whole late window: the row is present, not yellow.
        $this->assertDoesNotMatchRegularExpression('/<tr class="attendance-row-late"/', $html);
        $this->assertStringContainsString('badge-soft success">حاضر<', $html);
    }

    public function test_invalid_reversed_and_oversized_ranges_are_validation_errors_not_500(): void
    {
        $this->get('/attendance?from_date=not-a-date')->assertSessionHasErrors('from_date');
        $this->get('/attendance?from_date=2026-11-20&to_date=2026-11-10')->assertSessionHasErrors('to_date');
        $this->get('/attendance?from_date=2026-01-01&to_date=2026-12-31')->assertSessionHasErrors('to_date');
        $this->get('/attendance/export?from_date=not-a-date')->assertSessionHasErrors('from_date');
        $this->get('/attendance?state=bogus')->assertSessionHasErrors('state');
    }

    public function test_export_matches_the_screen_rows_and_state_filter(): void
    {
        $this->present('2026-11-24');
        $all = $this->get('/attendance/export')->assertOk()->streamedContent();
        // Synthesized absence rows appear in the file, clearly marked as derived.
        $this->assertStringContainsString('غائب (محتسَب)', $all);
        $this->assertStringContainsString('Mona Page', $all);
        $absentOnly = $this->get('/attendance/export?state=absent')->streamedContent();
        $this->assertStringContainsString('غائب', $absentOnly);
        $this->assertStringNotContainsString('حاضر', $absentOnly);
        $presentOnly = $this->get('/attendance/export?state=present')->streamedContent();
        $this->assertStringNotContainsString('غائب', $presentOnly);
    }

    public function test_pagination_links_preserve_the_state_filter(): void
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        Employee::create(['employee_code' => 'P2', 'first_name' => 'Omar', 'full_name' => 'Omar Second', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        $html = $this->get('/attendance?state=absent')->assertOk()->getContent();
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString('state=absent', $html);
    }

    public function test_record_before_hire_is_not_labeled_pending(): void
    {
        $this->employee->update(['hire_date' => '2026-11-10']);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-05', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('قبل التعيين', $html);
        $this->assertDoesNotMatchRegularExpression('/data-attendance-id="\d+"[^>]*>.*?لم يُسجَّل بعد/s', $html);
    }

    public function test_record_after_termination_is_not_labeled_pending(): void
    {
        $this->employee->update(['termination_date' => '2026-11-20']);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-22', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('بعد نهاية الخدمة', $html);
    }

    public function test_cancelled_record_behaves_like_rejected(): void
    {
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Cancelled', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('(ملغي)', $html);
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-rejected"/', $html);
        // A cancelled record is not a fingerprint either: absence is still synthesized.
        $this->assertContains('2026-11-24', $this->absentDates($html));
    }

    public function test_past_not_recorded_day_keeps_raw_status_not_pending(): void
    {
        $suspended = EmployeeStatus::firstOrCreate(['name' => 'Suspended']);
        $this->employee->update(['status_id' => $suspended->id]);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'status' => 'Draft', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<tr class="attendance-row-pending"/', $html);
        $this->assertDoesNotMatchRegularExpression('/badge-soft \\w+">لم يُسجَّل بعد</', $html);
    }

    public function test_page_has_no_big_heading_and_toolbar_actions_are_static(): void
    {
        $html = $this->get('/attendance')->assertOk()->getContent();
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<h1>الحضور والانصراف</h1>', $html);
        // Static toolbar: create button opens the modal, export is a plain link, import opens its modal.
        $this->assertStringContainsString('data-bs-target="#attendanceModal"', $html);
        $this->assertStringContainsString('data-bs-target="#attendanceImportModal"', $html);
        $this->assertMatchesRegularExpression('/<a class="btn btn-light" href="[^"]*attendance\\/export[^"]*"/', $html);
    }

    public function test_export_link_carries_the_current_filters(): void
    {
        $html = $this->get('/attendance?state=late&from_date=2026-11-01&to_date=2026-11-26')->assertOk()->getContent();
        preg_match('/href="([^"]*attendance\/export[^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'export link exists');
        $href = html_entity_decode($m[1]);
        $this->assertStringContainsString('state=late', $href);
        $this->assertStringContainsString('from_date=2026-11-01', $href);
        $this->assertStringContainsString('to_date=2026-11-26', $href);
    }

    public function test_advanced_filters_collapsed_by_default_and_open_when_active(): void
    {
        $html = $this->get('/attendance')->assertOk()->getContent();
        preg_match('/<div class="attendance-filter-extra"[^>]*>/', $html, $m);
        $this->assertStringContainsString('hidden', $m[0]);
        $this->assertMatchesRegularExpression('/<button type="button" class="attendance-more-filters" data-target="attendanceFilterExtra" aria-expanded="false">/', $html);

        $html = $this->get('/attendance?from_date=2026-11-01&to_date=2026-11-26')->assertOk()->getContent();
        preg_match('/<div class="attendance-filter-extra"[^>]*>/', $html, $m);
        $this->assertStringNotContainsString('hidden', $m[0]);
        $this->assertMatchesRegularExpression('/class="attendance-more-filters active"/', $html);
        $this->assertStringContainsString('aria-expanded="true"', $html);
    }

    public function test_today_cards_show_live_counts_from_todays_records(): void
    {
        $this->present('2026-11-26', '09:12', '17:00', 'Late', 12);
        $html = $this->get('/attendance')->assertOk()->getContent();
        $this->assertStringContainsString('حاضر اليوم</span><b>1</b>', $html);
        $this->assertStringContainsString('غياب اليوم</span><b>0</b>', $html);
        $this->assertStringContainsString('متأخرون</span><b>1</b>', $html);
        $this->assertStringContainsString('متوسط الحضور</span><b>09:12</b>', $html);
        $this->assertStringContainsString('اليوم —', $html); // scope label above the cards
    }

    public function test_filters_auto_apply_without_view_or_clear_buttons(): void
    {
        $html = $this->get('/attendance')->assertOk()->getContent();
        // No manual submit/clear controls: every change applies immediately.
        $this->assertStringNotContainsString('<i class="bi bi-funnel"></i> عرض</button>', $html);
        $this->assertStringNotContainsString('>مسح</a>', $html);
        // Server-side search on type (layout client-side row filter opted out), debounced wiring present.
        $this->assertStringContainsString('data-server-live-search="true"', $html);
        $this->assertStringContainsString('attendanceSearchTimer', $html);
        $this->assertStringContainsString('attendanceFiltersForm.querySelectorAll', $html);
    }
}
