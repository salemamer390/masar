<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyLaunchReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        if (!Route::has('employees.photo.show')) {
            Route::get('/employees/{employee}/photo', fn () => '')->name('employees.photo.show');
        }
        $_ENV['OWNER_EMAILS'] = $_SERVER['OWNER_EMAILS'] = 'owner@example.com';
        putenv('OWNER_EMAILS=owner@example.com');
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://hr.example.com');
        config()->set('session.secure', true);
    }

    public function test_passes_when_environment_and_storage_are_ready(): void
    {
        $this->assertSame(0, Artisan::call('masar:verify-launch-readiness'));
        $this->assertStringContainsString('كل فحوص الجاهزية ناجحة', Artisan::output());
    }

    public function test_fails_loudly_when_debug_is_enabled(): void
    {
        config()->set('app.debug', true);

        $this->assertSame(1, Artisan::call('masar:verify-launch-readiness'));
        $this->assertStringContainsString('APP_DEBUG', Artisan::output());
    }

    public function test_fails_when_owner_allowlist_is_empty(): void
    {
        $_ENV['OWNER_EMAILS'] = $_SERVER['OWNER_EMAILS'] = '';
        putenv('OWNER_EMAILS');

        $this->assertSame(1, Artisan::call('masar:verify-launch-readiness'));
        $this->assertStringContainsString('OWNER_EMAILS', Artisan::output());
    }

    public function test_fails_while_employee_files_remain_on_the_public_disk(): void
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $company = Company::create(['name' => 'Ready Co', 'slug' => 'ready-co', 'status' => 'active']);
        $employee = Employee::create([
            'employee_code' => '9801', 'first_name' => 'Ready', 'full_name' => 'Ready Employee',
            'company_id' => $company->id, 'status_id' => $status->id,
        ]);
        Storage::disk('public')->put('documents/1/old.pdf', 'legacy-bytes');
        Document::create([
            'employee_id' => $employee->id, 'document_name' => 'Old',
            'file_name' => 'old.pdf', 'file_path' => 'documents/1/old.pdf', 'status' => 'مكتمل',
        ]);

        $this->assertSame(1, Artisan::call('masar:verify-launch-readiness'));
        $this->assertStringContainsString('masar:migrate-private-storage', Artisan::output());
    }
}
