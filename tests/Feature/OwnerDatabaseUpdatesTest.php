<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\OwnerDatabaseUpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerDatabaseUpdatesTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Schema::hasTable('users')) {
            Artisan::call('migrate');
        }
        config()->set('owner.emails', ['owner@example.com']);
        if (\Schema::hasTable('activity_logs')) {
            ActivityLog::where('module', 'database')->delete();
        }
    }

    private function createRole(): Role
    {
        return Role::create(['name' => 'super_admin', 'display_name' => 'Super Admin', 'company_id' => null]);
    }

    private function createCompanyUser(string $email = 'company@example.com'): User
    {
        $company = Company::create(['name' => 'شركة الاختبار', 'slug' => 'test-company', 'status' => 'active']);

        return User::create([
            'company_id' => $company->id,
            'role_id' => Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id])->id,
            'name' => 'مستخدم شركة',
            'email' => $email,
            'phone' => '01000000000',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function createOwner(): User
    {
        return User::create([
            'company_id' => null,
            'role_id' => $this->createRole()->id,
            'name' => 'مالك النظام',
            'email' => 'owner@example.com',
            'phone' => '01000000001',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function fakeService(?array $status = null, ?array $runResult = null): FakeOwnerDatabaseUpdateService
    {
        $service = new FakeOwnerDatabaseUpdateService;
        $service->statusResult = $status ?? [
            'available' => true,
            'error' => null,
            'ran_count' => 20,
            'last_batch' => 4,
            'pending' => [
                ['name' => '2026_11_17_000001_first_pending_update', 'path' => 'database/migrations/2026_11_17_000001_first_pending_update.php'],
                ['name' => '2026_11_17_000002_second_pending_update', 'path' => 'database/migrations/2026_11_17_000002_second_pending_update.php'],
            ],
            'next' => ['name' => '2026_11_17_000001_first_pending_update', 'path' => 'database/migrations/2026_11_17_000001_first_pending_update.php'],
        ];
        $service->runResult = $runResult ?? [
            'ok' => true,
            'code' => 'completed',
            'message' => 'تم تنفيذ التحديث 2026_11_17_000001_first_pending_update بنجاح.',
            'migration' => '2026_11_17_000001_first_pending_update',
            'pending_before' => 2,
            'pending_after' => 1,
            'exit_code' => 0,
            'output' => 'DONE',
        ];
        $this->app->instance(OwnerDatabaseUpdateService::class, $service);

        return $service;
    }

    private function validConfirmation(array $overrides = []): array
    {
        return array_merge([
            'password' => 'password',
            'confirm_backup' => '1',
            'confirm_partial_risk' => '1',
        ], $overrides);
    }

    public function test_owner_dashboard_shows_pending_database_updates(): void
    {
        $owner = $this->createOwner();
        $this->fakeService();

        $response = $this->actingAs($owner, 'owner')->get(route('owner.index'));

        $response->assertOk();
        $response->assertSee('تحديثات قاعدة البيانات');
        $response->assertSee('2026_11_17_000001_first_pending_update');
        $response->assertSee('2026_11_17_000002_second_pending_update');
        $response->assertSee('كلمة مرور حساب المالك الحالية');
        $response->assertSee('تنفيذ التحديث التالي فقط');
    }

    public function test_guest_and_company_user_cannot_run_database_update(): void
    {
        $this->fakeService();

        $this->post(route('owner.database-updates.run'), $this->validConfirmation())
            ->assertRedirect(route('owner.login'));

        $companyUser = $this->createCompanyUser();
        $this->actingAs($companyUser)
            ->post(route('owner.database-updates.run'), $this->validConfirmation())
            ->assertNotFound();
    }

    public function test_password_backup_and_partial_risk_confirmations_are_required(): void
    {
        $owner = $this->createOwner();
        $service = $this->fakeService();

        $response = $this->actingAs($owner, 'owner')->post(
            route('owner.database-updates.run'),
            ['password' => 'wrong-password']
        );

        $response->assertSessionHasErrors(['password', 'confirm_backup', 'confirm_partial_risk']);
        $this->assertSame(0, $service->runCalls);
        $this->assertSame(0, ActivityLog::where('module', 'database')->count());
    }

    public function test_owner_password_is_checked_before_running(): void
    {
        $owner = $this->createOwner();
        $service = $this->fakeService();

        $response = $this->actingAs($owner, 'owner')->post(
            route('owner.database-updates.run'),
            $this->validConfirmation(['password' => 'wrong-password'])
        );

        $response->assertSessionHasErrors('password');
        $this->assertSame(0, $service->runCalls);
        $this->assertSame(0, ActivityLog::where('module', 'database')->count());
    }

    public function test_owner_can_run_next_update_and_activity_is_attributed_to_owner(): void
    {
        $owner = $this->createOwner();
        $service = $this->fakeService();

        $response = $this->actingAs($owner, 'owner')->post(
            route('owner.database-updates.run'),
            $this->validConfirmation()
        );

        $response->assertRedirect(route('owner.index'));
        $response->assertSessionHas('open_owner_tab', 'database-updates');
        $response->assertSessionHas('toast');
        $this->assertSame(1, $service->runCalls);
        $this->assertDatabaseHas('activity_logs', [
            'company_id' => null,
            'user_id' => $owner->id,
            'action' => 'database_update_completed',
            'module' => 'database',
        ]);
    }

    public function test_failed_or_locked_run_is_reported_and_logged(): void
    {
        $owner = $this->createOwner();
        $service = $this->fakeService(null, [
            'ok' => false,
            'code' => 'locked',
            'message' => 'هناك تحديث قاعدة بيانات قيد التنفيذ بالفعل.',
            'migration' => null,
            'pending_before' => null,
            'pending_after' => null,
            'exit_code' => null,
            'output' => null,
        ]);

        $response = $this->actingAs($owner, 'owner')->post(
            route('owner.database-updates.run'),
            $this->validConfirmation()
        );

        $response->assertSessionHasErrors('database_update');
        $this->assertSame(1, $service->runCalls);
        $this->assertDatabaseHas('activity_logs', [
            'company_id' => null,
            'user_id' => $owner->id,
            'action' => 'database_update_failed',
            'module' => 'database',
        ]);
    }
}

class FakeOwnerDatabaseUpdateService extends OwnerDatabaseUpdateService
{
    public array $statusResult;

    public array $runResult;

    public int $runCalls = 0;

    public function status(): array
    {
        return $this->statusResult;
    }

    public function runNext(): array
    {
        $this->runCalls++;

        return $this->runResult;
    }
}
