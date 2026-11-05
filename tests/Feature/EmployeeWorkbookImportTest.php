<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRow;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\InsuranceRecord;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Imports\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeWorkbookImportTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'wb-import-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'WorkbookCo', 'slug' => 'workbook-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'wb-import@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        LeaveType::firstOrCreate(['name' => 'سنوية', 'company_id' => $this->company->id], ['default_days' => 30, 'deducts_annual_balance' => false]);
        PenaltyType::firstOrCreate(['name' => 'خصم', 'company_id' => $this->company->id], ['default_deduction_days' => 1]);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function workbook(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wb') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, [
            ['name' => 'الموظفين', 'rows' => [
                ['الكود', 'الاسم الكامل', 'الرقم التأميني', 'رقم الهاتف', 'تاريخ التعيين'],
                ['301', 'سارة الجديدة', 'INS-301', '01011112222', '2024-01-01'],
                ['302', 'عمرو الجديد', 'INS-302', '01022223333', '2024-01-01'],
                ['303', 'صف مكسور', 'INS-303', '123', '2024-01-01'],
            ]],
            ['name' => 'التأمينات', 'rows' => [
                ['كود الموظف', 'اسم الموظف', 'الرقم التأميني', 'الأجر التأميني', 'مكتب التأمينات', 'الحالة التأمينية'],
                ['301', 'سارة الجديدة', 'INS-301', '5000', 'مكتب وسط القاهرة', 'مسجل'],
                ['999', 'موظف غير موجود', 'INS-999', '4000', '', 'مسجل'],
            ]],
            ['name' => 'الحضور', 'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['301', '2026-11-02', '09:00', '17:00'],
                ['302', 'not-a-date', '09:00', '17:00'],
            ]],
            ['name' => 'الإجازات', 'rows' => [
                ['كود الموظف', 'اسم الموظف', 'نوع الإجازة', 'من تاريخ', 'إلى تاريخ', 'عدد الأيام'],
                ['301', 'سارة الجديدة', 'سنوية', '2026-11-10', '2026-11-11', '2'],
                ['302', 'عمرو الجديد', 'نوع غير موجود', '2026-11-10', '2026-11-11', '2'],
            ]],
            ['name' => 'الجزاءات', 'rows' => [
                ['كود الموظف', 'نوع الجزاء', 'تاريخ القرار', 'أيام الخصم'],
                ['302', 'خصم', '2026-11-05', '1'],
            ]],
            ['name' => 'الطلبات', 'rows' => [
                ['كود الموظف', 'تاريخ الطلب', 'مبلغ السلفة'],
                ['301', '2026-11-03', '1000'],
            ]],
            ['name' => 'ملخص', 'rows' => [
                ['بند', 'قيمة'],
                ['إجمالي العمالة', '2'],
                ['متوسط الأجور', '5000'],
            ]],
        ]);

        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    public function test_preview_classifies_every_sheet_and_simulates_domains_without_saving(): void
    {
        $response = $this->postJson('/employees/import/preview', ['file' => $this->workbook()]);
        $response->assertOk()->assertJson(['success' => true]);
        $data = $response->json();

        $kinds = collect($data['sheets'])->pluck('kind', 'name')->all();
        $this->assertSame('employees', $kinds['الموظفين']);
        $this->assertSame('insurance', $kinds['التأمينات']);
        $this->assertSame('attendance', $kinds['الحضور']);
        $this->assertSame('leaves', $kinds['الإجازات']);
        $this->assertSame('penalties', $kinds['الجزاءات']);
        $this->assertContains($kinds['الطلبات'], ['requests', 'advances']);
        $this->assertSame('unknown', $kinds['ملخص']);

        $this->assertContains('ملخص', array_column($data['review_sheets'], 'name'));

        $this->assertSame([1, 1], [$data['domains']['attendance']['imported'], $data['domains']['attendance']['skipped']]);
        $this->assertSame(0, $data['selected_sheet']); // الموظفين = sheet index 0
        $this->assertSame([1, 1], [$data['domains']['leaves']['imported'], $data['domains']['leaves']['skipped']]);
        $this->assertSame([1, 0], [$data['domains']['penalties']['imported'], $data['domains']['penalties']['skipped']]);
        $this->assertSame([1, 0], [$data['domains']['requests']['imported'], $data['domains']['requests']['skipped']]);
        $this->assertSame([1, 1], [$data['domains']['insurance']['imported'], $data['domains']['insurance']['skipped']]);
        $this->assertNotEmpty($data['domains']['insurance']['errors']);

        // The dry-run must not persist anything anywhere.
        $this->assertSame(0, Employee::where('company_id', $this->company->id)->count());
        $this->assertSame(0, InsuranceRecord::count());
        $this->assertSame(0, Attendance::count());
        $this->assertSame(0, Leave::count());
        $this->assertSame(0, Penalty::count());
        $this->assertSame(0, HrRequest::count());
        $this->assertSame(0, EmployeeImportBatch::count());
        $this->assertSame(0, EmployeeImportRow::count());
    }

    public function test_commit_imports_every_domain_and_parks_failures(): void
    {
        $response = $this->post('/employees/import', ['file' => $this->workbook(), 'ack_updates' => '1']);
        $response->assertOk()->assertJson(['success' => true]);
        $data = $response->json();

        $sara = Employee::where('company_id', $this->company->id)->where('employee_code', '301')->firstOrFail();
        $amr = Employee::where('company_id', $this->company->id)->where('employee_code', '302')->firstOrFail();

        // Insurance: matched by code for an employee created by the SAME import.
        $record = InsuranceRecord::where('employee_id', $sara->id)->firstOrFail();
        $this->assertSame('INS-301', $record->insurance_number);
        $this->assertSame('5000.00', (string) $record->insured_salary);
        $this->assertSame('مكتب وسط القاهرة', $record->insurance_office);
        $this->assertSame('registered', $record->status);

        $this->assertSame(1, Attendance::where('employee_id', $sara->id)->whereDate('attendance_date', '2026-11-02')->count());
        $this->assertSame(1, Leave::where('employee_id', $sara->id)->count());
        $this->assertSame(1, Penalty::where('employee_id', $amr->id)->count());
        $this->assertSame(1, HrRequest::where('employee_id', $sara->id)->count());

        $batch = EmployeeImportBatch::findOrFail($data['batch_id']);
        $this->assertSame(2, $batch->created);
        $this->assertSame(1, $batch->distributed['attendance']['imported']);
        $this->assertSame(1, $batch->distributed['attendance']['skipped']);
        $this->assertSame(1, $batch->distributed['leaves']['imported']);
        $this->assertSame(1, $batch->distributed['leaves']['skipped']);
        $this->assertSame(1, $batch->distributed['penalties']['imported']);
        $this->assertSame(1, $batch->distributed['requests']['imported']);
        $this->assertSame(1, $batch->distributed['insurance']['imported']);
        $this->assertSame(1, $batch->distributed['insurance']['skipped']);

        // Parked rows: broken employee row + one failure per domain.
        $parked = EmployeeImportRow::where('batch_id', $batch->id)->get()->groupBy('kind');
        $this->assertCount(1, $parked['employees']);
        $this->assertCount(1, $parked['insurance']);
        $this->assertCount(1, $parked['attendance']);
        $this->assertCount(1, $parked['leaves']);
        $this->assertStringContainsString('غير موجود', implode(' ', $parked['insurance']->first()->errors));
        $this->assertSame('pending', $parked['insurance']->first()->status);
        $this->assertSame('التأمينات', $parked['insurance']->first()->sheet);

        // Parked domain payloads are encrypted at rest.
        $stored = DB::table('employee_import_rows')->where('id', $parked['insurance']->first()->id)->value('payload');
        $this->assertStringNotContainsString('INS-999', (string) $stored);

        // A non-employee row cannot be force-fixed through the employee flow.
        $insuranceRow = $parked['insurance']->first();
        $this->putJson('/employees/import-rows/' . $insuranceRow->id, [
            'version' => 0, 'reason' => 'محاولة', 'values' => ['full_name' => 'x'],
        ])->assertStatus(422);
        // But it can be dismissed with a reason.
        $this->delete('/employees/import-rows/' . $insuranceRow->id, ['version' => 0, 'reason' => 'موظف لا يخص الشركة'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('dismissed', $insuranceRow->refresh()->status);
    }

    public function test_resubmitting_the_same_workbook_creates_no_duplicates(): void
    {
        $this->post('/employees/import', ['file' => $this->workbook(), 'ack_updates' => '1'])->assertOk();
        $second = $this->post('/employees/import', ['file' => $this->workbook(), 'ack_updates' => '1'])->assertOk()->json();

        $this->assertSame(0, $second['result']['created']);
        $this->assertSame(2, Employee::where('company_id', $this->company->id)->whereIn('employee_code', ['301', '302'])->count());
        $this->assertSame(1, Leave::count());
        $this->assertSame(1, Penalty::count());
        $this->assertSame(1, HrRequest::count());
        $this->assertSame(1, InsuranceRecord::count());
        $this->assertSame(2, EmployeeImportBatch::count());
    }

    public function test_insurance_sheet_rejects_ambiguous_and_conflicting_matches(): void
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        Employee::create(['employee_code' => '401', 'full_name' => 'توأم أول', 'first_name' => 'توأم', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01']);
        Employee::create(['employee_code' => '402', 'full_name' => 'توأم أول', 'first_name' => 'توأم', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01', 'insurance_number' => 'INS-OTHER']);
        Employee::create(['employee_code' => '403', 'full_name' => 'صاحب رقم', 'first_name' => 'صاحب', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01', 'insurance_number' => 'INS-403']);

        $path = tempnam(sys_get_temp_dir(), 'wb') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, [
            ['name' => 'الموظفين', 'rows' => [
                ['الكود', 'الاسم الكامل', 'الرقم القومي'],
                ['410', 'موظف جديد', ''],
            ]],
            ['name' => 'التأمينات', 'rows' => [
                ['كود الموظف', 'اسم الموظف', 'الرقم التأميني', 'الأجر التأميني'],
                ['', 'توأم أول', 'INS-AMBIG', '3000'],          // ambiguous name: two employees share it
                ['403', 'صاحب رقم', 'INS-OTHER', '3000'],       // conflict: code 403 carries INS-403 already
                ['999', 'لا أحد', 'INS-404', '3000'],           // no match at all
                ['555', 'صاحب رقم', 'INS-403', '3000'],         // unknown code: must not fall back to INS-403's owner
            ]],
        ]);
        $file = UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));

        $response = $this->post('/employees/import', ['file' => $file, 'ack_updates' => '1']);
        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, InsuranceRecord::count());
        $rows = EmployeeImportRow::where('kind', 'insurance')->orderBy('line')->get();
        $this->assertCount(4, $rows);
        $this->assertStringContainsString('أكثر من موظف', implode(' ', $rows[0]->errors));
        $this->assertStringContainsString('مختلفين', implode(' ', $rows[1]->errors));
        $this->assertStringContainsString('غير موجود في الشركة', implode(' ', $rows[2]->errors));
        $this->assertStringContainsString('غير موجود في الشركة', implode(' ', $rows[3]->errors));
    }

    public function test_review_queue_is_company_scoped_and_permission_gated(): void
    {
        $this->post('/employees/import', ['file' => $this->workbook(), 'ack_updates' => '1'])->assertOk();
        $row = EmployeeImportRow::firstOrFail();

        // Another company's manager cannot see or touch these rows at all.
        $otherCompany = Company::create(['name' => 'OtherCo', 'slug' => 'other-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $otherCompany->id, 'subscription_plan_id' => SubscriptionPlan::firstOrCreate(['slug' => 'wb-import-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []])->id, 'status' => 'active', 'starts_at' => today()]);
        $otherRole = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $otherCompany->id]);
        $otherRole->permissions()->sync(Permission::pluck('id'));
        $otherUser = User::create(['name' => 'Other', 'email' => 'other-co@example.test', 'password' => bcrypt('test-only'), 'role_id' => $otherRole->id, 'company_id' => $otherCompany->id, 'is_active' => true]);

        $this->actingAs($otherUser);
        $this->get('/employees/import-batches')->assertOk()->assertDontSee('workbook.xlsx');
        $this->get('/employees/import-rows/' . $row->id)->assertNotFound();
        $this->putJson('/employees/import-rows/' . $row->id, ['version' => 0, 'reason' => 'x', 'values' => []])->assertNotFound();
        $this->deleteJson('/employees/import-rows/' . $row->id, ['version' => 0, 'reason' => 'x'])->assertNotFound();

        // A same-company user without employees.import gets no access either.
        $limitedRole = Role::create(['name' => 'viewer', 'display_name' => 'Viewer', 'company_id' => $this->company->id]);
        $limitedRole->permissions()->sync([]);
        $limited = User::create(['name' => 'Viewer', 'email' => 'viewer-co@example.test', 'password' => bcrypt('test-only'), 'role_id' => $limitedRole->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($limited);
        $this->get('/employees/import-batches')->assertRedirect();
        $this->get('/employees/import-rows/' . $row->id)->assertRedirect();
        $this->putJson('/employees/import-rows/' . $row->id, ['version' => 0, 'reason' => 'x', 'values' => []])->assertStatus(403);
        $this->deleteJson('/employees/import-rows/' . $row->id, ['version' => 0, 'reason' => 'x'])->assertStatus(403);
    }

    public function test_a_row_that_fails_at_write_time_does_not_block_the_rest(): void
    {
        Employee::creating(function (Employee $employee) {
            if ($employee->employee_code === '302') {
                throw new \RuntimeException('forced write failure');
            }
        });

        $response = $this->post('/employees/import', ['file' => $this->workbook(), 'ack_updates' => '1']);
        $response->assertOk()->assertJson(['success' => true]);
        $data = $response->json();

        // 301 committed, 302 reported as failed and parked - no partial writes.
        $this->assertSame(1, $data['result']['created']);
        $this->assertCount(1, $data['result']['failed']);
        $this->assertSame(1, Employee::where('company_id', $this->company->id)->where('employee_code', '301')->count());
        $this->assertSame(0, Employee::where('company_id', $this->company->id)->where('employee_code', '302')->count());

        $batch = EmployeeImportBatch::findOrFail($data['batch_id']);
        $this->assertSame(1, EmployeeImportRow::where('batch_id', $batch->id)->where('kind', 'employees')->where('line', 4)->count());
    }

    private function seedMatchingFixtures(): void
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        foreach ([['401', 'توأم أول'], ['402', 'توأم أول'], ['403', 'صاحب كود'], ['404', 'فريد الاسم']] as [$code, $name]) {
            Employee::create(['employee_code' => $code, 'full_name' => $name, 'first_name' => $name, 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01']);
        }
    }

    private function workbookWithDomainSheet(array $domainSheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wb') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, [
            ['name' => 'الموظفين', 'rows' => [
                ['الكود', 'الاسم الكامل', 'الرقم القومي'],
                ['410', 'موظف جديد', ''],
            ]],
            $domainSheet,
        ]);

        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    private function assertAmbiguousAndConflictingRowsParked(string $kind): void
    {
        $rows = EmployeeImportRow::where('kind', $kind)->orderBy('line')->get();
        $this->assertCount(3, $rows);
        $this->assertStringContainsString('أكثر من موظف', implode(' ', $rows[0]->errors));
        $this->assertStringContainsString('لا يطابق الاسم', implode(' ', $rows[1]->errors));
        $this->assertStringContainsString('غير موجود في الشركة', implode(' ', $rows[2]->errors));
        foreach ($rows as $row) {
            $this->assertSame('pending', $row->status);
        }
    }

    public function test_attendance_sheet_rejects_ambiguous_and_conflicting_employee_matches(): void
    {
        $this->seedMatchingFixtures();
        $file = $this->workbookWithDomainSheet(['name' => 'الحضور', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
            ['', 'توأم أول', '2026-11-02', '09:00', '17:00'],         // ambiguous: two employees share the name
            ['403', 'اسم آخر تماما', '2026-11-03', '09:00', '17:00'], // conflict: code 403 belongs to صاحب كود
            ['', 'فريد الاسم', '2026-11-04', '09:00', '17:00'],       // unambiguous name still imports
            ['555', 'فريد الاسم', '2026-11-05', '09:00', '17:00'],      // unknown code: no name fallback
        ]]);

        $this->post('/employees/import', ['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);

        $unique = Employee::where('company_id', $this->company->id)->where('employee_code', '404')->firstOrFail();
        $this->assertSame(1, Attendance::count());
        $this->assertSame(1, Attendance::where('employee_id', $unique->id)->whereDate('attendance_date', '2026-11-04')->count());
        $this->assertAmbiguousAndConflictingRowsParked('attendance');
    }

    public function test_leave_sheet_rejects_ambiguous_and_conflicting_employee_matches(): void
    {
        $this->seedMatchingFixtures();
        $file = $this->workbookWithDomainSheet(['name' => 'الإجازات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'نوع الإجازة', 'من تاريخ', 'إلى تاريخ', 'عدد الأيام'],
            ['', 'توأم أول', 'سنوية', '2026-11-10', '2026-11-11', '2'],
            ['403', 'اسم آخر تماما', 'سنوية', '2026-11-10', '2026-11-11', '2'],
            ['', 'فريد الاسم', 'سنوية', '2026-11-10', '2026-11-11', '2'],
            ['555', 'فريد الاسم', 'سنوية', '2026-11-12', '2026-11-12', '1'], // unknown code: no name fallback
        ]]);

        $this->post('/employees/import', ['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);

        $unique = Employee::where('company_id', $this->company->id)->where('employee_code', '404')->firstOrFail();
        $this->assertSame(1, Leave::count());
        $this->assertSame(1, Leave::where('employee_id', $unique->id)->count());
        $this->assertAmbiguousAndConflictingRowsParked('leaves');
    }

    public function test_penalty_sheet_rejects_ambiguous_and_conflicting_employee_matches(): void
    {
        $this->seedMatchingFixtures();
        $file = $this->workbookWithDomainSheet(['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'نوع الجزاء', 'تاريخ القرار', 'أيام الخصم'],
            ['', 'توأم أول', 'خصم', '2026-11-05', '1'],
            ['403', 'اسم آخر تماما', 'خصم', '2026-11-05', '1'],
            ['', 'فريد الاسم', 'خصم', '2026-11-05', '1'],
            ['555', 'فريد الاسم', 'خصم', '2026-11-06', '1'],       // unknown code: no name fallback
        ]]);

        $this->post('/employees/import', ['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);

        $unique = Employee::where('company_id', $this->company->id)->where('employee_code', '404')->firstOrFail();
        $this->assertSame(1, Penalty::count());
        $this->assertSame(1, Penalty::where('employee_id', $unique->id)->count());
        $this->assertAmbiguousAndConflictingRowsParked('penalties');
    }

    public function test_request_sheet_rejects_ambiguous_and_conflicting_employee_matches(): void
    {
        $this->seedMatchingFixtures();
        $file = $this->workbookWithDomainSheet(['name' => 'الطلبات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ الطلب', 'مبلغ السلفة'],
            ['', 'توأم أول', '2026-11-03', '500'],
            ['403', 'اسم آخر تماما', '2026-11-03', '500'],
            ['', 'فريد الاسم', '2026-11-03', '500'],
            ['555', 'فريد الاسم', '2026-11-04', '500'],           // unknown code: no name fallback
        ]]);

        $this->post('/employees/import', ['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);

        $unique = Employee::where('company_id', $this->company->id)->where('employee_code', '404')->firstOrFail();
        $this->assertSame(1, HrRequest::count());
        $this->assertSame(1, HrRequest::where('employee_id', $unique->id)->count());
        $this->assertAmbiguousAndConflictingRowsParked('requests');
    }
}
