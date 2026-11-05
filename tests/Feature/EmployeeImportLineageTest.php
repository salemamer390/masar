<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRow;
use App\Models\EmployeeImportRowRevision;
use App\Models\EmployeeStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeImportLineageTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'emp-import-lineage-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'EmpImportLineage', 'slug' => 'emp-import-lineage', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'emp-import-lineage@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        Employee::create(['employee_code' => 'E1', 'first_name' => 'Hoda', 'full_name' => 'Hoda Import', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01']);
    }

    private function csvUpload(): UploadedFile
    {
        $csv = "الكود,الاسم الكامل,رقم الهاتف,البريد الإلكتروني,الرقم التأميني\n"
            . "E1,Hoda Import,01011112222,,9001\n"
            . "E2,New Person,01033334444,,9002\n"
            . "E3,Fix Me,01055556666,not-an-email,9003\n"
            . "E4,Skip Me,123,,9004\n";

        return UploadedFile::fake()->createWithContent('staff.csv', $csv);
    }

    private function commit(): array
    {
        $response = $this->post('/employees/import', ['file' => $this->csvUpload(), 'ack_updates' => '1']);
        $response->assertOk()->assertJson(['success' => true]);

        return $response->json();
    }

    public function test_preview_shows_field_level_changes_and_kind_labels(): void
    {
        $response = $this->postJson('/employees/import/preview', ['file' => $this->csvUpload()]);
        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json();
        $this->assertArrayHasKey('insurance', $data['kind_labels']);

        $update = collect($data['rows'])->firstWhere('action', 'update');
        $this->assertNotNull($update, 'Row E1 must be detected as an update of the existing employee.');
        $fields = array_column($update['changes'], 'field');
        $this->assertContains('phone', $fields);
        $this->assertContains('insurance_number', $fields);
        $phone = collect($update['changes'])->firstWhere('field', 'phone');
        $this->assertSame('01011112222', $phone['new']);

        $errors = collect($data['rows'])->where('action', 'error');
        $this->assertCount(2, $errors);
    }

    public function test_commit_records_lineage_and_parks_rejected_rows(): void
    {
        $data = $this->commit();
        $this->assertNotEmpty($data['batch_id']);
        $this->assertNotEmpty($data['batches_url']);

        $batch = EmployeeImportBatch::findOrFail($data['batch_id']);
        $this->assertSame($this->company->id, $batch->company_id);
        $this->assertSame(1, $batch->created);
        $this->assertSame(1, $batch->updated);
        $this->assertSame(2, $batch->rejected);

        $created = Employee::where('company_id', $this->company->id)->where('employee_code', 'E2')->firstOrFail();
        $this->assertSame($batch->id, $created->import_batch_id);

        $rows = EmployeeImportRow::where('batch_id', $batch->id)->orderBy('line')->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row->status === 'pending' && $row->kind === 'employees'));
        $this->assertStringContainsString('البريد الإلكتروني', implode(' ', $rows[0]->errors));
        $this->assertStringContainsString('الهاتف', implode(' ', $rows[1]->errors));

        // Rejected payloads stay private at rest: the raw column must not
        // contain the plaintext row data.
        $stored = DB::table('employee_import_rows')->where('id', $rows[0]->id)->value('payload');
        $this->assertStringNotContainsString('Fix Me', (string) $stored);
        $this->assertStringNotContainsString('not-an-email', (string) $stored);
        $this->assertSame('Fix Me', $rows[0]->payload['full_name']);

        $this->get('/employees/import-batches')->assertOk()->assertSee('staff.csv');
    }

    public function test_rejected_row_can_be_fixed_and_dismissed_with_audit(): void
    {
        $data = $this->commit();
        [$fixRow, $dismissRow] = EmployeeImportRow::where('batch_id', $data['batch_id'])->orderBy('line')->get()->all();

        $this->get('/employees/import-rows/' . $fixRow->id)->assertOk()->assertSee('البريد الإلكتروني');

        $this->put('/employees/import-rows/' . $fixRow->id, [
            'version' => 0,
            'reason' => 'تصحيح البريد الإلكتروني',
            'values' => [
                'employee_code' => 'E3',
                'full_name' => 'Fix Me',
                'phone' => '01055556666',
                'email' => 'fixed@example.test',
                'insurance_number' => '9003',
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $fixRow->refresh();
        $this->assertSame('fixed', $fixRow->status);
        $employee = Employee::where('company_id', $this->company->id)->where('employee_code', 'E3')->firstOrFail();
        $this->assertSame($fixRow->employee_id, $employee->id);
        $this->assertSame($data['batch_id'], $employee->import_batch_id);
        $this->assertSame('fixed@example.test', $employee->email);

        $revision = EmployeeImportRowRevision::where('import_row_id', $fixRow->id)->where('action', 'fix')->firstOrFail();
        $this->assertSame($this->user->id, $revision->user_id);
        $this->assertSame('تصحيح البريد الإلكتروني', $revision->reason);

        // A reviewed row cannot be committed twice.
        $this->put('/employees/import-rows/' . $fixRow->id, [
            'version' => 1,
            'reason' => 'محاولة ثانية',
            'values' => ['employee_code' => 'E3', 'full_name' => 'Fix Me'],
        ])->assertStatus(422);

        $this->delete('/employees/import-rows/' . $dismissRow->id, [
            'version' => 0,
            'reason' => 'صف لا يخص شركتنا',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $dismissRow->refresh();
        $this->assertSame('dismissed', $dismissRow->status);
        $this->assertDatabaseMissing('employees', ['company_id' => $this->company->id, 'employee_code' => 'E4']);
        $this->assertSame('dismiss', EmployeeImportRowRevision::where('import_row_id', $dismissRow->id)->firstOrFail()->action);

        // Fixed and dismissed rows leave the pending queue.
        $this->get('/employees/import-batches')->assertOk()->assertDontSee('Fix Me');
    }

    public function test_wrong_version_is_rejected_without_changes(): void
    {
        $data = $this->commit();
        $row = EmployeeImportRow::where('batch_id', $data['batch_id'])->orderBy('line')->firstOrFail();

        $this->delete('/employees/import-rows/' . $row->id, ['version' => 9, 'reason' => 'إصدار قديم'])
            ->assertStatus(409);
        $this->assertSame('pending', $row->refresh()->status);
        $this->assertSame(0, $row->revisions()->count());
    }
}
