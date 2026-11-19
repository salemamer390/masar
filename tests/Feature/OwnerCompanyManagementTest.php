<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OwnerCompanyManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const OWNER = 'owner-mgmt@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['owner.emails' => [self::OWNER], 'session.driver' => 'database']);
    }

    private function owner(): self
    {
        $role = Role::firstOrCreate(['name' => 'super_admin', 'company_id' => null], ['display_name' => 'Super Admin']);
        $u = User::create(['name' => 'Owner', 'email' => self::OWNER, 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => null, 'is_active' => true]);
        return $this->actingAs($u, 'owner');
    }

    private function company(string $tag, int $managers = 1): array
    {
        $c = Company::create(['name' => "Mgmt Co $tag", 'slug' => "mgmt-$tag-" . uniqid(), 'email' => "full.$tag@company-details.test", 'phone' => '0123']);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'M', 'company_id' => $c->id]);
        $staff = Role::create(['name' => 'hr_staff', 'display_name' => 'S', 'company_id' => $c->id]);
        $users = [];
        for ($i = 1; $i <= $managers; $i++) {
            $users[] = User::create(['name' => "Mgr $tag $i", 'email' => "mgr$i.$tag@company-details.test", 'password' => bcrypt('old-password'), 'role_id' => $role->id, 'company_id' => $c->id, 'is_active' => true, 'remember_token' => 'old-remember']);
        }
        $other = User::create(['name' => "Staff $tag", 'email' => "staff.$tag@company-details.test", 'password' => bcrypt('old-password'), 'role_id' => $staff->id, 'company_id' => $c->id, 'is_active' => true]);
        return [$c, $users, $other];
    }

    public function test_dashboard_shows_full_company_details_without_secrets(): void
    {
        [$c, $users] = $this->company('d');
        $html = $this->owner()->get(route('owner.index'))->assertOk()->getContent();
        $this->assertStringContainsString('full.d@company-details.test', $html);
        $this->assertStringContainsString('mgr1.d@company-details.test', $html);
        $this->assertStringContainsString('companyDetails' . $c->id, $html);
        $this->assertStringContainsString('companyPassword' . $c->id, $html);
        $this->assertStringContainsString('companyDelete' . $c->id, $html);
        $this->assertStringNotContainsString($users[0]->getRawOriginal('password'), $html);
        $this->assertStringNotContainsString('old-remember', $html);
    }

    public function test_reset_manager_password_applies_immediately_and_ends_sessions(): void
    {
        [$c, $users, $staff] = $this->company('r');
        [$c2, $users2] = $this->company('r2');
        DB::table('sessions')->insert(['id' => 'sess-r-1', 'user_id' => $users[0]->id, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'sess-r-2', 'user_id' => $users[0]->id, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'sess-other', 'user_id' => $users2[0]->id, 'payload' => 'x', 'last_activity' => time()]);
        $otherHash = $users2[0]->fresh()->password;

        $response = $this->owner()->put(route('owner.companies.manager-password', $c), ['user_id' => $users[0]->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1']);
        $response->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Brand-New-Pass-1', json_encode(session()->all()));
        $fresh = $users[0]->fresh();
        $this->assertTrue(Hash::check('Brand-New-Pass-1', $fresh->password));
        $this->assertNotSame('old-remember', $fresh->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $users[0]->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-other')->count());
        $this->assertSame($otherHash, $users2[0]->fresh()->password);
        $this->assertTrue(Hash::check('old-password', $staff->fresh()->password));
        $this->assertSame(0, DB::table('activity_logs')->where('description', 'like', '%Brand-New-Pass-1%')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('activity_logs')->where('action', 'subscription_company_password_reset')->count());
    }

    public function test_reset_rejects_non_manager_other_company_and_weak_password(): void
    {
        [$c, $users, $staff] = $this->company('s');
        [$c2, $users2] = $this->company('s2');
        $o = $this->owner();
        $o->put(route('owner.companies.manager-password', $c), ['user_id' => $staff->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertStatus(422);
        $o->put(route('owner.companies.manager-password', $c), ['user_id' => $users2[0]->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertStatus(422);
        $o->put(route('owner.companies.manager-password', $c), ['user_id' => $users[0]->id, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $o->put(route('owner.companies.manager-password', $c), ['user_id' => $users[0]->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        foreach ([$users[0], $users2[0], $staff] as $u) $this->assertTrue(Hash::check('old-password', $u->fresh()->password));
    }

    public function test_reset_is_not_available_to_company_users_or_guests(): void
    {
        [$c, $users] = $this->company('g');
        $this->put(route('owner.companies.manager-password', $c), ['password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertRedirect();
        $this->actingAs($users[0])->put(route('owner.companies.manager-password', $c), ['password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertStatus(404);
        $this->actingAs($users[0])->delete(route('owner.companies.destroy', $c), ['confirm_name' => $c->name])->assertStatus(404);
        $this->assertTrue(Company::whereKey($c->id)->exists());
        $this->assertTrue(Hash::check('old-password', $users[0]->fresh()->password));
    }

    public function test_delete_requires_exact_name_then_erases_only_that_company(): void
    {
        Storage::fake('local'); Storage::fake('public');
        [$a, $ua] = $this->company('x');
        [$b, $ub] = $this->company('y');
        Storage::disk('local')->put("employees/9999/profile/p.jpg", 'keep');
        $o = $this->owner();
        $o->delete(route('owner.companies.destroy', $a), ['confirm_name' => 'Mgmt Co X'])->assertSessionHasErrors('confirm_name');
        $o->delete(route('owner.companies.destroy', $a), [])->assertSessionHasErrors('confirm_name');
        $this->assertTrue(Company::whereKey($a->id)->exists());
        $o->get(route('owner.companies.erasure.preview', $a))->assertOk()->assertJsonPath('counts.users', 2);
        $this->assertTrue(Company::whereKey($a->id)->exists());

        $o->delete(route('owner.companies.destroy', $a), ['confirm_name' => $a->name])->assertSessionHas('toast');
        $this->assertFalse(Company::whereKey($a->id)->exists());
        $this->assertSame(0, User::where('company_id', $a->id)->count());
        $this->assertTrue(Company::whereKey($b->id)->exists());
        $this->assertSame(2, User::where('company_id', $b->id)->count());
        Storage::disk('local')->assertExists('employees/9999/profile/p.jpg');
        $this->assertSame(0, DB::table('activity_logs')->where('description', 'like', '%Mgmt Co x%')->count());
    }

    public function test_open_manager_session_is_ended_after_password_change_on_any_session_driver(): void
    {
        [$c, $users] = $this->company('z');
        $manager = $users[0];
        $plan = \App\Models\SubscriptionPlan::first() ?? \App\Models\SubscriptionPlan::create(['name' => 'T', 'slug' => 't-' . uniqid(), 'price' => 0, 'is_active' => true]);
        \App\Models\CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $this->post('/login', ['email' => $manager->email, 'password' => 'old-password'])->assertRedirect();
        $this->assertAuthenticatedAs($manager);
        $this->get('/dashboard')->assertSuccessful();

        // Same write the Owner reset performs on the manager row.
        $manager->forceFill(['password' => 'Brand-New-Pass-1', 'remember_token' => 'rotated'])->save();

        $this->app['auth']->forgetGuards(); // a real next request starts with a fresh guard

        // The open session is rejected on its next request, whatever the session driver.
        $this->get('/dashboard')->assertRedirect();
        $this->assertGuest();
    }
}
