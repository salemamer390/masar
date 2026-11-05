<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use DatabaseTransactions;

    private const OWNER_EMAIL = 'owner@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['owner.emails' => [self::OWNER_EMAIL]]);
    }

    private function superAdmin(string $email, ?int $companyId = null): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'company_id' => null], ['display_name' => 'Super Admin']);
        return User::create([
            'name' => 'Admin', 'email' => $email, 'password' => bcrypt('secret'),
            'role_id' => $role->id, 'company_id' => $companyId, 'is_active' => true,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/owner')->assertRedirect();
    }

    public function test_company_users_get_404_not_a_redirect_that_reveals_the_page(): void
    {
        $company = Company::create(['name' => 'Owner Gate Co', 'slug' => 'owner-gate-co']);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create(['name' => 'Mgr', 'email' => 'mgr@example.test', 'password' => bcrypt('secret'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);

        $this->actingAs($user)->get('/owner')->assertNotFound();
    }

    public function test_other_super_admins_get_404_even_without_a_company(): void
    {
        $this->actingAs($this->superAdmin('someone-else@example.test'))->get('/owner')->assertNotFound();
    }

    public function test_super_admin_tied_to_a_company_gets_404_even_with_the_owner_email(): void
    {
        $company = Company::create(['name' => 'Owner Bound Co', 'slug' => 'owner-bound-co']);
        $this->actingAs($this->superAdmin(self::OWNER_EMAIL, $company->id))->get('/owner')->assertNotFound();
    }

    public function test_the_owner_opens_the_dashboard_and_sees_companies_and_data_counts(): void
    {
        $company = Company::create(['name' => 'شركة المالك للاختبار', 'slug' => 'owner-visible-co']);
        SubscriptionPlan::create(['name' => 'Owner Plan', 'slug' => 'owner-plan-x1', 'employee_limit' => 50, 'hr_user_limit' => 5, 'price' => 100, 'billing_period' => 'monthly', 'is_active' => true, 'sort_order' => 1]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        User::create(['name' => 'Mgr', 'email' => 'mgr2@example.test', 'password' => bcrypt('secret'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);

        $response = $this->actingAs($this->superAdmin(self::OWNER_EMAIL))->get('/owner');

        $response->assertOk();
        $response->assertSee('لوحة تحكم المالك');
        $response->assertSee('شركة المالك للاختبار');
        $response->assertSee('بيانات الشركات');
    }

    public function test_only_the_owner_can_mutate_plans(): void
    {
        $payload = ['name' => 'Blocked Plan', 'employee_limit' => 10, 'price' => 50, 'billing_period' => 'monthly'];

        $this->actingAs($this->superAdmin('intruder@example.test'))
            ->post('/owner/plans', $payload)
            ->assertNotFound();
        $this->assertDatabaseMissing('subscription_plans', ['name' => 'Blocked Plan']);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->post('/owner/plans', $payload)
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_plans', ['name' => 'Blocked Plan']);
    }
}
