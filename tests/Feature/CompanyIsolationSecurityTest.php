<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\PayrollRecord;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cross-tenant adversarial checks: a manager of company A must not read,
 * change or download anything owned by company B, through the UI routes a
 * real account can reach. Complements CompanyIsolationTest with account,
 * role, file and payslip paths.
 */
class CompanyIsolationSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private Company $companyA;
    private Company $companyB;
    private User $userA;
    private User $userB;
    private Role $roleA;
    private Role $roleB;
    private Employee $employeeA;
    private Employee $employeeB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );
        $this->companyA = Company::create(['name' => 'Iso A', 'slug' => 'iso-a', 'status' => 'active']);
        $this->companyB = Company::create(['name' => 'Iso B', 'slug' => 'iso-b', 'status' => 'active']);
        foreach ([$this->companyA, $this->companyB] as $company) {
            CompanySubscription::create([
                'company_id' => $company->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => today(),
            ]);
        }
        $this->roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager A', 'company_id' => $this->companyA->id]);
        $this->roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager B', 'company_id' => $this->companyB->id]);
        $this->userA = User::create(['name' => 'User A', 'email' => 'isoa@example.test', 'password' => bcrypt('secret'), 'role_id' => $this->roleA->id, 'company_id' => $this->companyA->id, 'is_active' => true]);
        $this->userB = User::create(['name' => 'User B', 'email' => 'isob@example.test', 'password' => bcrypt('secret'), 'role_id' => $this->roleB->id, 'company_id' => $this->companyB->id, 'is_active' => true]);
        $this->employeeA = Employee::create(['employee_code' => '9101', 'first_name' => 'IsoA', 'full_name' => 'Iso Employee A', 'company_id' => $this->companyA->id, 'status_id' => $status->id]);
        $this->employeeB = Employee::create(['employee_code' => '9102', 'first_name' => 'IsoB', 'full_name' => 'Iso Employee B', 'company_id' => $this->companyB->id, 'status_id' => $status->id]);
    }

    public function test_manager_cannot_update_user_of_other_company(): void
    {
        $this->actingAs($this->userA)
            ->put(route('users.update', $this->userB), [
                'name' => 'Hijacked B',
                'role_id' => $this->roleA->id,
            ])
            ->assertForbidden();

        $this->assertSame('User B', $this->userB->fresh()->name);
        $this->assertSame($this->roleB->id, $this->userB->fresh()->role_id);
    }

    public function test_manager_cannot_assign_role_of_other_company(): void
    {
        $response = $this->actingAs($this->userA)->post(route('users.store'), [
            'name' => 'Sneaky', 'email' => 'sneaky@example.test',
            'password' => 'secret1', 'role_id' => $this->roleB->id,
        ]);

        // Store resolves the role through the company-scoped query, so a
        // foreign role is invisible (404) and no account is created.
        $response->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.test']);
    }

    public function test_manager_cannot_view_employee_photo_of_other_company(): void
    {
        Storage::disk('local')->put('employees/photo-b.png', 'img-bytes');
        $this->employeeB->update(['profile_photo_path' => 'employees/photo-b.png']);

        $this->actingAs($this->userA)
            ->get(route('employees.photo.show', $this->employeeB))
            ->assertNotFound();

        $this->actingAs($this->userB)
            ->get(route('employees.photo.show', $this->employeeB))
            ->assertOk();
    }

    public function test_manager_cannot_open_document_of_other_company(): void
    {
        Storage::disk('local')->put("documents/{$this->employeeB->id}/secret.pdf", 'pdf-bytes');
        $document = Document::create([
            'employee_id' => $this->employeeB->id,
            'document_name' => 'Secret B',
            'file_name' => 'secret.pdf',
            'file_path' => "documents/{$this->employeeB->id}/secret.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 9,
        ]);

        $this->actingAs($this->userA)
            ->get(route('documents.preview', $document))
            ->assertNotFound();

        $this->actingAs($this->userB)
            ->get(route('documents.preview', $document))
            ->assertOk();
    }

    public function test_manager_cannot_open_payslip_of_other_company(): void
    {
        $record = PayrollRecord::create([
            'employee_id' => $this->employeeB->id,
            'payroll_month' => '2026-11-01',
            'basic_salary' => 5000, 'net_salary' => 5000, 'status' => 'Approved',
        ]);

        $this->actingAs($this->userA)
            ->get(route('payroll.employee-salaries.payslip-pdf', $record))
            ->assertNotFound();
    }
}
