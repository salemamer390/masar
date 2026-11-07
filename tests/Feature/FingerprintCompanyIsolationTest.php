<?php

namespace Tests\Feature;

use App\Contracts\Fingerprint\FingerprintDeviceDriverInterface;
use App\Data\FingerprintRawPunch;
use App\Enums\RawAttendanceType;
use App\Models\Attendance;
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
 * Fingerprint sync must attribute punches only to the company that owns the
 * device. Employee codes are unique per company only, so the same code can
 * exist in two companies; the scheduled sync runs in console context where no
 * authenticated user activates the company global scope. These tests pin the
 * device-company scoping in that unauthenticated path.
 */
class FingerprintCompanyIsolationTest extends TestCase
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
        $this->companyA = Company::create(['name' => 'FP A', 'slug' => 'fp-a', 'status' => 'active']);
        $this->companyB = Company::create(['name' => 'FP B', 'slug' => 'fp-b', 'status' => 'active']);
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
            'ip_address' => '192.0.2.20', 'port' => 4370,
            'is_active' => true, 'sync_enabled' => true,
        ]);
        $device->forceFill(['company_id' => $company->id])->save();

        return $device;
    }

    /** Bind a fake driver returning the given punches, then run the real public sync path. */
    private function syncWithPunches(FingerprintDevice $device, array $punches)
    {
        $driver = new class($punches) implements FingerprintDeviceDriverInterface {
            public function __construct(private array $punches) {}
            public function connect(FingerprintDevice $device): bool { return true; }
            public function disconnect(): void {}
            public function testConnection(FingerprintDevice $device, int $timeoutSeconds = 5): array { return ['ok' => true]; }
            public function getDeviceInfo(FingerprintDevice $device, int $timeoutSeconds = 5): array { return []; }
            public function pullAttendance(FingerprintDevice $device, int $timeoutSeconds = 10, int $retries = 1): iterable { return $this->punches; }
        };
        $resolver = new class($driver) extends DeviceDriverResolverService {
            public function __construct(private $driver) {}
            public function resolve(FingerprintDevice $device): FingerprintDeviceDriverInterface { return $this->driver; }
        };
        $this->app->instance(DeviceDriverResolverService::class, $resolver);

        return app(FingerprintAttendanceSyncService::class)->syncDevice($device);
    }

    private function dayPunches(string $deviceUserId): array
    {
        $today = now()->format('Y-m-d');

        return [
            new FingerprintRawPunch($deviceUserId, CarbonImmutable::parse("$today 08:00:00"), RawAttendanceType::CheckIn),
            new FingerprintRawPunch($deviceUserId, CarbonImmutable::parse("$today 17:00:00"), RawAttendanceType::CheckOut),
        ];
    }

    public function test_sync_attributes_shared_code_only_to_device_company(): void
    {
        // Company B's employee exists first; company A's same-code employee is
        // newer, the ordering that previously captured the punch map key.
        $empB = Employee::create(['employee_code' => 'FP100', 'first_name' => 'B', 'full_name' => 'FP Employee B', 'company_id' => $this->companyB->id, 'status_id' => $this->status->id]);
        $empA = Employee::create(['employee_code' => 'FP100', 'first_name' => 'A', 'full_name' => 'FP Employee A', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $device = $this->deviceFor($this->companyB);

        $log = $this->syncWithPunches($device, $this->dayPunches('FP100'));

        $this->assertSame('success', $log->status->value ?? $log->status);
        // Own-company employee matched and a complete day committed.
        $this->assertSame(1, Attendance::where('employee_id', $empB->id)->where('company_id', $this->companyB->id)->count());
        // The other company's same-code employee is untouched.
        $this->assertSame(0, Attendance::where('employee_id', $empA->id)->count());
        $this->assertSame(0, RawAttendanceLog::where('employee_id', $empA->id)->count());
        $this->assertSame(2, RawAttendanceLog::where('employee_id', $empB->id)->count());
    }

    public function test_sync_keeps_unknown_device_users_unmatched(): void
    {
        $empB = Employee::create(['employee_code' => 'FP300', 'first_name' => 'B', 'full_name' => 'FP Employee B3', 'company_id' => $this->companyB->id, 'status_id' => $this->status->id]);
        $device = $this->deviceFor($this->companyB);

        $log = $this->syncWithPunches($device, $this->dayPunches('NO-SUCH-USER'));

        $this->assertSame(2, RawAttendanceLog::whereNull('employee_id')->where('raw_device_user_id', 'NO-SUCH-USER')->count());
        $this->assertSame(0, Attendance::where('employee_id', $empB->id)->count());
        $this->assertSame(2, (int) ($log->metadata['unresolved_count'] ?? 0)); // one per unmatched punch
    }

    public function test_sync_matches_fingerprint_id_within_device_company(): void
    {
        Employee::create(['employee_code' => 'X1', 'fingerprint_id' => 'D-77', 'first_name' => 'A', 'full_name' => 'FP Other Co', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $empB = Employee::create(['employee_code' => 'FP400', 'fingerprint_id' => 'D-77', 'first_name' => 'B', 'full_name' => 'FP Employee B4', 'company_id' => $this->companyB->id, 'status_id' => $this->status->id]);
        $device = $this->deviceFor($this->companyB);

        $this->syncWithPunches($device, $this->dayPunches('D-77'));

        $this->assertSame(1, Attendance::where('employee_id', $empB->id)->where('company_id', $this->companyB->id)->count());
        $this->assertSame(2, RawAttendanceLog::where('employee_id', $empB->id)->count());
        $this->assertSame(0, Attendance::whereHas('employee', fn ($q) => $q->where('company_id', $this->companyA->id))->count());
    }
}
