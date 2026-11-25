<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\ImportFileVault;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportFileVaultTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        File::deleteDirectory(ImportFileVault::directory());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(ImportFileVault::directory());
        parent::tearDown();
    }

    private function companyUser(): User
    {
        $company = Company::create(['name' => 'VaultCo', 'slug' => 'vault-co-' . uniqid(), 'status' => 'active']);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $role->permissions()->sync(\App\Models\Permission::pluck('id'));
        $plan = \App\Models\SubscriptionPlan::firstOrCreate(['slug' => 'vault-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        \App\Models\CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);

        return User::create(['name' => 'Mgr', 'email' => 'm' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
    }

    private function files(): array
    {
        return array_map('basename', glob(ImportFileVault::directory() . '/20*') ?: []);
    }

    public function test_capture_keeps_a_readable_named_plain_copy_once_per_company_and_content(): void
    {
        $user = $this->companyUser();
        $vault = app(ImportFileVault::class);
        $make = fn (string $content) => UploadedFile::fake()->createWithContent('كشف الحضور ../نوفمبر.xlsx', $content);

        $vault->capture($make('workbook-bytes-1'), $user);
        $vault->capture($make('workbook-bytes-1'), $user); // wizard re-upload at the next step
        $vault->capture($make('another'), $user);

        $files = $this->files();
        $this->assertCount(2, $files);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}_\d{4}_VaultCo_[a-f0-9]{10}_.*xlsx$/u', $files[0]);
        $this->assertStringNotContainsString('/', $files[0]);
        $this->assertStringNotContainsString('..', $files[0]);
        $copy = collect(glob(ImportFileVault::directory() . '/20*'))->first(fn ($f) => filesize($f) === strlen('workbook-bytes-1'));
        $this->assertSame('workbook-bytes-1', file_get_contents($copy));
        $this->assertStringContainsString('Deny from all', file_get_contents(ImportFileVault::directory() . '/.htaccess'));
    }

    public function test_the_wizard_keeps_a_copy_even_if_the_import_is_never_confirmed(): void
    {
        $user = $this->companyUser();
        $this->actingAs($user)->postJson('/employees/import/preview', ['file' => UploadedFile::fake()->createWithContent('w.xlsx', 'wizard-upload')]);

        $this->assertCount(1, $this->files());
    }

    public function test_there_is_no_http_route_to_the_vault(): void
    {
        $user = $this->companyUser();
        app(ImportFileVault::class)->capture(UploadedFile::fake()->createWithContent('w.xlsx', 'x'), $user);
        $name = $this->files()[0];

        foreach (['/storage/app/private/import-vault/' . $name, '/import-vault/' . $name, '/owner/import-files'] as $url) {
            $this->assertContains($this->actingAs($user)->get($url)->getStatusCode(), [403, 404], $url);
        }
    }
}
