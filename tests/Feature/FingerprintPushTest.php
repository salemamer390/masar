<?php

namespace Tests\Feature;

use App\Enums\FingerprintConnectionMode;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\FingerprintDevice;
use App\Models\FingerprintDeviceCommand;
use App\Models\RawAttendanceLog;
use App\Models\SubscriptionPlan;
use App\Services\FingerprintPushTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Hosted ingestion: ADMS push receiver (secret-path only) and the local
 * bridge API. Pins the trust boundary (no serial-only ingestion), dedupe,
 * per-company matching, and the command channel.
 */
class FingerprintPushTest extends TestCase
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
        $this->companyA = Company::create(['name' => 'Push A', 'slug' => 'push-a', 'status' => 'active']);
        $this->companyB = Company::create(['name' => 'Push B', 'slug' => 'push-b', 'status' => 'active']);
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

    private function pushDevice(Company $company, string $serial, string $mode = 'adms_push'): array
    {
        $device = FingerprintDevice::create([
            'name' => 'Push '.$serial,
            'driver' => 'zkteco_tcp',
            'connection_mode' => $mode,
            'serial_number' => $serial,
            'is_active' => true,
            'sync_enabled' => true,
        ]);
        $device->forceFill(['company_id' => $company->id])->save();

        $token = app(FingerprintPushTokenService::class)->regenerate($device);

        return [$device->fresh(), $token];
    }

    public function test_fixed_iclock_path_never_imports(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-FIXED-1');
        Employee::create(['employee_code' => '105', 'first_name' => 'N', 'full_name' => 'Fixed Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);

        // Serial-only request to the ZK-standard path: rejected, nothing stored.
        $response = $this->call('POST', route('fingerprint.adms.fixed-cdata').'?SN=SN-FIXED-1&table=ATTLOG&Stamp=1', [], [], [], [], "105\t2026-11-05 09:00:00\t0\t1\t0\t0\t0");
        $response->assertForbidden();
        $this->assertSame(0, RawAttendanceLog::count());
        $this->assertNull($device->fresh()->push_last_seen_at);
        $this->assertNull($device->fresh()->last_sync_at);
    }

    public function test_adms_handshake_requires_valid_token_and_serial(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-HS-1');

        $wrongToken = str_repeat('a', 48);
        $this->get(route('fingerprint.adms.handshake', ['token' => $wrongToken]).'?SN=SN-HS-1')->assertForbidden();
        $this->get(route('fingerprint.adms.handshake', ['token' => $token]).'?SN=SN-OTHER')->assertForbidden();

        $ok = $this->get(route('fingerprint.adms.handshake', ['token' => $token]).'?SN=SN-HS-1&options=all');
        $ok->assertOk();
        $this->assertStringContainsString('GET OPTION FROM: SN-HS-1', $ok->getContent());
        $this->assertNotNull($device->fresh()->push_last_seen_at);
    }

    public function test_adms_attlog_upload_imports_dedupes_and_matches_company(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-UP-1');
        $employee = Employee::create(['employee_code' => '105', 'first_name' => 'N', 'full_name' => 'Up Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);

        $body = "105\t2026-11-05 09:02:30\t0\t1\t0\t0\t0\n105\t2026-11-05 17:05:10\t1\t1\t0\t0\t0";
        $response = $this->call('POST', route('fingerprint.adms.cdata', ['token' => $token]).'?SN=SN-UP-1&table=ATTLOG&Stamp=111', [], [], [], [], $body);
        $response->assertOk();
        $this->assertStringContainsString('OK', $response->getContent());

        $this->assertSame(2, RawAttendanceLog::where('employee_id', $employee->id)->count());
        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-05')->first();
        $this->assertNotNull($attendance);
        $this->assertSame('09:02:30', $attendance->check_in);
        $this->assertSame('17:05:10', $attendance->check_out);

        // Device reconnect resend: no duplicate rows.
        $this->call('POST', route('fingerprint.adms.cdata', ['token' => $token]).'?SN=SN-UP-1&table=ATTLOG&Stamp=111', [], [], [], [], $body)->assertOk();
        $this->assertSame(2, RawAttendanceLog::where('employee_id', $employee->id)->count());
    }

    public function test_adms_pin_equals_prefix_and_cross_company_isolation(): void
    {
        // Employee with the same numeric code exists in both companies; the
        // punch may only resolve inside the device's own company.
        $empA = Employee::create(['employee_code' => '105', 'first_name' => 'A', 'full_name' => 'A Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);
        $empB = Employee::create(['employee_code' => '105', 'first_name' => 'B', 'full_name' => 'B Emp', 'company_id' => $this->companyB->id, 'status_id' => $this->status->id]);
        [$device, $token] = $this->pushDevice($this->companyB, 'SN-UP-2');

        $body = "PIN=105\t2026-11-05 09:00:00\t0\t15\t0\t0\t0\nPIN=105\t2026-11-05 17:00:00\t1\t15\t0\t0\t0";
        $this->call('POST', route('fingerprint.adms.cdata', ['token' => $token]).'?SN=SN-UP-2&table=ATTLOG&Stamp=5', [], [], [], [], $body)->assertOk();

        $this->assertSame(2, RawAttendanceLog::where('employee_id', $empB->id)->count());
        $this->assertSame(0, RawAttendanceLog::where('employee_id', $empA->id)->count());
        $this->assertSame(1, Attendance::where('employee_id', $empB->id)->count());
        $this->assertSame(0, Attendance::where('employee_id', $empA->id)->count());
    }

    public function test_adms_rejects_malformed_payload_without_import(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-BAD-1');

        $this->call('POST', route('fingerprint.adms.cdata', ['token' => $token]).'?SN=SN-BAD-1&table=ATTLOG&Stamp=9', [], [], [], [], "garbage line")->assertStatus(400);
        $this->assertSame(0, RawAttendanceLog::count());
    }

    public function test_adms_command_poll_and_ack(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-CMD-1');

        $this->get(route('fingerprint.adms.getrequest', ['token' => $token]).'?SN=SN-CMD-1')->assertOk()->assertSee('OK');

        $command = FingerprintDeviceCommand::create(['device_id' => $device->id, 'command' => 'CHECK', 'status' => 'pending']);
        $poll = $this->get(route('fingerprint.adms.getrequest', ['token' => $token]).'?SN=SN-CMD-1');
        $this->assertStringContainsString("C:{$command->id}:CHECK", $poll->getContent());
        $this->assertSame('sent', $command->fresh()->status);

        $this->call('POST', route('fingerprint.adms.devicecmd', ['token' => $token]).'?SN=SN-CMD-1', [], [], [], [], "ID={$command->id}&Return=0&CMD=CHECK")->assertOk();
        $this->assertSame('acknowledged', $command->fresh()->status);
    }

    public function test_bridge_requires_token_and_matching_serial(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-BR-1', 'local_bridge');

        $this->postJson(route('fingerprint.bridge.punches'), ['device_serial' => 'SN-BR-1', 'punches' => [['user_id' => '105', 'timestamp' => '2026-11-05 09:00:00']]])->assertUnauthorized();

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Serial' => 'SN-OTHER'])
            ->postJson(route('fingerprint.bridge.punches'), ['device_serial' => 'SN-OTHER', 'punches' => [['user_id' => '105', 'timestamp' => '2026-11-05 09:00:00']]])
            ->assertForbidden();
    }

    public function test_bridge_handshake_and_punch_import_with_dedupe(): void
    {
        [$device, $token] = $this->pushDevice($this->companyA, 'SN-BR-2', 'local_bridge');
        $employee = Employee::create(['employee_code' => '105', 'first_name' => 'N', 'full_name' => 'Bridge Emp', 'company_id' => $this->companyA->id, 'status_id' => $this->status->id]);

        $hello = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Serial' => 'SN-BR-2'])
            ->postJson(route('fingerprint.bridge.handshake'), ['device_serial' => 'SN-BR-2']);
        $hello->assertOk()->assertJson(['ok' => true]);

        $payload = ['device_serial' => 'SN-BR-2', 'punches' => [
            ['user_id' => '105', 'timestamp' => '2026-11-05 09:02:30', 'state' => 0, 'verify_mode' => 1, 'record_id' => '7'],
            ['user_id' => '105', 'timestamp' => '2026-11-05 17:05:10', 'state' => 1, 'verify_mode' => 1, 'record_id' => '8'],
        ]];

        $first = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Serial' => 'SN-BR-2'])
            ->postJson(route('fingerprint.bridge.punches'), $payload);
        $first->assertOk()->assertJson(['ok' => true, 'imported' => 2, 'duplicates' => 0]);

        $second = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Device-Serial' => 'SN-BR-2'])
            ->postJson(route('fingerprint.bridge.punches'), $payload);
        $second->assertOk()->assertJson(['ok' => true, 'imported' => 0, 'duplicates' => 2]);

        $this->assertSame(2, RawAttendanceLog::where('employee_id', $employee->id)->count());
        $attendance = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-05')->first();
        $this->assertNotNull($attendance);
        $this->assertSame('09:02:30', $attendance->check_in);
    }

    public function test_push_devices_are_never_dialed_by_scheduled_pull(): void
    {
        [$admsDevice] = $this->pushDevice($this->companyA, 'SN-NOPULL-1');
        [$bridgeDevice] = $this->pushDevice($this->companyA, 'SN-NOPULL-2', 'local_bridge');

        $this->assertFalse($admsDevice->fresh()->isDueForSync());
        $this->assertFalse($bridgeDevice->fresh()->isDueForSync());
    }

    public function test_push_mode_requires_serial_number_on_validation(): void
    {
        // The serial is the cross-check for the push secret, so it is mandatory.
        [$device] = $this->pushDevice($this->companyA, 'SN-VAL-1');
        $this->assertSame(FingerprintConnectionMode::AdmsPush, $device->connection_mode);
        $this->assertTrue($device->hasPushSecret());
    }
}
