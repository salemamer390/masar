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
 * Import review rules: new penalty types register, in-file commissions do not warn as duplicates,
 * advance sheets expose their basic fields.
 */
class ImportSpecRoundTest extends TestCase
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

    public function test_unknown_penalty_type_is_registered_not_rejected(): void
    {
        $file = $this->xlsx([['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'نوع الجزاء', 'تاريخ القرار', 'أيام الخصم'],
            ['104', 'نوع جديد خاص بالشركة', '2026-11-12', '1'],
        ]]]);
        $preview = $this->postJson('/employees/import/preview', ['file' => $file])->assertOk();
        $this->assertSame(1, $preview->json('domains.penalties.imported'));
        $this->assertSame(0, $preview->json('domains.penalties.skipped'));
        $this->assertStringContainsString('نوع جزاء جديد', implode(' ', $preview->json('domains.penalties.warnings')));
        $this->assertNotNull(PenaltyType::where('name', 'خصم')->first(), 'existing types stay');
    }

    public function test_several_commissions_in_the_file_do_not_warn_as_existing_duplicates(): void
    {
        \App\Models\RequestType::create(['code' => 'commission', 'display_name' => 'عمولة', 'source_key' => 'commission', 'is_active' => true, 'company_id' => $this->company->id]);
        $file = $this->xlsx([['name' => 'العمولات', 'rows' => [
            ['كود الموظف', 'التاريخ', 'قيمة العمولة'],
            ['104', '2026-11-03', '100'],
            ['104', '2026-11-11', '200'],
            ['104', '2026-11-20', '300'],
        ]]]);
        $preview = $this->postJson('/employees/import/preview', ['file' => $file])->assertOk();
        $this->assertSame(3, $preview->json('domains.requests.imported'));
        $this->assertStringNotContainsString('قائم', implode(' ', $preview->json('domains.requests.warnings') ?? []));
    }

    public function test_advance_sheet_exposes_its_basic_fields_as_required(): void
    {
        $file = $this->xlsx([['name' => 'السلف', 'rows' => [
            ['كود الموظف', 'تاريخ السلفة', 'قيمة السلفة'],
            ['104', '2026-11-03', '1000'],
        ]]]);
        $preview = $this->postJson('/employees/import/preview', ['file' => $file])->assertOk();
        $this->assertSame(['code' => 'كود الموظف', 'name' => 'اسم الموظف', 'date' => 'تاريخ السلفة', 'amount' => 'قيمة السلفة', 'details' => 'التفاصيل'], $preview->json('domain_fields.advances'));
        $this->assertSame(['code', 'date', 'amount'], $preview->json('required_domain_fields.advances'));
        $this->assertSame(['code', 'date'], $preview->json('required_domain_fields.requests'));
    }

    public function test_discipline_page_lists_attendance_policy_penalties(): void
    {
        $emp = Employee::where('employee_code', '104')->first();
        \App\Models\Attendance::create(['employee_id' => $emp->id, 'attendance_date' => '2026-11-10', 'check_in' => '09:50:00', 'check_out' => '17:00:00', 'status' => 'Late', 'late_minutes' => 50, 'early_leave_minutes' => 0]);
        app(\App\Services\AttendancePenaltyPolicyService::class)->save($this->company->id, true, '2026-01-01', [
            ['kind' => 'late', 'from_minutes' => 31, 'to_minutes' => null, 'occurrence_from' => 1, 'occurrence_to' => null, 'deduction_days' => 1],
        ]);
        $this->get('/discipline?from_date=2026-11-01&to_date=2026-11-30')->assertOk()->assertSee('جزاءات التأخير والحضور والانصراف');
    }
}
