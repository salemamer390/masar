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

    public function test_company_linked_super_admin_cannot_disable_foreign_user(): void
    {
        $adminRole = Role::create(['name'=>'super_admin','display_name'=>'Company A admin','company_id'=>$this->companyA->id]);
        $this->userA->update(['role_id'=>$adminRole->id]); $this->userA->unsetRelation('role');
        $staffRole = Role::create(['name'=>'staff','display_name'=>'Company B staff','company_id'=>$this->companyB->id]);
        $this->userB->update(['role_id'=>$staffRole->id]);
        $this->actingAs($this->userA)->patchJson('/users/'.$this->userB->id.'/status',['is_active'=>false])->assertForbidden();
        $this->assertTrue($this->userB->fresh()->is_active);
    }

    public function test_employee_bank_rejects_foreign_company_reference(): void
    {
        $bank=\App\Models\Bank::create(['name'=>'Private company B bank','company_id'=>$this->companyB->id]);
        $this->actingAs($this->userA)->postJson('/employees/'.$this->employeeA->id.'/bank-accounts',['bank_id'=>$bank->id,'effective_from'=>'2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('bank_id');
        $this->assertDatabaseMissing('employee_bank_accounts',['employee_id'=>$this->employeeA->id,'bank_id'=>$bank->id]);
    }

    public function test_company_linked_admin_cannot_edit_delete_or_create_foreign_users(): void
    {
        $adminRole = Role::create(['name'=>'super_admin','display_name'=>'Admin','company_id'=>$this->companyA->id]);
        $this->userA->update(['role_id'=>$adminRole->id]); $this->userA->unsetRelation('role');
        $staffRole = Role::create(['name'=>'staff','display_name'=>'Staff','company_id'=>$this->companyB->id]);
        $this->userB->update(['role_id'=>$staffRole->id]);
        $this->actingAs($this->userA)->putJson('/users/'.$this->userB->id, ['name'=>'Changed','email'=>'changed@example.test','role_id'=>$staffRole->id])->assertForbidden();
        $this->deleteJson('/users/'.$this->userB->id)->assertForbidden();
        $this->postJson('/users',['name'=>'Foreign','email'=>'foreign@example.test','password'=>'secret123','password_confirmation'=>'secret123','role_id'=>$staffRole->id,'company_id'=>$this->companyB->id])->assertNotFound();
        $this->assertSame('User B',$this->userB->fresh()->name);
        $this->assertDatabaseMissing('users',['email'=>'foreign@example.test']);
    }

    public function test_private_lookup_ids_are_rejected_before_cross_company_links_are_written(): void
    {
        $models = ['bank_id'=>\App\Models\Bank::class,'payment_method_id'=>\App\Models\PaymentMethod::class,'department_id'=>\App\Models\Department::class,'job_id'=>\App\Models\Job::class,'branch_id'=>\App\Models\Branch::class,'document_type_id'=>\App\Models\DocumentType::class,'penalty_type_id'=>\App\Models\PenaltyType::class,'leave_type_id'=>\App\Models\LeaveType::class,'movement_type_id'=>\App\Models\MovementType::class];
        $ids=[];
        foreach($models as $field=>$model) {
            $row = new $model; $row->forceFill(['company_id'=>$this->companyB->id, $field==='job_id'?'title':'name'=>'Private foreign lookup']); $row->save(); $ids[$field]=$row->id;
        }
        $this->actingAs($this->userA);
        $cases=[['/employees',['department_id','job_id','branch_id']],['/employees/'.$this->employeeA->id.'/bank-accounts',['bank_id','payment_method_id']],['/salaries',['payment_method_id']],['/movements',['movement_type_id']],['/discipline/penalties',['penalty_type_id']],['/leaves',['leave_type_id']],['/employees/'.$this->employeeA->id.'/documents',['document_type_id']]];
        foreach($cases as [$url,$fields]) {
            $payload=['employee_id'=>$this->employeeA->id,'full_name'=>'Safe employee','effective_from'=>'2026-11-01','movement_date'=>'2026-11-01','start_date'=>'2026-11-01','end_date'=>'2026-11-01','penalty_date'=>'2026-11-01'];
            foreach($fields as $f) $payload[$f]=$ids[$f];
            $this->postJson($url,$payload)->assertUnprocessable()->assertJsonValidationErrors($fields);
        }
        $this->assertDatabaseMissing('salaries',['employee_id'=>$this->employeeA->id,'payment_method_id'=>$ids['payment_method_id']]);
        $this->assertDatabaseMissing('movement_records',['employee_id'=>$this->employeeA->id,'movement_type_id'=>$ids['movement_type_id']]);
    }

    public function test_scoped_lookup_validation_keeps_global_and_own_records_available(): void
    {
        $global=\App\Models\Bank::create(['name'=>'Global bank']);
        $own=\App\Models\Bank::create(['name'=>'Own bank','company_id'=>$this->companyA->id]);
        $this->actingAs($this->userA);
        foreach([$global,$own] as $bank) {
            $this->postJson('/employees/'.$this->employeeA->id.'/bank-accounts',['bank_id'=>$bank->id,'effective_from'=>'2026-11-01'])->assertOk();
        }
    }

    public function test_disabled_account_loses_an_existing_web_session(): void
    {
        $this->userA->update(['is_active'=>false]);
        $this->actingAs($this->userA)->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_web_login_is_rate_limited(): void
    {
        \Illuminate\Support\Facades\RateLimiter::clear('prelaunch');
        for($i=0;$i<5;$i++) $this->post('/login',['email'=>'missing@example.test','password'=>'wrong'])->assertRedirect();
        $this->post('/login',['email'=>'missing@example.test','password'=>'wrong'])->assertStatus(429);
    }

    public function test_company_manager_cannot_modify_a_globally_shared_lookup(): void
    {
        $global=\App\Models\Department::create(['name'=>'Shared department','company_id'=>null]);
        $this->actingAs($this->userA)->putJson('/master/departments/'.$global->id,['name'=>'Changed by A','code'=>''])->assertForbidden();
        $this->assertSame('Shared department',$global->fresh()->name);
        $this->deleteJson('/master/departments/'.$global->id)->assertForbidden();
    }
    public function test_employee_documents_are_stored_privately_and_preview_is_company_scoped(): void
    {
        Storage::fake('public');Storage::fake('local');
        $file=UploadedFile::fake()->createWithContent('private.pdf','%PDF-1.4 local test');
        $this->actingAs($this->userA)->postJson('/employees/'.$this->employeeA->id.'/documents',['document_name'=>'Private document','document_file'=>$file])->assertOk();
        $doc=\App\Models\Document::where('employee_id',$this->employeeA->id)->latest('id')->firstOrFail();
        Storage::disk('public')->assertMissing($doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->get('/documents/'.$doc->id.'/preview')->assertOk();
        $this->actingAs($this->userB)->get('/documents/'.$doc->id.'/preview')->assertNotFound();
    }

    public function test_platform_management_requires_separate_owner_account(): void
    {
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'company_id' => null], ['display_name' => 'Super Admin']);
        $companyLinkedAdmin = User::create([
            'name' => 'Company Admin', 'email' => 'admin-a@example.test', 'password' => bcrypt('secret'),
            'role_id' => $superAdminRole->id, 'company_id' => $this->companyA->id, 'is_active' => true,
        ]);

        $this->actingAs($companyLinkedAdmin)
            ->get(route('subscriptions.index'))
            ->assertRedirect(route('dashboard'));

        config(['owner.emails' => ['owner@example.test']]);
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => bcrypt('secret'),
            'role_id' => $superAdminRole->id, 'company_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($owner)->get(route('subscriptions.index'))->assertOk();
    }

    public function test_employee_photo_upload_is_private_and_company_scoped(): void
    {
        Storage::fake('public');

        $this->actingAs($this->userA)
            ->patch(route('employees.photo', $this->employeeA), ['profile_photo' => UploadedFile::fake()->image('avatar.jpg')])
            ->assertRedirect();

        $path = $this->employeeA->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $this->actingAs($this->userA)->get(route('employees.photo.show', $this->employeeA))->assertOk();
        $this->actingAs($this->userB)->get(route('employees.photo.show', $this->employeeA))->assertNotFound();
    }

    public function test_legacy_public_photo_is_served_only_through_guarded_route(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('employees/1/profile/legacy.jpg', 'old-bytes');
        $this->employeeA->update(['profile_photo_path' => 'employees/1/profile/legacy.jpg']);

        $this->actingAs($this->userA)->get(route('employees.photo.show', $this->employeeA))->assertOk();
        $this->actingAs($this->userB)->get(route('employees.photo.show', $this->employeeA))->assertNotFound();
    }
}
