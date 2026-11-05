<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Permission, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class FingerprintDevicesIndexModalTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    private function manager(bool $withFingerprintPermissions = true): User
    {
        $this->seq++;
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'fingerprint-modal-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'FingerprintModal', 'slug' => 'fingerprint-modal-'.$this->seq.'-'.uniqid(), 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        // company_manager bypasses permission checks (owner-level role); a plain
        // role name keeps the no-permission case honest.
        $role = Role::create(['name' => $withFingerprintPermissions ? 'company_manager' : 'staff', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'fingerprint-modal-'.$this->seq.'-'.uniqid().'@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        if ($withFingerprintPermissions) {
            foreach (Permission::where('name', 'like', 'fingerprint%')->pluck('id') as $permissionId) {
                DB::table('user_permissions')->insert(['user_id' => $user->id, 'permission_id' => $permissionId]);
            }
        }
        return $user;
    }

    public function test_index_renders_add_device_modal_with_full_form(): void
    {
        $this->actingAs($this->manager());
        $html = $this->get(route('fingerprint.devices.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-bs-target="#addDeviceModal"', $html);
        $this->assertStringContainsString('id="addDeviceModal"', $html);
        $this->assertStringContainsString('action="'.route('fingerprint.devices.store').'"', $html);
        foreach (['name="_token"', 'name="name"', 'name="device_type"', 'name="driver"', 'name="ip_address"', 'name="port"', 'name="communication_key"', 'name="serial_number"', 'name="physical_location"', 'name="timezone"', 'name="sync_interval_minutes"', 'name="sync_enabled"', 'name="is_active"', 'name="notes"'] as $field) {
            $this->assertStringContainsString($field, $html, "missing $field");
        }
        // no validation failure -> modal must stay closed
        $this->assertStringNotContainsString("new bootstrap.Modal(document.getElementById('addDeviceModal'))", $html);
    }

    public function test_failed_store_validation_returns_to_index_and_reopens_modal_with_old_input(): void
    {
        $this->actingAs($this->manager());

        $response = $this->from(route('fingerprint.devices.index'))->post(route('fingerprint.devices.store'), [
            'name' => 'جهاز التجربة',
            'device_type' => 'zkteco',
            'driver' => 'zkteco_tcp',
            'ip_address' => 'abc',
            'port' => 'x',           // not an integer -> validation fails
            'timezone' => 'Africa/Cairo',
            'sync_enabled' => '1',
            'sync_interval_minutes' => '15',
            'is_active' => '1',
        ]);
        $response->assertRedirect(route('fingerprint.devices.index'));
        $response->assertSessionHasErrors(['port']);
        $response->assertSessionDoesntHaveErrors(['name', 'ip_address']);
        $this->assertDatabaseMissing('fingerprint_devices', ['name' => 'جهاز التجربة']);

        $html = $this->get(route('fingerprint.devices.index'))->assertOk()->getContent();
        $this->assertStringContainsString("new bootstrap.Modal(document.getElementById('addDeviceModal'))", $html);
        $this->assertStringContainsString('value="جهاز التجربة"', $html);
        $this->assertStringContainsString('value="abc"', $html);
    }

    public function test_valid_modal_post_creates_device_with_driver(): void
    {
        $user = $this->manager();
        $this->actingAs($user);

        $response = $this->from(route('fingerprint.devices.index'))->post(route('fingerprint.devices.store'), [
            'name' => 'جهاز البوابة',
            'device_type' => 'zkteco',
            'driver' => 'zkteco_tcp',
            'ip_address' => '192.168.1.50',
            'port' => '4370',
            'timezone' => 'Africa/Cairo',
            'sync_enabled' => '1',
            'sync_interval_minutes' => '15',
            'is_active' => '1',
        ]);

        $device = \App\Models\FingerprintDevice::where('name', 'جهاز البوابة')->first();
        $this->assertNotNull($device, 'device was not persisted');
        $this->assertSame('zkteco_tcp', $device->driver instanceof \App\Enums\FingerprintDeviceDriver ? $device->driver->value : $device->driver);
        $this->assertSame($user->company_id, $device->company_id, 'device must belong to the posting user company');
        $response->assertRedirect(route('fingerprint.devices.show', $device));
        $response->assertSessionHasNoErrors();
    }

    public function test_unrelated_errors_do_not_reopen_modal(): void
    {
        $this->actingAs($this->manager());
        $bag = new ViewErrorBag();
        $bag->put('default', new MessageBag(['month' => ['خطأ في نموذج آخر']]));

        $html = $this->withSession(['errors' => $bag])->get(route('fingerprint.devices.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="addDeviceModal"', $html);
        $this->assertStringNotContainsString("new bootstrap.Modal(document.getElementById('addDeviceModal'))", $html);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('fingerprint.devices.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_fingerprint_permission_cannot_open_index(): void
    {
        $this->actingAs($this->manager(false));
        $response = $this->get(route('fingerprint.devices.index'));
        $response->assertRedirect(route('access.denied'));
    }
}
