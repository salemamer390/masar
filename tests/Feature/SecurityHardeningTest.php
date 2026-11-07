<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Guards the defensive hardening shipped for the hosting launch:
 * security headers, public-form throttling, CSV formula escaping and the
 * removal of web-accessible legacy upload files.
 */
class SecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );
        $company = Company::create(['name' => 'Sec Co', 'slug' => 'sec-co', 'status' => 'active']);
        CompanySubscription::create([
            'company_id' => $company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => today(),
        ]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $this->manager = User::create([
            'name' => 'Sec Manager', 'email' => 'sec@example.test', 'password' => bcrypt('secret'),
            'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true,
        ]);
        $this->company = $company;
    }

    public function test_security_headers_present_on_guest_page(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_security_headers_present_on_authed_page(): void
    {
        $this->actingAs($this->manager)
            ->get(route('dashboard'))
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_public_contact_form_is_throttled(): void
    {
        $payload = [
            'name' => 'زائر', 'email' => 'visitor@example.test',
            'subject' => 'استفسار', 'message' => 'رسالة تجريبية',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->post('/contact', $payload)->assertStatus(302);
        }
        $this->post('/contact', $payload)->assertStatus(429);
    }

    public function test_csv_report_export_escapes_formula_cells(): void
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        Employee::create([
            'employee_code' => '7771', 'first_name' => 'Formula',
            'full_name' => '=1+1', 'company_id' => $this->company->id, 'status_id' => $status->id,
        ]);

        $response = $this->actingAs($this->manager)->get(route('reports.export', 'employees'));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString("'=1+1", $content);
        $this->assertStringNotContainsString(',=1+1', $content);
    }

    public function test_no_legacy_uploads_ship_inside_public_storage(): void
    {
        $base = public_path('storage');
        $offenders = [];
        if (is_dir($base)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getFilename() !== '.gitkeep') $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'Files under public/storage are served by the web server without authentication.');
    }

    public function test_no_uploaded_files_are_tracked_in_git(): void
    {
        $root = base_path();
        $tracked = [];
        exec('git -C '.escapeshellarg($root).' ls-files storage/app/private public/storage', $tracked);
        $tracked = array_filter($tracked, fn ($f) => basename($f) !== '.gitkeep');
        $this->assertSame([], $tracked, 'Uploaded files and DB dumps must never be committed to the repository.');
    }
}
