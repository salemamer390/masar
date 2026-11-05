<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionRenewalRequest;
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

    private function companyWithManager(string $slug): array
    {
        $company = Company::create(['name' => 'شركة اختبار '.$slug, 'slug' => $slug]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $user = User::create([
            'name' => 'Mgr', 'email' => 'mgr-'.$slug.'@example.test', 'password' => bcrypt('secret'),
            'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true,
        ]);
        return [$company, $user];
    }

    private function plan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'name' => 'Plan '.uniqid(), 'slug' => 'plan-'.uniqid(),
            'employee_limit' => 50, 'hr_user_limit' => 5, 'price' => 100,
            'billing_period' => 'monthly', 'is_active' => true, 'sort_order' => 1,
        ], $overrides));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/owner')->assertRedirect();
    }

    public function test_dashboard_fails_closed_when_no_owner_emails_are_configured(): void
    {
        config(['owner.emails' => []]);

        $this->actingAs($this->superAdmin('anybody@example.test'))->get('/owner')->assertNotFound();
        $this->actingAs($this->superAdmin('anybody2@example.test'))
            ->post('/owner/plans', ['name' => 'Nope', 'employee_limit' => 1, 'billing_period' => 'monthly'])
            ->assertNotFound();
        $this->assertDatabaseMissing('subscription_plans', ['name' => 'Nope']);
    }

    public function test_company_users_get_404_not_a_redirect_that_reveals_the_page(): void
    {
        [, $user] = $this->companyWithManager('owner-gate-co');

        $this->actingAs($user)->get('/owner')->assertNotFound();
    }

    public function test_other_super_admins_get_404_even_without_a_company(): void
    {
        $this->actingAs($this->superAdmin('someone-else@example.test'))->get('/owner')->assertNotFound();
    }

    public function test_super_admin_tied_to_a_company_gets_404_even_with_the_owner_email(): void
    {
        [$company] = $this->companyWithManager('owner-bound-co');
        $this->actingAs($this->superAdmin(self::OWNER_EMAIL, $company->id))->get('/owner')->assertNotFound();
    }

    public function test_the_owner_opens_the_dashboard_and_sees_companies_and_data_counts(): void
    {
        [$company] = $this->companyWithManager('owner-visible-co');
        $this->plan(['name' => 'Owner Plan']);

        $response = $this->actingAs($this->superAdmin(self::OWNER_EMAIL))->get('/owner');

        $response->assertOk();
        $response->assertSee('لوحة تحكم المالك');
        $response->assertSee($company->name);
        $response->assertSee('بيانات الشركات');
    }

    public function test_only_the_owner_can_create_plans(): void
    {
        $payload = ['name' => 'Blocked Plan', 'employee_limit' => 10, 'price' => 50, 'billing_period' => 'monthly'];

        $this->actingAs($this->superAdmin('intruder@example.test'))
            ->post('/owner/plans', $payload)->assertNotFound();
        [, $tenant] = $this->companyWithManager('plan-deny-co');
        $this->actingAs($tenant)->post('/owner/plans', $payload)->assertNotFound();
        $this->assertDatabaseMissing('subscription_plans', ['name' => 'Blocked Plan']);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->post('/owner/plans', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_plans', ['name' => 'Blocked Plan']);
    }

    public function test_only_the_owner_can_update_plans(): void
    {
        $plan = $this->plan(['name' => 'Updatable Plan']);
        $payload = ['name' => 'Updated Plan', 'employee_limit' => 20, 'price' => 75, 'billing_period' => 'yearly', 'is_active' => 1];

        $this->actingAs($this->superAdmin('intruder2@example.test'))
            ->put("/owner/plans/{$plan->id}", $payload)->assertNotFound();
        [, $tenant] = $this->companyWithManager('plan-upd-deny-co');
        $this->actingAs($tenant)->put("/owner/plans/{$plan->id}", $payload)->assertNotFound();
        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'name' => 'Updatable Plan']);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->put("/owner/plans/{$plan->id}", $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'name' => 'Updated Plan', 'billing_period' => 'yearly']);
    }

    public function test_only_the_owner_can_create_companies(): void
    {
        $plan = $this->plan();
        $adminEmail = 'admin-'.uniqid().'@example.test';
        $payload = [
            'name' => 'شركة المالك الجديدة', 'plan_id' => $plan->id, 'trial_days' => 14,
            'admin_name' => 'Co Admin', 'admin_email' => $adminEmail, 'admin_password' => 'secret123',
        ];

        $this->actingAs($this->superAdmin('intruder3@example.test'))
            ->post('/owner/companies', $payload)->assertNotFound();
        [, $tenant] = $this->companyWithManager('company-deny-co');
        $this->actingAs($tenant)->post('/owner/companies', $payload)->assertNotFound();
        $this->assertDatabaseMissing('companies', ['name' => 'شركة المالك الجديدة']);
        $this->assertDatabaseMissing('users', ['email' => $adminEmail]);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->post('/owner/companies', $payload)->assertSessionHasNoErrors();
        $companyId = Company::where('name', 'شركة المالك الجديدة')->value('id');
        $this->assertNotNull($companyId);
        $this->assertDatabaseHas('users', ['email' => $adminEmail, 'company_id' => $companyId]);
        $this->assertDatabaseHas('company_subscriptions', ['company_id' => $companyId, 'status' => 'trial']);
    }

    public function test_only_the_owner_can_update_company_subscriptions(): void
    {
        [$company] = $this->companyWithManager('sub-edit-co');
        $plan = $this->plan();
        $payload = ['plan_id' => $plan->id, 'status' => 'active', 'trial_days' => 0];

        $this->actingAs($this->superAdmin('intruder4@example.test'))
            ->put("/owner/companies/{$company->id}/subscription", $payload)->assertNotFound();
        [, $tenant] = $this->companyWithManager('sub-deny-co');
        $this->actingAs($tenant)->put("/owner/companies/{$company->id}/subscription", $payload)->assertNotFound();
        $this->assertDatabaseMissing('company_subscriptions', ['company_id' => $company->id]);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->put("/owner/companies/{$company->id}/subscription", $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('company_subscriptions', [
            'company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active',
        ]);
    }

    public function test_only_the_owner_can_change_company_status(): void
    {
        [$company] = $this->companyWithManager('status-co');

        $this->actingAs($this->superAdmin('intruder5@example.test'))
            ->put("/owner/companies/{$company->id}/status", ['status' => 'suspended'])->assertNotFound();
        [, $tenant] = $this->companyWithManager('status-deny-co');
        $this->actingAs($tenant)->put("/owner/companies/{$company->id}/status", ['status' => 'suspended'])->assertNotFound();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'active']);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->put("/owner/companies/{$company->id}/status", ['status' => 'suspended'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'status' => 'suspended']);
    }

    public function test_only_the_owner_can_process_renewal_requests(): void
    {
        [$company, $requester] = $this->companyWithManager('renewal-co');
        $plan = $this->plan();
        $renewal = SubscriptionRenewalRequest::create([
            'company_id' => $company->id, 'subscription_plan_id' => $plan->id,
            'requested_by' => $requester->id, 'request_type' => 'renewal',
            'status' => 'pending', 'amount' => 100, 'billing_period' => 'monthly',
        ]);
        $payload = ['status' => 'approved'];

        $this->actingAs($this->superAdmin('intruder6@example.test'))
            ->put("/owner/renewals/{$renewal->id}", $payload)->assertNotFound();
        $this->actingAs($requester)->put("/owner/renewals/{$renewal->id}", $payload)->assertNotFound();
        $this->assertDatabaseHas('subscription_renewal_requests', ['id' => $renewal->id, 'status' => 'pending']);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->put("/owner/renewals/{$renewal->id}", $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subscription_renewal_requests', ['id' => $renewal->id, 'status' => 'approved']);
        $this->assertDatabaseHas('company_subscriptions', ['company_id' => $company->id, 'status' => 'active']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $requester->id,
        ]);
    }

    public function test_only_the_owner_can_send_announcements(): void
    {
        [$company, $recipient] = $this->companyWithManager('announce-co');
        $payload = ['message' => 'إشعار تجريبي من المالك', 'audience' => 'company', 'company_id' => $company->id];

        $this->actingAs($this->superAdmin('intruder7@example.test'))
            ->post('/owner/announcements', $payload)->assertNotFound();
        [, $tenant] = $this->companyWithManager('announce-deny-co');
        $this->actingAs($tenant)->post('/owner/announcements', $payload)->assertNotFound();
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $recipient->id]);

        $this->actingAs($this->superAdmin(self::OWNER_EMAIL))
            ->post('/owner/announcements', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class, 'notifiable_id' => $recipient->id,
        ]);
    }
}
