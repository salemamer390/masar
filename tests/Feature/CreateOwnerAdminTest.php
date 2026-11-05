<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class CreateOwnerAdminTest extends TestCase
{
    use DatabaseTransactions;

    private function runCommand(array $inputs): CommandTester
    {
        $command = $this->app->make(Kernel::class)->all()['masar:create-owner'];
        $tester = new CommandTester($command);
        $tester->setInputs($inputs);
        $tester->execute([]);
        return $tester;
    }

    public function test_it_creates_a_global_super_admin_that_passes_the_owner_gate(): void
    {
        config(['owner.emails' => ['new-owner@example.test']]);

        $tester = $this->runCommand(['Platform Owner', 'new-owner@example.test', 'strong-pass-123', 'strong-pass-123']);

        $this->assertSame(0, $tester->getStatusCode());
        $user = User::where('email', 'new-owner@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isSuperAdmin());
        $this->assertNull($user->company_id);
        $this->assertTrue((bool) $user->is_active);
        $this->assertStringNotContainsString('strong-pass-123', $tester->getDisplay());

        $this->actingAs($user, 'owner')->get('/owner')->assertOk();
    }

    public function test_a_taken_email_aborts_without_touching_the_existing_user(): void
    {
        $company = Company::create(['name' => 'أمر موجود', 'slug' => 'existing-owner-co']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'company_id' => null], ['display_name' => 'Super Admin']);
        $existing = User::create([
            'name' => 'Existing Owner', 'email' => 'taken-owner@example.test',
            'password' => bcrypt('original-pass'), 'role_id' => $role->id,
            'company_id' => $company->id, 'is_active' => true,
        ]);
        $originalHash = $existing->password;

        $tester = $this->runCommand(['Someone Else', 'taken-owner@example.test']);

        $this->assertSame(1, $tester->getStatusCode());
        $existing->refresh();
        $this->assertSame('Existing Owner', $existing->name);
        $this->assertSame($originalHash, $existing->password);
        $this->assertSame($company->id, $existing->company_id);
    }

    public function test_a_short_password_is_rejected_and_creates_nothing(): void
    {
        $tester = $this->runCommand(['Owner', 'short-pass@example.test', 'short']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertDatabaseMissing('users', ['email' => 'short-pass@example.test']);
    }

    public function test_mismatched_password_confirmation_is_rejected_and_creates_nothing(): void
    {
        $tester = $this->runCommand(['Owner', 'mismatch@example.test', 'strong-pass-123', 'different-pass-456']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.test']);
    }

    public function test_an_invalid_email_is_rejected_and_creates_nothing(): void
    {
        $tester = $this->runCommand(['Owner', 'not-an-email']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }
}
