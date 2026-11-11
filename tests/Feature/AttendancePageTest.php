<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
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

    private function recordCount(string $html): int
    {
        preg_match_all('/data-attendance-id="\d+"/', $html, $m);
        return count($m[0]);
    }

    public function test_only_actual_records_appear_and_unrecorded_days_stay_hidden(): void
    {
        $this->present('2026-11-24');
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertSame(1, $this->recordCount($html), 'exactly the one recorded day renders');
        $this->assertStringContainsString('24/11/2026', $html);
        // No synthesized absence or pending rows, and no per-day quick-add buttons.
        $this->assertStringNotContainsString('data-virtual', $html);
        $this->assertStringNotContainsString('attendance-quick-add', $html);
        $this->assertStringNotContainsString('attendance-row-pending', $html);
        $this->assertStringNotContainsString('لم يُسجَّل بعد', $html);
        // 21 other elapsed workdays this period produce nothing.
        preg_match_all('/<td data-label="التاريخ">(\d{2}\/\d{2}\/\d{4})<\/td>/', $html, $m);
        $this->assertSame(['24/11/2026'], $m[1]);
    }

    public function test_page_with_no_records_shows_empty_state_not_synthesized_rows(): void
    {
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertSame(0, $this->recordCount($html));
        $this->assertStringContainsString('لا توجد سجلات ضمن الفترة المحددة.', $html);
        $this->assertStringNotContainsString('data-virtual', $html);
    }

    public function test_manual_absent_record_is_listed_and_colored(): void
    {
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-absent" data-attendance-id="/', $html);
        $this->assertStringContainsString('badge-soft danger">غائب<', $html);
        preg_match('/غياب\s*<b>(\d+)<\/b>\s*يوم/', $html, $absent);
        $this->assertSame(1, (int) $absent[1]);
    }

    public function test_late_record_carries_color_class_and_label(): void
    {
        $this->present('2026-11-24', '09:45', '17:00', 'Late', 45);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-late" data-attendance-id="/', $html);
        $this->assertStringContainsString('>متأخر<', $html);
        $this->assertStringContainsString('legend-swatch legend-late', $html);
        $this->assertStringContainsString('legend-swatch legend-absent', $html);
    }

    public function test_state_filter_limits_the_list_and_not_recorded_is_rejected(): void
    {
        $this->present('2026-11-24', '09:45', '17:00', 'Late', 45);
        $this->present('2026-11-25');
        $badge = fn (string $html, string $label) => preg_match('/badge-soft \w+">'.$label.'</', $html) === 1;
        $lateOnly = $this->get('/attendance?state=late&per_page=200')->assertOk()->getContent();
        $this->assertTrue($badge($lateOnly, 'متأخر'));
        $this->assertFalse($badge($lateOnly, 'حاضر'));
        $presentOnly = $this->get('/attendance?state=present&per_page=200')->assertOk()->getContent();
        $this->assertTrue($badge($presentOnly, 'حاضر'));
        $this->assertFalse($badge($presentOnly, 'متأخر'));
        // The removed pending state is no longer a valid filter value.
        $this->get('/attendance?state=not_recorded')->assertSessionHasErrors('state');
        // The filter dropdown no longer offers it either.
        $html = $this->get('/attendance')->assertOk()->getContent();
        $this->assertStringNotContainsString('value="not_recorded"', $html);
    }

    public function test_mobile_layout_uses_plain_table_rows_not_cards(): void
    {
        $html = $this->get('/attendance')->assertOk()->getContent();
        // No card transformation on small screens: the table keeps its header
        // and plain rows with horizontal scroll, exactly like desktop.
        $this->assertStringNotContainsString('.table-panel .table thead{display:none}', $html);
        $this->assertStringNotContainsString('.table-panel .table tbody tr{display:grid', $html);
        $this->assertStringNotContainsString(".table-panel .table tbody td::before", $html);
        $this->assertStringNotContainsString('.table-panel .table{display:block', $html);
    }

    public function test_record_forms_have_no_manual_status_field_and_status_is_derived_from_times(): void
    {
        $html = $this->get('/attendance')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="status"', $html);
        $this->assertStringNotContainsString('editAttendanceStatus', $html);

        // Store derives the status from the shift times: on time = حاضر.
        $this->post('/attendance', [
            'employee_id' => $this->employee->id, 'attendance_date' => '2026-11-24',
            'check_in' => '09:00', 'check_out' => '17:00',
        ])->assertRedirect();
        $this->assertSame('Present', Attendance::where('employee_id', $this->employee->id)->whereDate('attendance_date', '2026-11-24')->value('status'));

        // Update without any status field re-derives it: past grace = متأخر.
        $record = Attendance::where('employee_id', $this->employee->id)->whereDate('attendance_date', '2026-11-24')->firstOrFail();
        $this->put('/attendance/'.$record->id, [
            'employee_id' => $this->employee->id, 'attendance_date' => '2026-11-24',
            'check_in' => '10:30', 'check_out' => '17:00',
        ])->assertRedirect();
        $this->assertSame('Late', $record->fresh()->status);
    }

    public function test_period_totals_count_actual_records_only(): void
    {
        $this->present('2026-11-24', '09:45', '17:00', 'Late', 45);
        $this->present('2026-11-25');
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-23', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        preg_match('/حضور\s*<b>(\d+)<\/b>\s*يوم/', $html, $present);
        preg_match('/تأخير\s*<b>(\d+)<\/b>\s*يوم/', $html, $late);
        preg_match('/غياب\s*<b>(\d+)<\/b>\s*يوم/', $html, $absent);
        $this->assertSame(2, (int) $present[1], 'late days count as attended, matching the monthly summary');
        $this->assertSame(1, (int) $late[1]);
        $this->assertSame(1, (int) $absent[1]);
    }

    public function test_future_dated_record_is_shown_but_not_counted_before_it_happens(): void
    {
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-28', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Approved', 'source' => 'manual']);
        $this->present('2026-11-24');
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertSame(2, $this->recordCount($html), 'the actual future-dated record stays visible');
        preg_match('/حضور\s*<b>(\d+)<\/b>\s*يوم/', $html, $present);
        $this->assertSame(1, (int) $present[1], 'a future day is not counted yet');
        $this->assertStringContainsString('حتى 26/11/2026', $html);
        $this->assertStringNotContainsString('حتى 30/11/2026', $html);
    }

    public function test_two_companies_are_isolated(): void
    {
        $other = Company::create(['name' => 'OtherCo', 'slug' => 'other-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $other->id, 'subscription_plan_id' => \App\Models\SubscriptionPlan::firstOrCreate(['slug' => 'attendance-page-unlimited'])->id, 'status' => 'active', 'starts_at' => today()]);
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $ghost = Employee::withoutEvents(fn () => Employee::create(['employee_code' => 'X9', 'first_name' => 'Ghost', 'full_name' => 'Ghost OutsideWorker', 'company_id' => $other->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']));
        Attendance::withoutEvents(fn () => Attendance::create(['employee_id' => $ghost->id, 'company_id' => $other->id, 'attendance_date' => '2026-11-24', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Approved', 'source' => 'manual']));
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringNotContainsString('Ghost OutsideWorker', $html);
        $this->assertSame(0, $this->recordCount($html));
    }

    public function test_rejected_record_is_labeled_and_not_counted(): void
    {
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Rejected', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-rejected"/', $html);
        $this->assertStringContainsString('(مرفوض)', $html);
        preg_match('/حضور\s*<b>(\d+)<\/b>\s*يوم/', $html, $present);
        $this->assertSame(0, (int) $present[1]);
        $absentOnly = $this->get('/attendance?state=absent&per_page=200')->assertOk()->getContent();
        $this->assertStringNotContainsString('(مرفوض)', $absentOnly);
    }

    public function test_cancelled_record_behaves_like_rejected(): void
    {
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Cancelled', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('(ملغي)', $html);
        $this->assertMatchesRegularExpression('/<tr class="attendance-row-rejected"/', $html);
    }

    public function test_permission_covered_late_is_not_colored_late(): void
    {
        \App\Models\RequestType::create(['code' => 'permission', 'display_name' => 'permission', 'source_key' => 'permission', 'is_active' => true, 'company_id' => $this->company->id]);
        \App\Models\HrRequest::create(['employee_id' => $this->employee->id, 'request_type' => 'permission', 'request_type_id' => \App\Models\RequestType::where('code', 'permission')->value('id'), 'title' => 'permission', 'payload' => ['request_date' => '2026-11-24', 'from_time' => '09:00', 'to_time' => '10:00'], 'status' => 'Approved', 'created_by' => $this->user->id]);
        $this->present('2026-11-24', '09:30', '17:00', 'Late', 30);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        // The permission covers the whole late window: the recorded day is present, not yellow.
        $this->assertDoesNotMatchRegularExpression('/<tr class="attendance-row-late"/', $html);
        $this->assertStringContainsString('badge-soft success">حاضر<', $html);
    }

    public function test_absent_record_on_a_leave_day_follows_the_day_classification(): void
    {
        $type = LeaveType::create(['name' => 'Annual', 'policy_code' => 'annual', 'is_paid' => true, 'is_active' => true, 'company_id' => $this->company->id]);
        Leave::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'leave_type_id' => $type->id, 'start_date' => '2026-11-24', 'end_date' => '2026-11-24', 'days' => 1, 'status' => 'Approved']);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        // The raw record says Absent, but the day is leave: no red row, no absence count.
        $this->assertDoesNotMatchRegularExpression('/<tr class="attendance-row-absent"/', $html);
        $this->assertStringContainsString('badge-soft info">إجازة<', $html);
        preg_match('/غياب\s*<b>([\d.]+)<\/b>\s*يوم/', $html, $absent);
        $this->assertSame(0.0, (float) $absent[1]);
    }

    public function test_permission_partially_covering_an_absent_record_shows_partial_absence(): void
    {
        \App\Models\RequestType::create(['code' => 'permission', 'display_name' => 'permission', 'source_key' => 'permission', 'is_active' => true, 'company_id' => $this->company->id]);
        \App\Models\HrRequest::create(['employee_id' => $this->employee->id, 'request_type' => 'permission', 'request_type_id' => \App\Models\RequestType::where('code', 'permission')->value('id'), 'title' => 'permission', 'payload' => ['request_date' => '2026-11-24', 'from_time' => '09:00', 'to_time' => '13:00'], 'status' => 'Approved', 'created_by' => $this->user->id]);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-24', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('غائب (جزئي)', $html);
        preg_match('/غياب\s*<b>([\d.]+)<\/b>\s*يوم/', $html, $absent);
        $this->assertSame(0.5, (float) $absent[1]); // 4 of 8 shift hours excused
    }

    public function test_record_before_hire_keeps_its_service_label(): void
    {
        $this->employee->update(['hire_date' => '2026-11-10']);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-05', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('قبل التعيين', $html);
        $this->assertSame(1, $this->recordCount($html));
    }

    public function test_record_after_termination_keeps_its_service_label(): void
    {
        $this->employee->update(['termination_date' => '2026-11-20']);
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-22', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $html = $this->get('/attendance?per_page=200')->assertOk()->getContent();
        $this->assertStringContainsString('بعد نهاية الخدمة', $html);
    }

    public function test_invalid_reversed_and_oversized_ranges_are_validation_errors_not_500(): void
    {
        $this->get('/attendance?from_date=not-a-date')->assertSessionHasErrors('from_date');
        $this->get('/attendance?from_date=2026-11-20&to_date=2026-11-10')->assertSessionHasErrors('to_date');
        $this->get('/attendance?from_date=2026-01-01&to_date=2026-12-31')->assertSessionHasErrors('to_date');
        $this->get('/attendance/export?from_date=not-a-date')->assertSessionHasErrors('from_date');
        $this->get('/attendance?state=bogus')->assertSessionHasErrors('state');
    }

    public function test_export_lists_actual_records_only_and_matches_the_state_filter(): void
    {
        $this->present('2026-11-24');
        Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-23', 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        $all = $this->get('/attendance/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Mona Page', $all);
        $this->assertStringContainsString('24/11/2026', $all);
        $this->assertStringContainsString('23/11/2026', $all);
        // Nothing synthesized ever leaks into the file.
        $this->assertStringNotContainsString('محتسَب', $all);
        $this->assertStringNotContainsString('لم يُسجَّل بعد', $all);
        // Two records, one header line, no absence rows invented.
        $this->assertSame(2, substr_count($all, 'Mona Page'));
        $absentOnly = $this->get('/attendance/export?state=absent')->streamedContent();
        $this->assertStringContainsString('غائب', $absentOnly);
        $this->assertStringNotContainsString('24/11/2026', $absentOnly);
        $presentOnly = $this->get('/attendance/export?state=present')->streamedContent();
        $this->assertStringNotContainsString('غائب', $presentOnly);
    }

    public function test_pagination_links_preserve_the_state_filter(): void
    {
        foreach (range(1, 30) as $day) {
            Attendance::create(['employee_id' => $this->employee->id, 'company_id' => $this->company->id, 'attendance_date' => sprintf('2026-11-%02d', $day), 'status' => 'Absent', 'approval_status' => 'Approved', 'source' => 'manual']);
        }
        $html = $this->get('/attendance?state=absent')->assertOk()->getContent();
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString('state=absent', $html);
    }

    public function test_page_has_no_big_heading_and_toolbar_actions_are_static(): void
    {
        $html = $this->get('/attendance')->assertOk()->getContent();
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<h1>الحضور والانصراف</h1>', $html);
        // Static toolbar: create button opens the modal, export is a plain link, import opens its modal.
        $this->assertStringContainsString('data-bs-target="#attendanceModal"', $html);
        $this->assertStringContainsString('data-bs-target="#attendanceImportModal"', $html);
        $this->assertMatchesRegularExpression('/<a class="btn btn-success" href="[^"]*attendance\\/export[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attendanceImportModal"/', $html);
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
