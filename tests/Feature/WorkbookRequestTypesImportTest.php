<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeImportRow;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\Permission;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Imports\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkbookRequestTypesImportTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\CompleteEmployeeImportFixtures;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'wb-req-import-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'ReqImportCo', 'slug' => 'req-import-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'wb-req-import@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([['501', 'خالد الأول'], ['502', 'منى الثانية']] as [$code, $name]) {
            Employee::create(['employee_code' => $code, 'full_name' => $name, 'first_name' => $name, 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01', 'gross_salary' => 10000]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function employee(string $code): Employee
    {
        return Employee::where('company_id', $this->company->id)->where('employee_code', $code)->firstOrFail();
    }

    private function type(string $source): RequestType
    {
        return RequestType::active()->where('source_key', $source)->firstOrFail();
    }

    private function makeRequest(Employee $employee, string $source, array $payload): HrRequest
    {
        $type = $this->type($source);
        return HrRequest::create([
            'employee_id' => $employee->id, 'request_type' => $type->code, 'request_type_id' => $type->id,
            'title' => $type->display_name, 'payload' => $payload, 'status' => 'Approved',
            'created_by' => $this->user->id, 'reviewed_by' => $this->user->id, 'reviewed_at' => now(),
        ]);
    }

    private function workbook(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wb') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, $this->completeEmployeeSheets(array_merge([[
            'name' => 'الموظفين',
            'rows' => [['الكود', 'الاسم الكامل', 'تاريخ التعيين'], ['900', 'موظف جديد', '2024-01-01']],
        ]], $sheets)));

        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    private function commitImport(array $params): \Illuminate\Testing\TestResponse
    {
        $token = $this->postJson('/employees/import/preview', $params)->json('review_token');
        $this->assertNotEmpty($token, 'preview must issue a review token');

        return $this->post('/employees/import', $params + ['review_token' => $token]);
    }

    public function test_permission_rows_import_in_arabic_and_english(): void
    {
        $file = $this->workbook([
            ['name' => 'الأذونات', 'rows' => [
                ['كود الموظف', 'تاريخ الطلب', 'من الساعة', 'إلى الساعة', 'نوع الطلب'],
                ['501', '2026-11-10', '10:00', '12:00', 'إذن'],
            ]],
            ['name' => 'Permissions', 'rows' => [
                ['Employee Code', 'Request Date', 'From Time', 'To Time', 'Request Type'],
                ['502', '2026-11-11', '09:30', '11:00', 'permission'],
            ]],
        ]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, (int) $response->json('distributed.requests.imported'), (string) $response->getContent());

        $arabic = HrRequest::where('employee_id', $this->employee('501')->id)->firstOrFail();
        $this->assertSame('permission', $arabic->requestType?->source_key ?: $arabic->request_type);
        $this->assertSame(['request_date' => '2026-11-10', 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'from_time' => '10:00', 'to_time' => '12:00'], [
            'request_date' => data_get($arabic->payload, 'request_date'),
            'start_date' => data_get($arabic->payload, 'start_date'),
            'end_date' => data_get($arabic->payload, 'end_date'),
            'from_time' => data_get($arabic->payload, 'from_time'),
            'to_time' => data_get($arabic->payload, 'to_time'),
        ]);

        $english = HrRequest::where('employee_id', $this->employee('502')->id)->firstOrFail();
        $this->assertSame('permission', $english->requestType?->source_key ?: $english->request_type);
        $this->assertSame('09:30', data_get($english->payload, 'from_time'));
        $this->assertSame('11:00', data_get($english->payload, 'to_time'));
    }

    public function test_mission_rows_import_with_optional_end_date(): void
    {
        $file = $this->workbook([
            ['name' => 'المأموريات', 'rows' => [
                ['كود الموظف', 'تاريخ الطلب', 'تاريخ الانتهاء', 'نوع الطلب'],
                ['501', '2026-11-12', '2026-11-14', 'مأمورية'],
                ['502', '2026-11-15', '', 'مأمورية'],
            ]],
        ]);

        $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);

        $spanned = HrRequest::where('employee_id', $this->employee('501')->id)->firstOrFail();
        $this->assertSame('mission', $spanned->requestType?->source_key ?: $spanned->request_type);
        $this->assertSame('2026-11-12', data_get($spanned->payload, 'start_date'));
        $this->assertSame('2026-11-14', data_get($spanned->payload, 'end_date'));

        $singleDay = HrRequest::where('employee_id', $this->employee('502')->id)->firstOrFail();
        $this->assertSame('2026-11-15', data_get($singleDay->payload, 'request_date'));
        $this->assertNull(data_get($singleDay->payload, 'end_date'));
    }

    public function test_second_advance_in_same_month_is_rejected_with_the_existing_date(): void
    {
        $this->makeRequest($this->employee('501'), 'advance', ['request_date' => '2026-11-03', 'amount' => 1000]);

        $file = $this->workbook([['name' => 'السلف', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'مبلغ السلفة'],
            ['501', '2026-11-20', '500'],
            ['502', '2026-11-21', '700'],
        ]]]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $response->json('distributed.requests.imported'));
        $this->assertSame(1, (int) $response->json('distributed.requests.skipped'));

        $errors = implode(' ', (array) $response->json('distributed.requests.errors'));
        $this->assertStringContainsString('نفس الشهر', $errors);
        $this->assertStringContainsString('2026-11-03', $errors);

        $this->assertSame(2, HrRequest::count());
        $this->assertSame(1, HrRequest::where('employee_id', $this->employee('501')->id)->count());
        $parked = EmployeeImportRow::where('kind', 'requests')->get();
        $this->assertCount(1, $parked);
        $this->assertStringContainsString('نفس الشهر', implode(' ', $parked->first()->errors));
    }

    public function test_second_bonus_in_same_month_is_rejected(): void
    {
        $this->makeRequest($this->employee('501'), 'bonus', ['request_date' => '2026-11-05', 'amount' => 300]);

        $file = $this->workbook([['name' => 'الطلبات', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'المبلغ', 'نوع الطلب'],
            ['501', '2026-11-25', '200', 'مكافأة'],
        ]]]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, (int) $response->json('distributed.requests.imported'));
        $this->assertStringContainsString('نفس الشهر', implode(' ', (array) $response->json('distributed.requests.errors')));
        $this->assertSame(1, HrRequest::count());
    }

    public function test_duplicate_incentive_same_month_imports_with_a_dated_warning_in_preview_and_commit(): void
    {
        $this->makeRequest($this->employee('501'), 'incentive', ['request_date' => '2026-11-02', 'amount' => 150]);

        $file = $this->workbook([['name' => 'الطلبات', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'المبلغ', 'نوع الطلب'],
            ['501', '2026-11-09', '250', 'حافز'],
        ]]]);

        $preview = $this->postJson('/employees/import/preview', ['file' => $file])->assertOk();
        $previewWarnings = implode(' ', (array) $preview->json('domains.requests.warnings'));
        $this->assertStringContainsString('2026-11-02', $previewWarnings);
        $this->assertSame(1, HrRequest::count(), 'preview must not save anything');

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $response->json('distributed.requests.imported'));
        $warnings = implode(' ', (array) $response->json('distributed.requests.warnings'));
        $this->assertStringContainsString('2026-11-02', $warnings);
        $this->assertSame(2, HrRequest::count());
    }

    public function test_overtime_hours_come_from_the_window_not_the_sheet(): void
    {
        $file = $this->workbook([['name' => 'الإضافي', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'عدد الساعات', 'نوع الساعات', 'من الساعة', 'إلى الساعة', 'نوع الطلب'],
            ['501', '2026-11-06', '99', 'ليلي', '22:00', '02:00', 'إضافي'],
        ]]]);

        $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);

        $request = HrRequest::where('employee_id', $this->employee('501')->id)->firstOrFail();
        $this->assertSame('overtime', $request->requestType?->source_key ?: $request->request_type);
        $this->assertSame(4.0, (float) data_get($request->payload, 'hours'));
        $this->assertSame('night', data_get($request->payload, 'overtime_period'));
        $this->assertSame('22:00', data_get($request->payload, 'from_time'));
        $this->assertSame('02:00', data_get($request->payload, 'to_time'));
    }

    public function test_overtime_duplicate_same_day_and_period_warns_but_imports(): void
    {
        $this->makeRequest($this->employee('501'), 'overtime', ['request_date' => '2026-11-05', 'hours' => 2, 'overtime_period' => 'day', 'from_time' => '17:00', 'to_time' => '19:00']);

        $file = $this->workbook([['name' => 'الإضافي', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'من الساعة', 'إلى الساعة', 'نوع الطلب'],
            ['501', '2026-11-05', '19:00', '21:00', 'إضافي'],
        ]]]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $response->json('distributed.requests.imported'));
        $warnings = implode(' ', (array) $response->json('distributed.requests.warnings'));
        $this->assertStringContainsString('نفس اليوم', $warnings);
        $this->assertStringContainsString('2026-11-05', $warnings);
        $this->assertSame(2, HrRequest::count());
    }

    public function test_permission_and_mission_duplicates_never_warn(): void
    {
        $this->makeRequest($this->employee('501'), 'permission', ['request_date' => '2026-11-10', 'start_date' => '2026-11-10', 'end_date' => '2026-11-10', 'from_time' => '10:00', 'to_time' => '11:00']);
        $this->makeRequest($this->employee('501'), 'mission', ['request_date' => '2026-11-12', 'start_date' => '2026-11-12']);

        $file = $this->workbook([['name' => 'الطلبات', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'من الساعة', 'إلى الساعة', 'نوع الطلب'],
            ['501', '2026-11-10', '12:00', '13:00', 'إذن'],
            ['501', '2026-11-12', '', '', 'مأمورية'],
        ]]]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, (int) $response->json('distributed.requests.imported'), (string) $response->getContent());
        $this->assertEmpty((array) $response->json('distributed.requests.warnings'));
        $this->assertSame(4, HrRequest::count());
    }

    public function test_document_and_other_types_stay_rejected_in_both_languages(): void
    {
        $file = $this->workbook([['name' => 'الطلبات', 'rows' => [
            ['كود الموظف', 'تاريخ الطلب', 'المبلغ', 'نوع الطلب'],
            ['501', '2026-11-03', '100', 'طلب مستند'],
            ['501', '2026-11-04', '100', 'document'],
            ['502', '2026-11-05', '100', 'طلب آخر'],
            ['502', '2026-11-06', '100', 'other'],
        ]]]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, (int) $response->json('distributed.requests.imported'));
        $this->assertSame(4, (int) $response->json('distributed.requests.skipped'));
        $this->assertSame(0, HrRequest::count());
        $this->assertSame(4, substr_count(implode(' ', (array) $response->json('distributed.requests.errors')), 'نوع الطلب غير معروف'));
    }

    public function test_english_type_values_import_with_the_right_types(): void
    {
        $file = $this->workbook([['name' => 'Requests', 'rows' => [
            ['Employee Code', 'Request Date', 'Amount', 'From Time', 'To Time', 'Request Type'],
            ['501', '2026-11-03', '1000', '', '', 'advance'],
            ['501', '2026-11-06', '', '17:00', '20:00', 'overtime'],
            ['501', '2026-11-07', '150', '', '', 'incentive'],
            ['501', '2026-11-08', '200', '', '', 'commission'],
            ['501', '2026-11-09', '300', '', '', 'profit share'],
            ['501', '2026-11-10', '400', '', '', 'bonus'],
            ['502', '2026-11-11', '', '09:00', '10:00', 'permission'],
            ['502', '2026-11-12', '', '', '', 'mission'],
        ]]]);

        $response = $this->commitImport(['file' => $file, 'ack_updates' => '1'])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(8, (int) $response->json('distributed.requests.imported'), (string) $response->getContent());

        $sources = HrRequest::with('requestType')->get()->map(fn (HrRequest $r) => $r->requestType?->source_key ?: $r->request_type)->sort()->values()->all();
        $this->assertSame(['advance', 'bonus', 'commission', 'incentive', 'mission', 'overtime', 'permission', 'profit_share'], $sources);

        $overtime = HrRequest::with('requestType')->get()->first(fn (HrRequest $r) => ($r->requestType?->source_key ?: $r->request_type) === 'overtime');
        $this->assertSame(3.0, (float) data_get($overtime->payload, 'hours'));
    }
}
