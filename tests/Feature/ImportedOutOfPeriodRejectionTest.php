<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\LeaveType;
use App\Models\Penalty;
use App\Models\PenaltyType;
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

/** Excel rows dated before the current payroll period never save. */
class ImportedOutOfPeriodRejectionTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\CompleteEmployeeImportFixtures;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 12:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'period-import-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'PeriodCo', 'slug' => 'period-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'period-import@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        PenaltyType::firstOrCreate(['name' => 'خصم', 'company_id' => $this->company->id], ['default_deduction_days' => 1]);
        LeaveType::firstOrCreate(['name' => 'Annual', 'company_id' => $this->company->id], ['is_active' => true]);
        RequestType::firstOrCreate(['code' => 'permission', 'company_id' => $this->company->id], ['display_name' => 'إذن', 'source_key' => 'permission', 'is_active' => true]);
        Employee::create(['employee_code' => '104', 'first_name' => 'عامر', 'full_name' => 'عامر سالم جمعه', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2024-01-01']);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function employee(): Employee
    {
        return Employee::where('employee_code', '104')->where('company_id', $this->company->id)->firstOrFail();
    }

    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'period') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, $this->completeEmployeeSheets($sheets));
        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    private function preview(UploadedFile $file): array
    {
        return $this->postJson(route('employees.import.preview'), ['file' => $file])->assertOk()->json('domains');
    }

    public function test_old_penalty_is_rejected_but_current_one_imports(): void
    {
        $domains = $this->preview($this->xlsx([['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ القرار', 'نوع الجزاء', 'أيام الخصم'],
            ['104', 'عامر سالم جمعه', '2026-10-31', 'خصم', '1'],
            ['104', 'عامر سالم جمعه', '2026-11-01', 'خصم', '1'],
        ]]]));
        $this->assertSame(1, $domains['penalties']['imported']);
        $this->assertSame(1, $domains['penalties']['skipped']);
        $this->assertStringContainsString('فترة المرتب الحالية', $domains['penalties']['errors'][0]);
    }

    public function test_leave_crossing_period_start_is_rejected_whole(): void
    {
        $domains = $this->preview($this->xlsx([['name' => 'الإجازات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'نوع الإجازة', 'من تاريخ', 'إلى تاريخ'],
            ['104', 'عامر سالم جمعه', 'Annual', '2026-10-31', '2026-11-02'],
        ]]]));
        $this->assertSame(0, $domains['leaves']['imported']);
        $this->assertSame(1, $domains['leaves']['skipped']);
        $this->assertStringContainsString('فترة المرتب الحالية', $domains['leaves']['errors'][0]);
    }

    public function test_old_request_is_rejected(): void
    {
        $domains = $this->preview($this->xlsx([['name' => 'الطلبات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ الطلب', 'نوع الطلب', 'من الساعة', 'إلى الساعة'],
            ['104', 'عامر سالم جمعه', '2026-10-31', 'إذن', '09:00', '10:00'],
        ]]]));
        $this->assertSame(0, $domains['requests']['imported']);
        $this->assertSame(1, $domains['requests']['skipped']);
        $this->assertStringContainsString('فترة المرتب الحالية', $domains['requests']['errors'][0]);
    }

    public function test_commit_never_saves_the_old_rows(): void
    {
        $file = $this->xlsx([['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ القرار', 'نوع الجزاء', 'أيام الخصم'],
            ['104', 'عامر سالم جمعه', '2026-10-31', 'خصم', '1'],
            ['104', 'عامر سالم جمعه', '2026-11-01', 'خصم', '1'],
        ]]]);
        $preview = $this->postJson(route('employees.import.preview'), ['file' => $file])->assertOk()->json();
        $this->postJson(route('employees.import.store'), [
            'file' => $file,
            'review_token' => $preview['review_token'],
            'sheet_kinds' => json_encode(['الجزاءات' => 'penalties']),
        ])->assertOk()->assertJsonPath('distributed_penalty_count', 1);
        $this->assertSame(1, Penalty::whereDate('penalty_date', '2026-11-01')->count());
        $this->assertSame(0, Penalty::whereDate('penalty_date', '2026-10-31')->count());
    }

    public function test_old_attendance_is_rejected_but_current_one_imports(): void
    {
        $domains = $this->preview($this->xlsx([['name' => 'الحضور والانصراف', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
            ['104', 'عامر سالم جمعه', '2026-10-31', '09:00', '17:00'],
            ['104', 'عامر سالم جمعه', '2026-11-01', '09:00', '17:00'],
        ]]]));
        $this->assertSame(1, $domains['attendance']['imported']);
        $this->assertSame(1, $domains['attendance']['skipped']);
        $this->assertStringContainsString('فترة المرتب الحالية', $domains['attendance']['errors'][0]);
        $this->assertSame('الحضور والانصراف', $domains['attendance']['failures'][0]['sheet']);
        $this->assertSame(2, $domains['attendance']['failures'][0]['line']);
        $this->assertSame(1, $domains['attendance']['out_of_period_count']);
        $this->assertSame('01/11', $domains['attendance']['period_start']);
        $this->assertSame([], $domains['attendance']['row_failures']);
    }

    public function test_commit_never_saves_the_old_attendance_rows(): void
    {
        $file = $this->xlsx([['name' => 'الحضور والانصراف', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
            ['104', 'عامر سالم جمعه', '2026-10-31', '09:00', '17:00'],
            ['104', 'عامر سالم جمعه', '2026-11-01', '09:00', '17:00'],
        ]]]);
        $preview = $this->postJson(route('employees.import.preview'), ['file' => $file])->assertOk()->json();
        $this->postJson(route('employees.import.store'), [
            'file' => $file,
            'review_token' => $preview['review_token'],
            'sheet_kinds' => json_encode(['الحضور والانصراف' => 'attendance']),
        ])->assertOk()->assertJsonPath('distributed_attendance_count', 1);
        $this->assertSame(1, \App\Models\Attendance::whereDate('attendance_date', '2026-11-01')->count());
        $this->assertSame(0, \App\Models\Attendance::whereDate('attendance_date', '2026-10-31')->count());
    }

    public function test_custom_26_to_25_cycle_uses_company_period(): void
    {
        DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', 'payroll_period_start_day')->update(['setting_value' => '26']);
        DB::table('hr_operational_settings')->where('company_id', $this->company->id)->where('setting_key', 'payroll_period_end_day')->update(['setting_value' => '25']);
        $domains = $this->preview($this->xlsx([['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ القرار', 'نوع الجزاء', 'أيام الخصم'],
            ['104', 'عامر سالم جمعه', '2026-10-25', 'خصم', '1'],
            ['104', 'عامر سالم جمعه', '2026-10-26', 'خصم', '1'],
        ]]]));
        $this->assertSame(1, $domains['penalties']['imported']);
        $this->assertSame(1, $domains['penalties']['skipped']);
    }
    public function test_cleanup_lists_and_deletes_only_tagged_old_records(): void
    {
        $type = PenaltyType::where('name', 'خصم')->firstOrFail();
        $requestType = RequestType::where('code', 'permission')->firstOrFail();
        $leaveType = LeaveType::where('name', 'Annual')->firstOrFail();
        $batch = \App\Models\EmployeeImportBatch::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'file_name' => 'old.xlsx']);
        $importedPenalty = Penalty::create(['import_batch_id' => $batch->id, 'penalty_type_id' => $type->id, 'employee_id' => $this->employee()->id, 'penalty_date' => '2026-10-20', 'title' => 'Imported old', 'reason' => 'old', 'status' => 'Approved']);
        $manualPenalty = Penalty::create(['penalty_type_id' => $type->id, 'employee_id' => $this->employee()->id, 'penalty_date' => '2026-10-20', 'title' => 'Manual old', 'reason' => 'old', 'status' => 'Approved']);
        $importedLeave = \App\Models\Leave::create(['import_batch_id' => $batch->id, 'employee_id' => $this->employee()->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-21', 'days' => 2, 'status' => 'Approved']);
        $importedRequest = \App\Models\HrRequest::create(['import_batch_id' => $batch->id, 'employee_id' => $this->employee()->id, 'request_type' => 'permission', 'request_type_id' => $requestType->id, 'title' => 'إذن', 'payload' => ['request_date' => '2026-10-20'], 'status' => 'Approved', 'created_by' => $this->user->id]);
        $currentPenalty = Penalty::create(['import_batch_id' => $batch->id, 'penalty_type_id' => $type->id, 'employee_id' => $this->employee()->id, 'penalty_date' => '2026-11-01', 'title' => 'Imported current', 'reason' => 'current', 'status' => 'Approved']);

        $page = $this->get(route('employees.import-outofperiod.index'))->assertOk();
        $page->assertSee('name="ids[penalties][]" value="'.$importedPenalty->id.'"', false)
            ->assertSee('name="ids[leaves][]" value="'.$importedLeave->id.'"', false)
            ->assertSee('name="ids[requests][]" value="'.$importedRequest->id.'"', false)
            ->assertDontSee('value="'.$manualPenalty->id.'"', false)
            ->assertDontSee('value="'.$currentPenalty->id.'"', false);

        $this->delete(route('employees.import-outofperiod.destroy'), ['ids' => ['penalties' => [$importedPenalty->id, $manualPenalty->id, $currentPenalty->id], 'leaves' => [$importedLeave->id], 'requests' => [$importedRequest->id]]])->assertRedirect();
        $this->assertDatabaseMissing('penalties', ['id' => $importedPenalty->id]);
        $this->assertDatabaseMissing('leaves', ['id' => $importedLeave->id]);
        $this->assertDatabaseMissing('hr_requests', ['id' => $importedRequest->id]);
        $this->assertDatabaseHas('penalties', ['id' => $manualPenalty->id]);
        $this->assertDatabaseHas('penalties', ['id' => $currentPenalty->id]);
    }

    public function test_commit_tags_created_domain_records_and_cleanup_page_them(): void
    {
        $file = $this->xlsx([['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ القرار', 'نوع الجزاء', 'أيام الخصم'],
            ['104', 'عامر سالم جمعه', '2026-11-01', 'خصم', '1'],
        ]]]);
        $preview = $this->postJson(route('employees.import.preview'), ['file' => $file])->assertOk()->json();
        $response = $this->postJson(route('employees.import.store'), [
            'file' => $file,
            'review_token' => $preview['review_token'],
            'sheet_kinds' => json_encode(['الجزاءات' => 'penalties']),
        ]);
        $response->assertOk();
        $batch = \App\Models\EmployeeImportBatch::latest('id')->first();
        $this->assertNotNull($batch);
        $penalty = Penalty::whereDate('penalty_date', '2026-11-01')->firstOrFail();
        $this->assertNotNull($penalty->import_batch_id, 'distributed='.json_encode($response->json('distributed'), JSON_UNESCAPED_UNICODE).' schema='.(int) \Illuminate\Support\Facades\Schema::hasColumn('penalties','import_batch_id').' company='.$penalty->company_id);
        $this->get(route('employees.import-outofperiod.index'))->assertOk()->assertDontSee('value="'.$penalty->id.'"', false);
    }

    public function test_preview_names_incomplete_sheets_and_missing_columns(): void
    {
        $response = $this->postJson(route('employees.import.preview'), ['file' => $this->xlsx([['name' => 'الجزاءات', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'تاريخ القرار'],
            ['104', 'عامر سالم جمعه', '2026-11-01'],
        ]]])])->assertOk();
        $response->assertJsonPath('incomplete_sheets.0.name', 'الجزاءات')
            ->assertJsonPath('incomplete_sheets.0.kind', 'penalties');
        $this->assertContains('نوع الجزاء', $response->json('incomplete_sheets.0.missing'));
        $this->assertContains('أيام الخصم', $response->json('incomplete_sheets.0.missing'));
        $this->assertStringContainsString('الجزاءات', $response->json('mapping_error'));
    }

    public function test_sheets_step_groups_each_sheet_as_a_card(): void
    {
        $source = file_get_contents(resource_path('views/employees/partials/import-modal.blade.php'));
        $this->assertStringContainsString('incomplete_sheets', $source);
        $this->assertStringContainsString('sheet-card-missing', $source);
        $this->assertStringContainsString('sheet-card-ignored', $source);
        $this->assertStringContainsString('sheet-card-head', $source);
        $this->assertStringContainsString('sheet-card-body', $source);
        $this->assertStringContainsString('sheet-missing-badge', $source);
        $this->assertStringContainsString('بيانات ناقصة في:', $source);
        $this->assertStringContainsString('mapping-field-card', $source);
        $this->assertStringContainsString('mappingNeedsAttention', $source);
        $this->assertStringContainsString('is-ready', $source);
        $this->assertStringContainsString("classList.add('open')", $source);
        $this->assertStringContainsString('grid-template-rows: 0fr', $source);
        // Ignored sheets must collapse closed, not stay expanded.
        $this->assertStringContainsString("(state.sheetKinds[sh.name]||sh.kind)==='ignore') return;", $source);
        // The old red row highlight and the duplicate yellow banner are gone.
        $this->assertStringNotContainsString('sheet-summary-missing', $source);
        $this->assertStringNotContainsString('حقول مطلوبة غير مربوطة', $source);
    }

}
