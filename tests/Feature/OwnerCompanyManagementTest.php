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

    public function test_session_without_fingerprint_from_before_deploy_is_ended_by_owner_reset(): void
    {
        [$c, $users] = $this->company('legacy');
        $manager = $users[0];
        $plan = \App\Models\SubscriptionPlan::first() ?? \App\Models\SubscriptionPlan::create(['name' => 'T', 'slug' => 't-' . uniqid(), 'price' => 0, 'is_active' => true]);
        \App\Models\CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);

        // A session opened before deploy: authenticated, no password fingerprint, and no request since.
        $this->actingAs($manager, 'web');
        $this->assertNull(session('password_hash_web_' . $manager->id));

        // Real Owner reset through the real route, as a separate request.
        $this->owner()->put(route('owner.companies.manager-password', $c), ['password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // The old session (still no fingerprint) makes its first request after the reset.
        $fresh = User::find($manager->id);
        $this->actingAs($fresh, 'web')->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_new_login_is_fingerprinted_immediately(): void
    {
        [$c, $users] = $this->company('fp');
        $plan = \App\Models\SubscriptionPlan::first() ?? \App\Models\SubscriptionPlan::create(['name' => 'T', 'slug' => 't-' . uniqid(), 'price' => 0, 'is_active' => true]);
        \App\Models\CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $this->post('/login', ['email' => $users[0]->email, 'password' => 'old-password'])->assertRedirect();
        $this->assertNotNull(session('password_hash_web_' . $users[0]->id));
    }

    /**
     * Real browsers keep `web` as the default guard. The owner can be signed in
     * as a company user (web) and as the owner (owner guard) at the same time;
     * tests that use actingAs(..., 'owner') silently switch the default guard
     * and hide that case, so it is rebuilt here explicitly.
     */
    private function ownerAlongsideCompanyUser(User $companyUser): void
    {
        $this->owner();
        $this->actingAs($companyUser, 'web');
        \Illuminate\Support\Facades\Auth::shouldUse('web');
    }

    public function test_owner_reset_works_while_also_signed_in_to_a_different_company(): void
    {
        [$mine, $mineUsers] = $this->company('mine');
        [$target, $targetUsers, $targetStaff] = $this->company('target');
        [$third, $thirdUsers] = $this->company('third');
        $this->ownerAlongsideCompanyUser($mineUsers[0]);

        $this->put(route('owner.companies.manager-password', $target), ['user_id' => $targetUsers[0]->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])
            ->assertRedirect()->assertSessionHas('toast');

        $this->assertTrue(Hash::check('Brand-New-Pass-1', $targetUsers[0]->fresh()->password));
        // Nothing else changed: the signed-in company, another company, and staff.
        $this->assertTrue(Hash::check('old-password', $mineUsers[0]->fresh()->password));
        $this->assertTrue(Hash::check('old-password', $thirdUsers[0]->fresh()->password));
        $this->assertTrue(Hash::check('old-password', $targetStaff->fresh()->password));
        // The audit row belongs to the target company and is attributed to the owner account, not the company user.
        $log = DB::table('activity_logs')->where('action', 'subscription_company_password_reset')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame((int) $target->id, (int) $log->company_id);
        $this->assertSame(self::OWNER, User::withoutGlobalScopes()->whereKey($log->user_id)->value('email'));
    }

    public function test_company_isolation_still_holds_for_company_users_after_the_owner_fix(): void
    {
        [$mine, $mineUsers] = $this->company('m2');
        [$target, $targetUsers] = $this->company('t2');
        // A company manager can never reach owner actions, even with an owner session open alongside.
        $this->ownerAlongsideCompanyUser($mineUsers[0]);
        \Illuminate\Support\Facades\Auth::guard('owner')->logout();
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->put(route('owner.companies.manager-password', $target), ['user_id' => $targetUsers[0]->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1'])->assertStatus(404);
        $this->assertTrue(Hash::check('old-password', $targetUsers[0]->fresh()->password));
        // A company-scoped write for another company is still refused by the tenant guard.
        $this->actingAs($mineUsers[0], 'web');
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        \App\Models\ActivityLog::create(['company_id' => $target->id, 'action' => 'x', 'description' => 'cross-company write must fail']);
    }

    public function test_owner_delete_works_while_also_signed_in_to_a_different_company(): void
    {
        Storage::fake('local'); Storage::fake('public');
        [$mine, $mineUsers] = $this->company('mine3');
        [$target] = $this->company('target3');
        $this->ownerAlongsideCompanyUser($mineUsers[0]);
        $this->delete(route('owner.companies.destroy', $target), ['confirm_name' => $target->name])->assertSessionHas('toast');
        $this->assertFalse(Company::whereKey($target->id)->exists());
        $this->assertSame(2, User::withoutGlobalScopes()->where('company_id', $mine->id)->count());
    }

    public function test_reset_is_atomic_when_the_audit_event_cannot_be_written(): void
    {
        [$c, $users] = $this->company('atomic');
        DB::table('sessions')->insert(['id' => 'sess-atomic-1', 'user_id' => $users[0]->id, 'payload' => 'x', 'last_activity' => time()]);
        $before = $users[0]->fresh()->password;
        \App\Models\ActivityLog::creating(function () { throw new \RuntimeException('audit write failed'); });
        $this->owner();
        $failed = false;
        try {
            $this->withoutExceptionHandling()->put(route('owner.companies.manager-password', $c), ['user_id' => $users[0]->id, 'password' => 'Brand-New-Pass-1', 'password_confirmation' => 'Brand-New-Pass-1']);
        } catch (\RuntimeException $e) {
            $failed = true;
        }
        $this->assertTrue($failed);
        // Nothing half-applied: old password stays, session stays, no reset event.
        $this->assertSame($before, $users[0]->fresh()->password);
        $this->assertTrue(Hash::check('old-password', $users[0]->fresh()->password));
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-atomic-1')->count());
        $this->assertSame(0, DB::table('activity_logs')->where('action', 'subscription_company_password_reset')->count());
    }
}
