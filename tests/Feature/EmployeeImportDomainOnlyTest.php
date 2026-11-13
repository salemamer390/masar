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
    use \Tests\CompleteEmployeeImportFixtures;

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
        (new XlsxWriter())->writeSheets($path, $this->completeEmployeeSheets($sheets));

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
        $preview->assertOk();
        $this->assertNotEmpty($preview->json('mapping_error'));
        $this->assertNotEmpty($preview->json('sheets.0.domain_config.headers'));
        $this->postJson('/employees/import', ['file' => $this->unlinkedWorkbook(), 'review_token' => $preview->json('review_token')])->assertStatus(422);
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

    public function test_single_sheet_missing_required_columns_stays_editable_but_cannot_commit(): void
    {
        $csv = "الكود,الاسم الكامل\n501,موظف جديد\n";
        $file = UploadedFile::fake()->createWithContent('staff.csv', $csv);
        $preview = $this->postJson('/employees/import/preview', ['file' => $file]);
        $preview->assertOk();
        $this->assertFalse((bool) $preview->json('domain_only'));
        $this->assertSame(0, (int) $preview->json('summary.create'));
        $this->assertStringContainsString('الرقم القومي', $preview->json('mapping_error'));

        $token = $preview->json('review_token');
        $this->post('/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv), 'review_token' => $token])
            ->assertStatus(422);
        $this->assertNull(Employee::where('company_id', $this->company->id)->where('employee_code', '501')->first());
    }
    private function requiredEmployeeCsv(array $overrides = [], array $omit = []): UploadedFile
    {
        $values = array_diff_key(array_replace([
            'كود' => 'R501', 'الاسماء' => 'موظف المتطلبات', 'الرقم القومي' => '29001011234567',
            'الوظيفة' => 'محاسب', 'بداية العقد' => '2026-01-01', 'نهاية العقد' => '2027-12-31', 'الراتب الإجمالي' => '5000',
        ], $overrides), array_flip($omit));
        return UploadedFile::fake()->createWithContent('required.csv', implode(',', array_keys($values))."\n".implode(',', $values)."\n");
    }

    public function test_employee_required_columns_stay_editable_and_commit_is_blocked_with_exact_labels(): void
    {
        foreach (['كود', 'الرقم القومي', 'الوظيفة', 'بداية العقد', 'نهاية العقد', 'الراتب الإجمالي'] as $field) {
            $params = ['file' => $this->requiredEmployeeCsv([], [$field])];
            $preview = $this->postJson('/employees/import/preview', $params)->assertOk();
            $this->assertNotEmpty($preview->json('sheets.0.employee_config.columns'));
            $this->assertNotEmpty($preview->json('mapping_error'));
            $this->postJson('/employees/import', $params + ['review_token' => $preview->json('review_token')])->assertStatus(422);
        }
        $this->assertFalse(Employee::where('employee_code', 'R501')->exists());
    }

    public function test_employee_blank_required_values_are_row_errors_before_defaults(): void
    {
        $preview = $this->postJson('/employees/import/preview', ['file' => $this->requiredEmployeeCsv([
            'كود' => '', 'الرقم القومي' => '', 'الوظيفة' => '', 'بداية العقد' => '', 'نهاية العقد' => '', 'الراتب الإجمالي' => '',
        ])])->assertOk();
        $text = implode(' ', $preview->json('rows.0.errors'));
        foreach (['كود الموظف', 'الرقم القومي', 'الوظيفة', 'بداية العقد', 'نهاية العقد', 'الراتب الإجمالي أو الراتب الصافي'] as $label) $this->assertStringContainsString($label, $text);
        $this->assertSame('error', $preview->json('rows.0.action'));
    }

    public function test_gross_or_net_salary_is_sufficient_but_both_missing_is_not(): void
    {
        foreach ([[], ['الراتب الإجمالي']] as $omit) {
            $overrides = $omit ? ['الراتب الصافي' => '4500'] : [];
            $preview = $this->postJson('/employees/import/preview', ['file' => $this->requiredEmployeeCsv($overrides, $omit)])->assertOk();
            $this->assertNull($preview->json('mapping_error'));
            $this->assertSame(1, $preview->json('summary.create'));
        }
    }

    public function test_attendance_manual_date_and_optional_name_are_signed_and_status_input_is_ignored(): void
    {
        $file = $this->xlsx([['name'=>'الحضور','rows'=>[
            ['code','الحضور','الانصراف','الحالة'], ['104','09:20','17:00','غائب'],
        ]]]);
        $params = ['file'=>$file, 'sheet_kinds'=>json_encode(['الحضور'=>'attendance']), 'sheet_dates'=>json_encode(['الحضور'=>'2026-11-12'])];
        $missing = $this->postJson('/employees/import/preview', array_diff_key($params, ['sheet_dates'=>true]))->assertOk();
        $this->assertStringContainsString('التاريخ', $missing->json('mapping_error'));
        $preview = $this->postJson('/employees/import/preview', $params)->assertOk();
        $this->assertNull($preview->json('mapping_error'));
        $this->assertArrayNotHasKey('status', $preview->json('domain_fields.attendance'));
        $tampered = $params; $tampered['sheet_dates'] = json_encode(['الحضور'=>'2026-11-13']);
        $this->postJson('/employees/import', $tampered + ['review_token'=>$preview->json('review_token')])->assertStatus(422);
        $this->postJson('/employees/import', $params + ['review_token'=>$preview->json('review_token')])->assertOk();
        $attendance = Attendance::firstOrFail();
        $this->assertSame('2026-11-12', $attendance->attendance_date->toDateString());
        $this->assertSame('Late', $attendance->status);
    }

    public function test_penalties_require_values_and_ignore_removed_status_and_notes(): void
    {
        $file = $this->xlsx([['name'=>'الجزاءات','rows'=>[
            ['code','نوع الجزاء','تاريخ القرار','أيام الخصم','حالة القرار','ملاحظات'],
            ['104','خصم','2026-11-12','١','Rejected','must not be imported'],
            ['104','خصم','2026-11-13','','Approved',''],
            ['','خصم','2026-11-14','1','Approved',''],
            ['104','','2026-11-15','1','Approved',''],
            ['104','خصم','','1','Approved',''],
        ]]]);
        $params=['file'=>$file];
        $preview=$this->postJson('/employees/import/preview',$params)->assertOk();
        $this->assertArrayNotHasKey('status',$preview->json('domain_fields.penalties'));
        $this->assertArrayNotHasKey('reason',$preview->json('domain_fields.penalties'));
        $this->postJson('/employees/import',$params+['review_token'=>$preview->json('review_token')])->assertOk();
        $this->assertSame(1, Penalty::count());
        $penalty=Penalty::firstOrFail();
        $this->assertSame('Approved',$penalty->status);
        $this->assertSame('خصم',$penalty->reason);
        $this->assertEquals(1,$penalty->deduction_days);
    }

    public function test_new_name_and_time_aliases_are_added_without_removing_old_ones(): void
    {
        foreach (['اسم الموظف','الاسم','الموظفين','الاسماء','الموظفون','employee name'] as $name) {
            $file=$this->xlsx([['name'=>'الحضور','rows'=>[
                ['code',$name,'التاريخ','c/in','c/out'],['104','عامر سالم جمعه','2026-11-12','09:00','17:00'],
            ]]]);
            $preview=$this->postJson('/employees/import/preview',['file'=>$file])->assertOk();
            $this->assertNull($preview->json('mapping_error'));
            $this->assertSame(['code'=>0,'name'=>1,'date'=>2,'in'=>3,'out'=>4],$preview->json('sheets.0.domain_config.map'));
            $file=$this->xlsx([['name'=>'الجزاءات','rows'=>[
                ['كود',$name,'نوع الجزاء','تاريخ القرار','أيام الخصم'],['104','عامر سالم جمعه','خصم','2026-11-12','1'],
            ]]]);
            $preview=$this->postJson('/employees/import/preview',['file'=>$file])->assertOk();
            $this->assertSame(1,$preview->json('sheets.0.domain_config.map.name'));
        }
        $analyzer=app(\App\Services\Imports\AttendanceSheetAnalyzer::class);
        foreach ([['in','out'],['c/in','c/out'],['الحضور','الانصراف'],['أول بصمة','آخر بصمة']] as [$in,$out]) {
            $map=$analyzer->mapHeaders(['كود','التاريخ',$in,$out]);
            $this->assertSame(2,$map['check_in']); $this->assertSame(3,$map['check_out']);
        }
    }

}
