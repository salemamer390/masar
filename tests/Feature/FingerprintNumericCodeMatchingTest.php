<?php

namespace Tests\Feature;

use App\Contracts\Fingerprint\FingerprintDeviceDriverInterface;
use App\Data\FingerprintRawPunch;
use App\Enums\RawAttendanceType;
use App\Models\Attendance;
use App\Models\AttendanceImportDay;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\FingerprintDevice;
use App\Models\RawAttendanceLog;
use App\Models\SubscriptionPlan;
use App\Services\DeviceDriverResolverService;
use App\Services\FingerprintAttendanceSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Devices report numeric user IDs (e.g. 105). The employee match map must keep
 * numeric codes as keys: the previous flatMap/collapse construction re-indexed
 * them through array_merge and every numeric-coded punch stayed unresolved.
 * These tests pin numeric-code matching and the retained review policy for
 * days whose punches cannot be paired (no first/last inference).
 */
class FingerprintNumericCodeMatchingTest extends TestCase
{
    use DatabaseTransactions;

    private Company $companyA;
    private Company $companyB;
    private EmployeeStatus $status;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );
        $this->companyA = Company::create(['name' => 'Num A', 'slug' => 'num-a', 'status' => 'active']);
        $this->companyB = Company::create(['name' => 'Num B', 'slug' => 'num-b', 'status' => 'active']);
        foreach ([$this->companyA, $this->companyB] as $company) {
            CompanySubscription::create([
                'company_id' => $company->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => today(),
            ]);
        }
        $this->status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
    }

    private function deviceFor(Company $company): FingerprintDevice
    {
        // company_id is not mass-assignable; in production the saving hook
        // stamps it from the authenticated user. Tests run without auth.
        $device = FingerprintDevice::create([
            'name' => 'Device '.$company->slug, 'driver' => 'zkteco_tcp',
            'ip_address' => '192.0.2.40', 'port' => 4370,
            'is_active' => true, 'sync_enabled' => true,
        ]);
        $device->forceFill(['company_id' => $company->id])->save();

        return $device;
    }

    private function punch(string $userId, string $at, RawAttendanceType $type, int $state, string $recordId): FingerprintRawPunch
    {
        return new FingerprintRawPunch(
            rawDeviceUserId: $userId,
            punchedAt: CarbonImmutable::parse($at),
            rawType: $type,
            deviceRecordId: $recordId,
            punchState: $state,
        );
    }

    private function syncWith(FingerprintDevice $device, array $punches)
    {
        $driver = new class($punches) implements FingerprintDeviceDriverInterface {
            public function __construct(private array $punches) {}
            public function connect(FingerprintDevice $device): bool { return true; }
            public function disconnect(): void {}
            public function testConnection(FingerprintDevice $device, int $timeoutSeconds = 5): array { return ['ok' => true]; }
            public function getDeviceInfo(FingerprintDevice $device, int $timeoutSeconds = 5): array { return []; }
            public function pullAttendance(FingerprintDevice $device, int $timeoutSeconds = 10, $retries = 1): iterable { return $this->punches; }
        };
        $resolver = new class($driver) extends DeviceDriverResolverService {
            public function __construct(private $d) {}
            public function resolve(FingerprintDevice $device): FingerprintDeviceDriverInterface { return $this->d; }
        };
        $this->app->instance(DeviceDriverResolverService::class, $resolver);

        return app(FingerprintAttendanceSyncService::class)->syncDevice($device->fresh());
    }

    public function test_numeric_employee_code_matches_and_commits_attendance(): void
    {
        $employee = Employee::create(['employee_code' => '105', 'first_name' => 'N', 'full_name' => 'Num Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $device = $this->deviceFor($this->companyA);

        $log = $this->syncWith($device, [
            $this->punch('105', '2026-11-05 09:02:30', RawAttendanceType::CheckIn, 0, '7'),
            $this->punch('105', '2026-11-05 17:05:10', RawAttendanceType::CheckOut, 1, '8'),
        ]);

        $this->assertSame('success', $log->status->value);
        $this->assertSame(0, (int) ($log->metadata['unresolved_count'] ?? 0));
        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-05')->first();
        $this->assertNotNull($attendance);
        $this->assertSame('09:02:30', $attendance->check_in);
        $this->assertSame('17:05:10', $attendance->check_out);
        $this->assertSame(2, RawAttendanceLog::where('employee_id', $employee->id)->count());
    }

    public function test_numeric_code_shared_across_companies_resolves_to_device_company(): void
    {
        // Same numeric code in both companies (allowed: unique per company);
        // company A's employee is created later (higher id).
        $empA = Employee::create(['employee_code' => '105', 'first_name' => 'A', 'full_name' => 'Num A Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $empB = Employee::create(['employee_code' => '105', 'first_name' => 'B', 'full_name' => 'Num B Emp', 'company_id' => $this->companyB->id, 'status_id' => $this->status->id]);
        $empA->created_at = now()->addMinute();
        $empA->save();
        $device = $this->deviceFor($this->companyB);

        $this->syncWith($device, [
            $this->punch('105', '2026-11-05 09:00:00', RawAttendanceType::CheckIn, 0, '11'),
            $this->punch('105', '2026-11-05 17:00:00', RawAttendanceType::CheckOut, 1, '12'),
        ]);

        $this->assertSame(1, Attendance::where('employee_id', $empB->id)->count());
        $this->assertSame(0, Attendance::where('employee_id', $empA->id)->count());
        $this->assertSame(2, RawAttendanceLog::where('employee_id', $empB->id)->count());
        $this->assertSame(0, RawAttendanceLog::where('employee_id', $empA->id)->count());
    }

    public function test_numeric_fingerprint_id_matches(): void
    {
        $employee = Employee::create(['employee_code' => 'E-9001', 'fingerprint_id' => '9001', 'first_name' => 'F', 'full_name' => 'Fid Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $device = $this->deviceFor($this->companyA);

        $this->syncWith($device, [
            $this->punch('9001', '2026-11-05 09:10:00', RawAttendanceType::CheckIn, 0, '21'),
            $this->punch('9001', '2026-11-05 17:20:00', RawAttendanceType::CheckOut, 1, '22'),
        ]);

        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-05')->first();
        $this->assertNotNull($attendance);
        $this->assertSame('09:10:00', $attendance->check_in);
        $this->assertSame('17:20:00', $attendance->check_out);
    }

    public function test_unpairable_day_still_goes_to_exceptions_without_attendance(): void
    {
        // Device not marking check-out: both punches arrive as check-in.
        // Policy is unchanged: the day waits for review, nothing is inferred.
        $employee = Employee::create(['employee_code' => '105', 'first_name' => 'N', 'full_name' => 'Num Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $device = $this->deviceFor($this->companyA);

        $this->syncWith($device, [
            $this->punch('105', '2026-11-05 09:02:30', RawAttendanceType::CheckIn, 0, '31'),
            $this->punch('105', '2026-11-05 17:05:10', RawAttendanceType::CheckIn, 0, '32'),
        ]);

        $this->assertSame(0, Attendance::where('employee_id', $employee->id)->count());
        $day = AttendanceImportDay::where('employee_id', $employee->id)->first();
        $this->assertNotNull($day);
        $this->assertSame('pending', $day->resolution);
        $this->assertSame('missing', $day->issue);
    }
}
