<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Permission, Role, SubscriptionPlan, User};
use App\Support\GuidedTour;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GuidedTourTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'tour-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'TourCo', 'slug' => 'tour-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
    }

    private function userWithRole(string $roleName, array $permissionNames): User
    {
        $role = Role::create(['name' => $roleName, 'display_name' => $roleName, 'company_id' => $this->company->id]);
        if ($permissionNames) {
            $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));
        }

        return User::create(['name' => 'Tour User', 'email' => $roleName.'@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
    }

    private function fullPermissionUser(): User
    {
        return $this->userWithRole('company_manager', [
            'payroll.settings', 'employees.view', 'salary.view', 'leaves.view',
            'penalties.view', 'requests.view', 'fingerprint-devices.view',
            'payroll.view', 'dashboard.view',
        ]);
    }

    public function test_first_login_user_gets_active_tour(): void
    {
        $user = $this->fullPermissionUser();
        $this->actingAs($user);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('window.masarGuidedTour', $html);
        $this->assertStringContainsString('active: true', $html);
        $this->assertStringContainsString('js/tour.js', $html);
        $this->assertStringContainsString('أهلًا بك في مسار', $html);
        $this->assertStringContainsString('جاهز للبدء', $html);
    }

    public function test_tour_stops_starting_once_seen(): void
    {
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_seen_at' => now()])->save();
        $this->actingAs($user);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('active: false', $html);
    }

    public function test_steps_follow_the_user_chosen_setup_order(): void
    {
        $keys = array_column(GuidedTour::stepsFor($this->fullPermissionUser()), 'key');

        $this->assertSame([
            'welcome',
            'settings',
            'employees',
            'salary-structures',
            'leaves',
            'penalties',
            'requests',
            'fingerprint',
            'monthly-summary',
            'payroll',
            'dashboard',
            'closing',
        ], $keys);
    }

    public function test_each_page_step_enters_its_own_page_and_targets_page_content(): void
    {
        $steps = GuidedTour::stepsFor($this->fullPermissionUser());

        $byKey = collect($steps)->keyBy('key');
        $this->assertSame(route('payroll.settings.index'), $byKey['settings']['url']);
        $this->assertSame(route('employees.index'), $byKey['employees']['url']);
        $this->assertSame(route('salaries.index'), $byKey['salary-structures']['url']);
        $this->assertSame(route('leaves.index'), $byKey['leaves']['url']);
        $this->assertSame(route('discipline.index'), $byKey['penalties']['url']);
        $this->assertSame(route('requests.index'), $byKey['requests']['url']);
        $this->assertSame(route('fingerprint.devices.index'), $byKey['fingerprint']['url']);
        $this->assertSame(route('jobs.index'), $byKey['monthly-summary']['url']);
        $this->assertSame(route('payroll.employee-salaries'), $byKey['payroll']['url']);
        $this->assertSame(route('dashboard'), $byKey['dashboard']['url']);

        // Page stops highlight real in-page content, never the sidebar.
        $this->assertSame(['.settings-cards', '.page-head'], $byKey['settings']['target']);
        $this->assertSame(['[data-bs-target="#addEmployeeModal"]', '.page-head'], $byKey['employees']['target']);
        $this->assertSame(['[data-bs-target="#salaryModal"]', '.page-head'], $byKey['salary-structures']['target']);
        $this->assertSame(['[data-bs-target="#leaveModal"]', '.page-head'], $byKey['leaves']['target']);
        $this->assertSame(['[data-bs-target="#penaltyModal"]', '.page-head'], $byKey['penalties']['target']);
        $this->assertSame(['#newRequestButton', '.page-head a[href*="create=1"]', '.page-head'], $byKey['requests']['target']);
        $this->assertSame(['[data-bs-target="#addDeviceModal"]', '.page-head'], $byKey['fingerprint']['target']);
        $this->assertSame(['.monthly-summary-table', '.page-head'], $byKey['monthly-summary']['target']);
        $this->assertSame(['#payrollViewTabs', '.employee-payroll-actions'], $byKey['payroll']['target']);
        $this->assertSame(['.reference-kpi-grid', '.dashboard-command-bar'], $byKey['dashboard']['target']);
    }

    public function test_steps_follow_user_permissions(): void
    {
        $user = $this->userWithRole('leaves_only', ['leaves.view']);
        $keys = array_column(GuidedTour::stepsFor($user), 'key');

        $this->assertSame(['welcome', 'leaves', 'closing'], $keys);
    }

    public function test_progress_saves_the_current_step_key(): void
    {
        $user = $this->fullPermissionUser();
        $this->actingAs($user);

        $this->postJson('/tour/progress', ['step' => 'leaves'])->assertNoContent();

        $this->assertSame('leaves', $user->fresh()->tour_step);
        $this->assertNull($user->fresh()->tour_seen_at);
    }

    public function test_progress_rejects_steps_outside_the_user_tour(): void
    {
        $user = $this->userWithRole('leaves_only', ['leaves.view']);
        $this->actingAs($user);

        $this->postJson('/tour/progress', ['step' => 'payroll'])->assertStatus(422);
        $this->postJson('/tour/progress', ['step' => 'made-up'])->assertStatus(422);

        $this->assertNull($user->fresh()->tour_step);
    }

    public function test_progress_requires_authentication(): void
    {
        $this->postJson('/tour/progress', ['step' => 'leaves'])->assertUnauthorized();
    }

    public function test_complete_marks_seen_and_clears_progress(): void
    {
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_step' => 'leaves'])->save();
        $this->actingAs($user);

        $this->post('/tour/complete')->assertNoContent();

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->tour_seen_at);
        $this->assertNull($fresh->tour_step);
    }

    public function test_replay_resets_state_and_redirects_back(): void
    {
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_seen_at' => now(), 'tour_step' => 'payroll'])->save();
        $this->actingAs($user);

        $this->from('/dashboard')->post('/tour/replay')->assertRedirect('/dashboard');

        $fresh = $user->fresh();
        $this->assertNull($fresh->tour_seen_at);
        $this->assertNull($fresh->tour_step);
    }

    public function test_tour_state_is_stored_per_user(): void
    {
        $a = $this->userWithRole('manager_a', ['leaves.view']);
        $b = $this->userWithRole('manager_b', ['leaves.view']);
        $this->actingAs($a);

        $this->postJson('/tour/progress', ['step' => 'leaves'])->assertNoContent();
        $this->post('/tour/complete')->assertNoContent();

        $this->assertNotNull($a->fresh()->tour_seen_at);
        $this->assertNull($b->fresh()->tour_seen_at);
        $this->assertNull($b->fresh()->tour_step);
    }

    public function test_tour_is_mandatory_with_no_skip_control(): void
    {
        $user = $this->fullPermissionUser();
        $this->actingAs($user);

        $html = $this->get('/employees')->assertOk()->getContent();

        $this->assertStringContainsString('الانتقال إلى الصفحة', $html);
        $this->assertStringContainsString('اضغط على «:page» من القائمة الجانبية', $html);
        $this->assertStringNotContainsString('تخطي الجولة', $html);
        $this->assertStringNotContainsString('متابعة الجولة', $html);
    }

    public function test_paused_tour_resumes_on_the_saved_step(): void
    {
        $user = $this->fullPermissionUser();
        $user->forceFill(['tour_step' => 'payroll'])->save();
        $this->actingAs($user);

        $html = $this->get('/employees')->assertOk()->getContent();

        $this->assertStringContainsString('current: "payroll"', $html);
    }

    public function test_changes_are_refused_until_the_tour_is_finished(): void
    {
        config(['tour.enforce' => true]);
        $user = $this->fullPermissionUser();
        $this->actingAs($user);

        $this->postJson('/notifications/read-all')->assertStatus(423);
        $this->post('/notifications/read-all')->assertRedirect();
        $this->assertNull($user->fresh()->tour_seen_at);

        // Reading pages and the tour's own endpoints stay open.
        $this->get('/employees')->assertOk();
        $this->postJson('/tour/progress', ['step' => 'leaves'])->assertNoContent();
        $this->post('/tour/complete')->assertNoContent();

        $this->postJson('/notifications/read-all')->assertSuccessful();
    }

    public function test_logout_stays_available_during_the_tour(): void
    {
        config(['tour.enforce' => true]);
        $user = $this->fullPermissionUser();
        $this->actingAs($user);

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_user_menu_has_replay_entry(): void
    {
        $user = $this->fullPermissionUser();
        $this->actingAs($user);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('إعادة الجولة التعريفية', $html);
        $this->assertStringContainsString(route('tour.replay'), $html);
    }
}
