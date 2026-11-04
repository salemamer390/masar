<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\FingerprintDevice;
use App\Models\FingerprintDeviceSyncLog;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves that a company account can only read and write its own tenant's
 * data, while a company-less platform account keeps platform-wide access.
 */
class CompanyIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $companyA;
    private Company $companyB;
    private User $userA;
    private User $userB;
    private Employee $employeeA;
    private Employee $employeeB;
    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $this->statusId = $status->id;
        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );

        $this->companyA = Company::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        $this->companyB = Company::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);
        foreach ([$this->companyA, $this->companyB] as $company) {
            CompanySubscription::create([
                'company_id' => $company->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => today(),
            ]);
        }

        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager A', 'company_id' => $this->companyA->id]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager B', 'company_id' => $this->companyB->id]);

        $this->userA = User::create(['name' => 'User A', 'email' => 'a@example.test', 'password' => bcrypt('secret'), 'role_id' => $roleA->id, 'company_id' => $this->companyA->id, 'is_active' => true]);
        $this->userB = User::create(['name' => 'User B', 'email' => 'b@example.test', 'password' => bcrypt('secret'), 'role_id' => $roleB->id, 'company_id' => $this->companyB->id, 'is_active' => true]);

        $this->employeeA = Employee::create(['employee_code' => '9001', 'first_name' => 'EmpA', 'full_name' => 'Employee Alpha A', 'company_id' => $this->companyA->id, 'status_id' => $this->statusId]);
        $this->employeeB = Employee::create(['employee_code' => '9002', 'first_name' => 'EmpB', 'full_name' => 'Employee Bravo B', 'company_id' => $this->companyB->id, 'status_id' => $this->statusId]);
    }

    public function test_employee_index_lists_only_own_company(): void
    {
        $response = $this->actingAs($this->userA)->get(route('employees.index'));

        $response->assertOk();
        $response->assertSee('Employee Alpha A');
        $response->assertDontSee('Employee Bravo B');
    }

    public function test_employee_show_returns_404_for_other_company(): void
    {
        $this->actingAs($this->userA)
            ->get(route('employees.show', $this->employeeB))
            ->assertNotFound();
    }

    public function test_employee_update_cannot_touch_other_company(): void
    {
        $this->actingAs($this->userA)
            ->put(route('employees.update', $this->employeeB), ['full_name' => 'Hijacked Name'])
            ->assertNotFound();

        $this->assertSame('Employee Bravo B', $this->employeeB->fresh()->full_name);
    }

    public function test_employee_store_forces_the_acting_company(): void
    {
        $response = $this->actingAs($this->userA)->post(route('employees.store'), [
            'full_name' => 'New Hire A',
            'company_id' => $this->companyB->id,
            'employee_code' => '9010',
            'salary_source' => 'gross',
            'salary_value' => 5000,
            'status_id' => $this->statusId,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('employees', [
            'full_name' => 'New Hire A',
            'company_id' => $this->companyA->id,
        ]);
        $this->assertDatabaseMissing('employees', [
            'full_name' => 'New Hire A',
            'company_id' => $this->companyB->id,
        ]);
    }

    public function test_model_write_guard_blocks_cross_company_save(): void
    {
        $this->actingAs($this->userA);

        $foreign = Employee::withoutGlobalScopes()->findOrFail($this->employeeB->id);
        $foreign->full_name = 'Changed By A';

        $this->expectException(AuthorizationException::class);
        $foreign->save();
    }

    public function test_platform_account_keeps_platform_wide_visibility(): void
    {
        $platformRole = Role::create(['name' => 'super_admin', 'display_name' => 'Platform Admin', 'company_id' => null]);
        $platform = User::create(['name' => 'Platform', 'email' => 'platform@example.test', 'password' => bcrypt('secret'), 'role_id' => $platformRole->id, 'company_id' => null, 'is_active' => true]);

        $response = $this->actingAs($platform)->get(route('employees.index'));

        $response->assertOk();
        $response->assertSee('Employee Alpha A');
        $response->assertSee('Employee Bravo B');
    }

    public function test_company_linked_super_admin_stays_isolated(): void
    {
        $adminRole = Role::create(['name' => 'super_admin', 'display_name' => 'Admin A', 'company_id' => $this->companyA->id]);
        $adminA = User::create(['name' => 'Admin A', 'email' => 'admin-a@example.test', 'password' => bcrypt('secret'), 'role_id' => $adminRole->id, 'company_id' => $this->companyA->id, 'is_active' => true]);

        $response = $this->actingAs($adminA)->get(route('employees.index'));

        $response->assertOk();
        $response->assertSee('Employee Alpha A');
        $response->assertDontSee('Employee Bravo B');
    }

    public function test_s6_form_returns_404_for_other_company(): void
    {
        $this->actingAs($this->userA)
            ->get(route('employees.forms.s6.editable', $this->employeeB))
            ->assertNotFound();
    }

    public function test_attendance_import_skips_other_company_employees(): void
    {
        $csv = "employee_code,employee_name,date,check_in,check_out,status\n"
            . "9001,Employee Alpha A,2026-10-15,09:00,17:00,Present\n"
            . "9002,Employee Bravo B,2026-10-15,09:00,17:00,Present\n";
        $file = UploadedFile::fake()->createWithContent('attendance.csv', $csv);

        $preview = $this->actingAs($this->userA)
            ->post(route('attendance.import.preview'), ['file' => $file]);
        $preview->assertOk();
        preg_match('/name="import_token" value="([0-9a-f-]{36})"/', $preview->getContent(), $tokenMatch);
        preg_match('/name="preview_key" id="previewKey" value="([0-9a-f]{64})"/', $preview->getContent(), $keyMatch);

        $this->actingAs($this->userA)
            ->post(route('attendance.import'), ['import_token' => $tokenMatch[1], 'unit' => 'sheet-0', 'map' => [], 'options' => [], 'preview_key' => $keyMatch[1]])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('attendance', [
            'employee_id' => $this->employeeA->id,
            'attendance_date' => '2026-10-15',
        ]);
        $this->assertDatabaseMissing('attendance', [
            'employee_id' => $this->employeeB->id,
        ]);
    }

    public function test_attendance_import_token_cannot_be_used_from_another_company(): void
    {
        $csv = "employee_code,employee_name,date,check_in,check_out,status\n"
            . "9001,Employee Alpha A,2026-10-15,09:00,17:00,Present\n";
        $file = UploadedFile::fake()->createWithContent('attendance.csv', $csv);

        $preview = $this->actingAs($this->userA)
            ->post(route('attendance.import.preview'), ['file' => $file]);
        $preview->assertOk();
        preg_match('/name="import_token" value="([0-9a-f-]{36})"/', $preview->getContent(), $tokenMatch);
        preg_match('/name="preview_key" id="previewKey" value="([0-9a-f]{64})"/', $preview->getContent(), $keyMatch);

        $this->actingAs($this->userB)
            ->post(route('attendance.import'), ['import_token' => $tokenMatch[1], 'unit' => 'sheet-0', 'map' => [], 'options' => [], 'preview_key' => $keyMatch[1]])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseMissing('attendance', [
            'employee_id' => $this->employeeA->id,
            'attendance_date' => '2026-10-15',
        ]);
    }

    public function test_fingerprint_sync_logs_show_only_own_company_devices(): void
    {
        // company_id is not mass-assignable on FingerprintDevice; the trait
        // fills it from the acting account, so assign it directly here.
        $deviceA = FingerprintDevice::create(['name' => 'Device Alpha A', 'ip_address' => '10.0.0.1']);
        $deviceA->forceFill(['company_id' => $this->companyA->id])->saveQuietly();
        $deviceB = FingerprintDevice::create(['name' => 'Device Bravo B', 'ip_address' => '10.0.0.2']);
        $deviceB->forceFill(['company_id' => $this->companyB->id])->saveQuietly();
        FingerprintDeviceSyncLog::create(['device_id' => $deviceA->id, 'sync_type' => 'attendance_pull', 'status' => 'success', 'started_at' => now()]);
        FingerprintDeviceSyncLog::create(['device_id' => $deviceB->id, 'sync_type' => 'attendance_pull', 'status' => 'success', 'started_at' => now()]);

        $response = $this->actingAs($this->userA)->get(route('fingerprint.sync-logs.index'));

        $response->assertOk();
        $response->assertSee('Device Alpha A');
        $response->assertDontSee('Device Bravo B');
    }

    public function test_employee_report_is_scoped_to_own_company(): void
    {
        $response = $this->actingAs($this->userA)->get(route('reports.show', 'employees'));

        $response->assertOk();
        $response->assertSee('Employee Alpha A');
        $response->assertDontSee('Employee Bravo B');
    }
}
