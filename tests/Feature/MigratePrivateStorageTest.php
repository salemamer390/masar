<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Document;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigratePrivateStorageTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $company = Company::create(['name' => 'Mig Co', 'slug' => 'mig-co', 'status' => 'active']);
        $this->employee = Employee::create([
            'employee_code' => '9701', 'first_name' => 'Mig', 'full_name' => 'Mig Employee',
            'company_id' => $company->id, 'status_id' => $status->id,
        ]);
    }

    private function document(string $path): Document
    {
        return Document::create([
            'employee_id' => $this->employee->id, 'document_name' => 'Doc',
            'file_name' => 'doc.pdf', 'file_path' => $path, 'status' => 'مكتمل',
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        Storage::disk('public')->put('documents/1/a.pdf', 'bytes-a');
        $this->document('documents/1/a.pdf');

        $this->assertSame(0, Artisan::call('masar:migrate-private-storage', ['--dry-run' => true]));

        Storage::disk('public')->assertExists('documents/1/a.pdf');
        Storage::disk('local')->assertMissing('documents/1/a.pdf');
    }

    public function test_move_copies_bytes_before_removing_public_and_is_idempotent(): void
    {
        Storage::disk('public')->put('employees/1/profile/p.jpg', 'photo-bytes');
        $this->employee->update(['profile_photo_path' => 'employees/1/profile/p.jpg']);

        $this->assertSame(0, Artisan::call('masar:migrate-private-storage'));

        Storage::disk('local')->assertExists('employees/1/profile/p.jpg');
        $this->assertSame('photo-bytes', Storage::disk('local')->get('employees/1/profile/p.jpg'));
        Storage::disk('public')->assertMissing('employees/1/profile/p.jpg');
        $this->assertSame('employees/1/profile/p.jpg', $this->employee->fresh()->profile_photo_path);

        // A second run has nothing left to move and stays successful.
        $this->assertSame(0, Artisan::call('masar:migrate-private-storage'));
        $this->assertSame('photo-bytes', Storage::disk('local')->get('employees/1/profile/p.jpg'));
    }

    public function test_identical_public_duplicate_is_removed_after_integrity_check(): void
    {
        Storage::disk('local')->put('documents/1/dup.pdf', 'same-bytes');
        Storage::disk('public')->put('documents/1/dup.pdf', 'same-bytes');
        $this->document('documents/1/dup.pdf');

        $this->assertSame(0, Artisan::call('masar:migrate-private-storage'));

        Storage::disk('local')->assertExists('documents/1/dup.pdf');
        Storage::disk('public')->assertMissing('documents/1/dup.pdf');
    }

    public function test_conflicting_copies_are_both_kept_and_reported(): void
    {
        Storage::disk('local')->put('documents/1/conf.pdf', 'private-bytes');
        Storage::disk('public')->put('documents/1/conf.pdf', 'different-public-bytes');
        $this->document('documents/1/conf.pdf');

        $this->assertSame(1, Artisan::call('masar:migrate-private-storage'));

        $this->assertSame('private-bytes', Storage::disk('local')->get('documents/1/conf.pdf'));
        $this->assertSame('different-public-bytes', Storage::disk('public')->get('documents/1/conf.pdf'));
    }

    public function test_missing_file_on_both_disks_is_warned_not_fatal(): void
    {
        $this->document('documents/1/ghost.pdf');

        $this->assertSame(0, Artisan::call('masar:migrate-private-storage'));

        $this->assertSame('documents/1/ghost.pdf', $this->employee->fresh()->documents()->first()->file_path);
    }
}
