<?php

namespace Tests\Feature;

use App\Data\FingerprintRawPunch;
use App\Enums\RawAttendanceType;
use App\Models\{Attendance, AttendanceImportDay, AttendancePeriodArchive, AttendancePunchRevision, Company, CompanySubscription, Employee, EmployeeStatus, RawAttendanceLog, Role, SubscriptionPlan, User};
use App\Services\FingerprintAttendanceSyncService;
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class FingerprintExceptionsSyncTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'fp-exceptions-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'FP Exceptions', 'slug' => 'fp-exceptions', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'fp-exceptions@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        $this->actingAs($user);
        $this->employee = Employee::create(['employee_code' => 'FP1', 'first_name' => 'Test', 'full_name' => 'FP Employee', 'company_id' => $company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function punch(string $at, RawAttendanceType $type): void
    {
        $method = new \ReflectionMethod(FingerprintAttendanceSyncService::class, 'processPunch');
        $method->invoke(app(FingerprintAttendanceSyncService::class), $this->employee, new FingerprintRawPunch('FP1', \Carbon\CarbonImmutable::parse($at), $type));
    }

    public function test_multi_punch_day_parks_in_exceptions_and_keeps_every_raw_punch(): void
    {
        $this->punch('2026-11-01 09:00', RawAttendanceType::CheckIn);
        $this->punch('2026-11-01 17:00', RawAttendanceType::CheckOut);
        $this->assertSame('17:00:00', Attendance::firstOrFail()->check_out);
        $this->punch('2026-11-01 18:00', RawAttendanceType::CheckOut);
        $this->assertSame(3, RawAttendanceLog::count());
        $day = AttendanceImportDay::firstOrFail();
        $this->assertSame('pending', $day->resolution);
        $this->assertSame('extra', $day->issue);
        $this->assertCount(1, $day->original_punches); // original is frozen at first parking
        $this->assertCount(3, $day->current_punches);
        // The attendance committed while the day was regular is left for the reviewer.
        $this->assertSame('17:00:00', Attendance::firstOrFail()->check_out);
        $this->assertTrue(AttendancePunchRevision::where('action', 'sync-append')->exists());
    }

    public function test_missing_checkout_parks_then_auto_resolves_when_the_exit_punch_arrives(): void
    {
        $this->punch('2026-11-01 09:00', RawAttendanceType::CheckIn);
        $this->assertSame(0, Attendance::count());
        $day = AttendanceImportDay::firstOrFail();
        $this->assertSame('pending', $day->resolution);
        $this->assertSame('missing', $day->issue);

        $this->punch('2026-11-01 17:00', RawAttendanceType::CheckOut);
        $day = $day->fresh();
        $this->assertSame('resolved', $day->resolution);
        $this->assertNull($day->issue);
        $record = Attendance::firstOrFail();
        $this->assertSame('09:00:00', $record->check_in);
        $this->assertSame('17:00:00', $record->check_out);
        $this->assertSame($record->id, $day->attendance_id);
        $this->assertTrue(AttendancePunchRevision::where('action', 'sync-resolve')->exists());
    }

    public function test_reviewer_exclusion_is_never_recreated_by_later_syncs(): void
    {
        $this->punch('2026-11-01 09:00', RawAttendanceType::CheckIn);
        $this->punch('2026-11-01 17:00', RawAttendanceType::CheckOut);
        $day = AttendanceImportDay::firstOrFail();
        $day->update(['resolution' => 'deleted']);
        $this->punch('2026-11-01 18:00', RawAttendanceType::CheckOut);
        $this->assertSame(1, AttendanceImportDay::count());
        $this->assertSame('deleted', $day->fresh()->resolution);
        $this->assertSame('17:00:00', Attendance::firstOrFail()->check_out);
        $this->assertSame(3, RawAttendanceLog::count());
    }

    public function test_closed_period_keeps_the_raw_punch_without_touching_attendance_or_exceptions(): void
    {
        AttendancePeriodArchive::create(['company_id' => $this->employee->company_id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => AttendancePeriodArchive::CLOSED, 'closes_on' => '2026-11-01', 'rows' => [], 'record_count' => 0, 'employee_count' => 0, 'settings' => [], 'captured_at' => now()]);
        $this->punch('2026-11-01 09:00', RawAttendanceType::CheckIn);
        $this->assertSame(1, RawAttendanceLog::count());
        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, AttendanceImportDay::count());
    }

    public function test_reviewers_correction_is_never_overwritten_by_a_later_regular_sync(): void
    {
        $this->punch('2026-11-01 09:00', RawAttendanceType::CheckIn);
        $day = AttendanceImportDay::firstOrFail();
        // Reviewer corrects the day by hand: picks their own times, saves, day resolved.
        $corrected = Attendance::create(['employee_id' => $this->employee->id, 'attendance_date' => '2026-11-01', 'check_in' => '10:00:00', 'check_out' => '16:00:00', 'status' => 'Present', 'source' => 'import', 'approval_status' => 'Approved']);
        $day->update(['resolution' => 'resolved', 'issue' => null, 'attendance_id' => $corrected->id]);

        // The real exit punch arrives later: raw punches now look regular.
        $this->punch('2026-11-01 17:00', RawAttendanceType::CheckOut);

        $record = Attendance::firstOrFail();
        $this->assertSame('10:00:00', $record->check_in);
        $this->assertSame('16:00:00', $record->check_out);
        $this->assertSame('import', $record->source);
        $day = $day->fresh();
        $this->assertSame('resolved', $day->resolution);
        $this->assertTrue(AttendancePunchRevision::where('import_day_id', $day->id)->where('action', 'sync-append')->exists());
    }

    public function test_overnight_shift_exit_punch_auto_resolves_the_shift_start_day(): void
    {
        $this->employee->update(['shift_start' => '22:00', 'shift_end' => '06:00']);
        $this->punch('2026-11-01 22:00', RawAttendanceType::CheckIn);
        $day = AttendanceImportDay::firstOrFail();
        $this->assertSame('2026-11-01', $day->work_date->toDateString());
        $this->assertSame('missing', $day->issue);

        $this->punch('2026-11-02 05:00', RawAttendanceType::CheckOut);
        $record = Attendance::firstOrFail();
        $this->assertSame('2026-11-01', $record->attendance_date->toDateString());
        $this->assertSame('22:00:00', $record->check_in);
        $this->assertSame('05:00:00', $record->check_out);
        $this->assertSame('resolved', $day->fresh()->resolution);
    }
}
