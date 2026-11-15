<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
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
 * Vertical sheets (field labels down the first column, one person per value
 * column) are rotated into the normal table shape so the mapping review and
 * the import pipeline work on them unchanged. Multi-sheet workbooks keep
 * their narrow two-column profile cards untouched: those stay report cards,
 * never employee tables.
 */
class EmployeeImportTransposedSheetTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'transposed-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'TransCo', 'slug' => 'trans-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'transposed@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wk') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, $sheets);

        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    /** One person per value column; the first column lists the field labels. */
    private function verticalCardRows(array $people): array
    {
        $labels = [
            'كود الموظف' => 'code',
            'الاسم الكامل' => 'name',
            'الرقم القومي' => 'nid',
            'الوظيفة' => 'job',
            'بداية العقد' => 'start',
            'نهاية العقد' => 'end',
            'الراتب الإجمالي' => 'gross',
        ];
        $rows = [];
        foreach ($labels as $label => $key) {
            $row = [$label];
            foreach ($people as $person) {
                $row[] = $person[$key];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function person(string $code, string $name, string $nid): array
    {
        return ['code' => $code, 'name' => $name, 'nid' => $nid, 'job' => 'محاسب', 'start' => '2026-01-01', 'end' => '2027-12-31', 'gross' => '9000'];
    }

    public function test_single_sheet_vertical_employee_card_is_transposed_and_imported(): void
    {
        $sheets = [['name' => 'بيانات', 'rows' => $this->verticalCardRows([$this->person('501', 'خالد محمود', '29001011234567')])]];
        $file = $this->xlsx($sheets);

        $preview = $this->postJson('/employees/import/preview', ['file' => $file]);
        $preview->assertOk();
        $sheet = collect($preview->json('sheets'))->firstWhere('name', 'بيانات');
        $this->assertTrue((bool) ($sheet['transposed'] ?? false), 'the vertical card is read as a transposed table');
        $this->assertSame('employees', $sheet['kind']);
        $headers = collect($sheet['employee_config']['columns'])->pluck('header')->all();
        $this->assertContains('الراتب الإجمالي', $headers, 'each label row becomes a mappable column');
        $this->assertSame(1, (int) $preview->json('summary.create'));

        $commit = $this->postJson('/employees/import', ['file' => $file, 'review_token' => $preview->json('review_token')]);
        $commit->assertOk()->assertJson(['success' => true]);
        $employee = Employee::where('company_id', $this->company->id)->where('employee_code', '501')->first();
        $this->assertNotNull($employee, 'the vertical card commits as one employee');
        $this->assertSame('خالد محمود', $employee->full_name);
    }

    public function test_wide_transposed_table_imports_every_person_column(): void
    {
        $sheets = [['name' => 'بيانات', 'rows' => $this->verticalCardRows([
            $this->person('601', 'سامي علي', '29001012222222'),
            $this->person('602', 'منى حسن', '29001013333333'),
        ])]];
        $file = $this->xlsx($sheets);

        $preview = $this->postJson('/employees/import/preview', ['file' => $file]);
        $preview->assertOk();
        $sheet = collect($preview->json('sheets'))->firstWhere('name', 'بيانات');
        $this->assertTrue((bool) ($sheet['transposed'] ?? false));
        $this->assertSame(2, (int) $preview->json('summary.create'));

        $commit = $this->postJson('/employees/import', ['file' => $file, 'review_token' => $preview->json('review_token')]);
        $commit->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, Employee::where('company_id', $this->company->id)->whereIn('employee_code', ['601', '602'])->count());
    }

    public function test_normal_horizontal_sheet_is_never_transposed(): void
    {
        $sheets = [['name' => 'الموظفين', 'rows' => [
            ['كود الموظف', 'الاسم الكامل', 'الرقم القومي', 'الوظيفة', 'بداية العقد', 'نهاية العقد', 'الراتب الإجمالي'],
            ['701', 'هاني فؤاد', '29001014444444', 'مهندس', '2026-01-01', '2027-12-31', '12000'],
        ]]];

        $preview = $this->postJson('/employees/import/preview', ['file' => $this->xlsx($sheets)]);
        $preview->assertOk();
        $sheet = collect($preview->json('sheets'))->firstWhere('name', 'الموظفين');
        $this->assertFalse((bool) ($sheet['transposed'] ?? false), 'a normal table keeps its shape');
        $this->assertSame('employees', $sheet['kind']);
        $this->assertSame(1, (int) $preview->json('summary.create'));
    }

    public function test_vertical_card_with_loose_payroll_labels_still_transposes(): void
    {
        // The exact labels from a real user file: only «اسم الموظف» is a
        // verbatim alias; «الراتب الشهري الصافي» matches by word meaning and
        // the period labels stay for manual mapping.
        $sheets = [['name' => 'Sheet1', 'rows' => [
            ['اسم الموظف', 'عامر سالم جمعه'],
            ['الراتب الشهري الصافي', '2026-11-11'],
            ['بداية الفترة', '2026-10-12'],
            ['نهاية الفترة', '20000'],
        ]]];

        $preview = $this->postJson('/employees/import/preview', ['file' => $this->xlsx($sheets)]);
        $preview->assertOk();
        $sheet = collect($preview->json('sheets'))->firstWhere('name', 'Sheet1');
        $this->assertTrue((bool) ($sheet['transposed'] ?? false), 'loose payroll labels down a column still mean a transposed sheet');
        $this->assertSame('employees', $sheet['kind']);
        $columns = collect($sheet['employee_config']['columns']);
        $this->assertSame(['اسم الموظف', 'الراتب الشهري الصافي', 'بداية الفترة', 'نهاية الفترة'], $columns->pluck('header')->all());
        $this->assertSame('full_name', $columns->firstWhere('header', 'اسم الموظف')['field']);
        $this->assertSame('net_salary', $columns->firstWhere('header', 'الراتب الشهري الصافي')['field']);
        $this->assertNull($columns->firstWhere('header', 'بداية الفترة')['field'], 'unmatched labels stay for the user to map');
    }

    public function test_multi_sheet_workbook_keeps_narrow_profile_cards_untouched(): void
    {
        $sheets = [
            ['name' => 'الموظفين', 'rows' => [
                ['كود الموظف', 'الاسم الكامل', 'الرقم القومي', 'الوظيفة', 'بداية العقد', 'نهاية العقد', 'الراتب الإجمالي'],
                ['801', 'هاني فؤاد', '29001015555555', 'مهندس', '2026-01-01', '2027-12-31', '12000'],
            ]],
            ['name' => 'بيانات الموظف', 'rows' => [
                ['بيانات الموظف'],
                ['اسم الموظف', 'عامر سالم جمعه'],
                ['كود الموظف', '104'],
                ['تاريخ التعيين', '2024-01-01'],
                ['القسم', 'التشغيل'],
            ]],
        ];

        $preview = $this->postJson('/employees/import/preview', ['file' => $this->xlsx($sheets)]);
        $preview->assertOk();
        $sheet = collect($preview->json('sheets'))->firstWhere('name', 'بيانات الموظف');
        $this->assertFalse((bool) ($sheet['transposed'] ?? false), 'a two-column profile card in a multi-sheet workbook is not rotated');
        $this->assertContains($sheet['kind'], ['unknown', 'auxiliary'], 'profile cards are still never employee tables');
        $this->assertSame(1, (int) $preview->json('summary.create'), 'only the real employee table imports people');
    }
}
