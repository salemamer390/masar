<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
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

/**
 * Workbooks with no employee table at all: vertical report sheets must never
 * turn into employees, and complete data sheets (attendance, penalties, ...)
 * import for the existing employees they name after review.
 */
class EmployeeImportDomainOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'domain-only-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'DomainOnlyCo', 'slug' => 'domain-only-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'domain-only@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        PenaltyType::firstOrCreate(['name' => 'خصم', 'company_id' => $this->company->id], ['default_deduction_days' => 1]);
        Employee::create(['employee_code' => '104', 'first_name' => 'عامر', 'full_name' => 'عامر سالم جمعه', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01']);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function xlsx(array $sheets, string $filename = 'workbook.xlsx'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'do') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, $sheets);

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($path));
    }

    /** Mirrors the user's payroll export: data sheets link by code/name, plus two vertical report sheets. */
    private function linkedWorkbook(): UploadedFile
    {
        return $this->xlsx([
            ['name' => 'الحضور والانصراف', 'rows' => [
                ['تقرير الحضور الشهري'],
                [],
                ['كود الموظف', 'اسم الموظف', 'التاريخ', 'اليوم', 'وقت الحضور', 'وقت الانصراف'],
                ['104', 'عامر سالم جمعه', '2026-11-02', 'الأحد', '09:00', '17:00'],
            ]],
            ['name' => 'الجزاءات', 'rows' => [
                ['كود الموظف', 'اسم الموظف', 'تاريخ القرار', 'نوع الجزاء', 'أيام الخصم'],
                ['104', 'عامر سالم جمعه', '2026-11-05', 'خصم', '1'],
            ]],
            ['name' => 'ملخص الرواتب', 'rows' => [
                ['ملخص الرواتب'],
                ['اسم الموظف'],
                ['الراتب الصافي الشهري'],
                ['أيام الحضور'],
                ['أيام الغياب'],
                ['صافي المستحق بعد الجزاءات'],
            ]],
            ['name' => 'بيانات الموظف', 'rows' => [
                ['بيانات الموظف'],
                ['اسم الموظف', 'عامر سالم جمعه'],
                ['كود الموظف', '104'],
                ['تاريخ التعيين', '2024-01-01'],
                ['القسم', 'التشغيل'],
            ]],
        ]);
    }

    /** Same export without code/name columns on the data sheets: rows can never link to employees. */
    private function unlinkedWorkbook(): UploadedFile
    {
        return $this->xlsx([
            ['name' => 'الحضور والانصراف', 'rows' => [
                ['تقرير الحضور الشهري'],
                [],
                ['التاريخ', 'اليوم', 'وقت الحضور', 'وقت الانصراف'],
                ['2026-11-02', 'الأحد', '09:00', '17:00'],
            ]],
            ['name' => 'الجزاءات', 'rows' => [
                ['تقرير الجزاءات'],
                [],
                ['التاريخ', 'السبب'],
                ['2026-11-05', 'تأخير'],
            ]],
            ['name' => 'ملخص الرواتب', 'rows' => [
                ['ملخص الرواتب'],
                ['اسم الموظف'],
                ['الراتب الصافي الشهري'],
                ['أيام الحضور'],
            ]],
        ]);
    }

    public function test_domain_only_workbook_reaches_review_and_commits_for_existing_employees(): void
    {
        $preview = $this->postJson('/employees/import/preview', ['file' => $this->linkedWorkbook()]);
        $preview->assertOk();
        $this->assertTrue((bool) $preview->json('domain_only'), 'a workbook whose data sheets are complete imports in domain-only mode');
        $this->assertSame(0, (int) $preview->json('summary.create'));
        $this->assertSame(0, (int) $preview->json('summary.update'));
        $this->assertFalse((bool) $preview->json('needs_mapping'));

        $kinds = collect($preview->json('sheets'))->keyBy('name');
        $this->assertSame('attendance', $kinds['الحضور والانصراف']['kind']);
        $this->assertSame('penalties', $kinds['الجزاءات']['kind']);
        $this->assertContains($kinds['ملخص الرواتب']['kind'], ['unknown', 'auxiliary'], 'vertical report sheets are never employee tables');
        $this->assertContains($kinds['بيانات الموظف']['kind'], ['unknown', 'auxiliary']);
        $this->assertSame('unknown', $kinds['ملخص الرواتب']['detected_kind']);

        $token = $preview->json('review_token');
        $this->assertNotEmpty($token, 'preview must issue a review token');
        $response = $this->post('/employees/import', ['file' => $this->linkedWorkbook(), 'review_token' => $token]);
        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Employee::where('company_id', $this->company->id)->count(), 'domain-only import never creates employees');
        $employee = Employee::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame(1, Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-02')->count());
        $this->assertSame(1, Penalty::where('employee_id', $employee->id)->count());
    }

    public function test_unlinkable_workbook_gets_precise_error_and_creates_nothing(): void
    {
        $preview = $this->postJson('/employees/import/preview', ['file' => $this->unlinkedWorkbook()]);
        $preview->assertStatus(422);
        $message = (string) $preview->json('message');
        $this->assertStringContainsString('الحضور والانصراف', $message);
        $this->assertStringContainsString('الجزاءات', $message);
        $this->assertStringContainsString('كود أو اسم الموظف', $message, 'the error names the missing linking columns instead of the generic name-column dead-end');
        $this->assertStringNotContainsString('أيام الحضور', Employee::pluck('full_name')->implode(','));
        $this->assertSame(1, Employee::where('company_id', $this->company->id)->count(), 'no report label becomes an employee');
        $this->assertSame(0, Attendance::count());
    }

    public function test_vertical_report_sheet_beside_an_employee_table_is_not_imported_as_people(): void
    {
        $file = $this->xlsx([
            ['name' => 'الموظفين', 'rows' => [
                ['الكود', 'الاسم الكامل', 'رقم الهاتف'],
                ['301', 'سارة الجديدة', '01011112222'],
            ]],
            ['name' => 'ملخص الرواتب', 'rows' => [
                ['ملخص الرواتب'],
                ['اسم الموظف'],
                ['الراتب الصافي الشهري'],
                ['أيام الحضور'],
                ['أيام الغياب'],
            ]],
        ]);
        $preview = $this->postJson('/employees/import/preview', ['file' => $file]);
        $preview->assertOk();
        $this->assertFalse((bool) $preview->json('domain_only'));
        $this->assertSame(1, (int) $preview->json('summary.create'), 'only the real employee table contributes rows');
        $this->assertSame('employees', $preview->json('sheets.0.kind'));
        $this->assertNotSame('employees', $preview->json('sheets.1.detected_kind'));

        $token = $preview->json('review_token');
        $response = $this->post('/employees/import', ['file' => $file, 'review_token' => $token]);
        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, Employee::where('company_id', $this->company->id)->count());
        $this->assertNull(Employee::where('company_id', $this->company->id)->where('full_name', 'أيام الحضور')->first());
    }

    public function test_single_sheet_name_only_contract_is_unchanged(): void
    {
        $csv = "الكود,الاسم الكامل\n501,موظف جديد\n";
        $file = UploadedFile::fake()->createWithContent('staff.csv', $csv);
        $preview = $this->postJson('/employees/import/preview', ['file' => $file]);
        $preview->assertOk();
        $this->assertFalse((bool) $preview->json('domain_only'));
        $this->assertSame(1, (int) $preview->json('summary.create'));

        $token = $preview->json('review_token');
        $this->post('/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv), 'review_token' => $token])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull(Employee::where('company_id', $this->company->id)->where('employee_code', '501')->first());
    }
}
