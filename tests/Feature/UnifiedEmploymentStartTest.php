<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Permission, Role, SubscriptionPlan, User};
use App\Services\{EmployeePeriodMetrics, LeaveBalanceService, PayrollRunService};
use App\Services\Imports\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UnifiedEmploymentStartTest extends TestCase
{
    use DatabaseTransactions;
    use \Tests\CompleteEmployeeImportFixtures;
    private Company $company;
    private int $status;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        $this->company = Company::create(['name'=>'Start Test', 'slug'=>'start-test', 'status'=>'active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug'=>'start-test'], ['name'=>'Test', 'employee_limit'=>10000, 'features'=>[]]);
        CompanySubscription::create(['company_id'=>$this->company->id, 'subscription_plan_id'=>$plan->id, 'status'=>'active', 'starts_at'=>today()]);
        $role = Role::create(['name'=>'company_manager', 'display_name'=>'Manager', 'company_id'=>$this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $user = User::create(['name'=>'Start manager', 'email'=>'start-test@example.test', 'password'=>bcrypt('test-only'), 'role_id'=>$role->id, 'company_id'=>$this->company->id, 'is_active'=>true]);
        $this->actingAs($user);
        $this->status = EmployeeStatus::firstOrCreate(['name'=>'Active'])->id;
        foreach (['payroll_period_start_day'=>'1', 'payroll_period_end_day'=>'31'] as $key=>$value) {
            DB::table('hr_operational_settings')->insert(['company_id'=>$this->company->id, 'setting_key'=>$key, 'setting_value'=>$value, 'label'=>$key, 'created_at'=>now(), 'updated_at'=>now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function employee(array $dates = []): Employee
    {
        return Employee::create($dates + ['company_id'=>$this->company->id, 'employee_code'=>'START-'.uniqid(), 'first_name'=>'Start', 'full_name'=>'Start employee', 'status_id'=>$this->status]);
    }

    public function test_model_synchronizes_canonical_and_legacy_writes_and_direct_assignment(): void
    {
        $e = $this->employee(['contract_start_date'=>'2026-11-15']);
        $this->assertSame('2026-11-15', $e->fresh()->hire_date->toDateString());
        $e->update(['contract_start_date'=>'2026-11-17']);
        $this->assertSame('2026-11-17', $e->fresh()->hire_date->toDateString());
        $e->update(['hire_date'=>'2026-11-10']);
        $this->assertSame('2026-11-10', $e->fresh()->contract_start_date->toDateString());
        $e->contract_start_date = '2026-11-12'; $e->save();
        $this->assertSame('2026-11-12', $e->fresh()->hire_date->toDateString());
    }

    public function test_conflicting_input_is_rejected_before_model_write(): void
    {
        $before = Employee::count();
        try { $this->employee(['hire_date'=>'2026-11-10', 'contract_start_date'=>'2026-11-15']); $this->fail('Expected conflict'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('contract_start_date', $exception->errors()); }
        $this->assertSame($before, Employee::count());
    }

    public function test_web_create_uses_one_date_for_salary_and_service_window_and_attendance(): void
    {
        $this->postJson('/employees', ['full_name'=>'Canonical starter', 'employee_code'=>'CANON', 'contract_start_date'=>'2026-11-15', 'gross_salary'=>12000])->assertSuccessful();
        $e = Employee::where('employee_code','CANON')->firstOrFail();
        $this->assertSame('2026-11-15', $e->hire_date->toDateString());
        $this->assertSame('2026-11-15', $e->currentSalary->effective_from->toDateString());
        $window = app(PayrollRunService::class)->serviceWindow($e, Carbon::parse('2026-11-01'));
        $this->assertSame('2026-11-15', $window['start']->toDateString());
        $days = app(EmployeePeriodMetrics::class)->days($e, Carbon::parse('2026-11-14'), Carbon::parse('2026-11-15'), collect(), collect(), collect());
        $this->assertSame('before_hire',$days[0]['state']);
        $this->assertNotSame('before_hire',$days[1]['state']);
    }

    public function test_update_and_renewal_preserve_original_start_and_leave_tenure(): void
    {
        $e = $this->employee(['contract_start_date'=>'2015-03-10', 'contract_end_date'=>'2026-03-09']);
        $before = app(LeaveBalanceService::class)->entitlement($e, Carbon::parse('2026-11-01'));
        $this->putJson('/employees/'.$e->id, ['full_name'=>$e->full_name, 'contract_end_date'=>'2027-03-09'])->assertSuccessful();
        $e->refresh();
        $this->assertSame('2015-03-10',$e->hire_date->toDateString());
        $this->assertSame('2015-03-10',$e->contract_start_date->toDateString());
        $this->assertSame($before, app(LeaveBalanceService::class)->entitlement($e, Carbon::parse('2026-11-01')));
        $insurance = (new \ReflectionMethod(PayrollRunService::class,'insuranceIsDueForMonth'))->invoke(app(PayrollRunService::class),$e,Carbon::parse('2015-03-01'));
        $this->assertFalse($insurance);
    }

    public function test_legacy_requests_work_but_conflicts_and_invalid_end_are_rejected(): void
    {
        $e = $this->employee(['hire_date'=>'2026-11-01']);
        $this->putJson('/employees/'.$e->id,['full_name'=>$e->full_name,'hire_date'=>'2026-11-10'])->assertSuccessful();
        $this->assertSame('2026-11-10',$e->fresh()->contract_start_date->toDateString());
        $this->putJson('/employees/'.$e->id,['full_name'=>$e->full_name,'hire_date'=>'2026-11-11','contract_start_date'=>'2026-11-12'])->assertUnprocessable();
        $this->assertSame('2026-11-10',$e->fresh()->hire_date->toDateString());
        $this->putJson('/employees/'.$e->id,['full_name'=>$e->full_name,'contract_start_date'=>'2026-11-10','contract_end_date'=>'2026-11-09'])->assertUnprocessable();
    }

    public function test_migration_backfills_only_missing_values_and_does_not_touch_salary(): void
    {
        $e = $this->employee(['hire_date'=>'2024-01-01']);
        $this->postJson('/employees',['full_name'=>'Historical','employee_code'=>'HIST','contract_start_date'=>'2024-01-01','gross_salary'=>8000])->assertSuccessful();
        $historical = Employee::where('employee_code','HIST')->firstOrFail();
        $salaryBefore = $historical->currentSalary->getAttributes();
        DB::table('employees')->where('id',$e->id)->update(['contract_start_date'=>null]);
        DB::table('employees')->where('id',$historical->id)->update(['hire_date'=>null]);
        $migration = require database_path('migrations/2026_11_06_000010_unify_employee_start_dates.php');
        $migration->up();
        $this->assertSame('2024-01-01',$e->fresh()->contract_start_date->toDateString());
        $this->assertSame('2024-01-01',$historical->fresh()->hire_date->toDateString());
        $this->assertSame($salaryBefore,$historical->currentSalary->fresh()->getAttributes());
        $migration->down();
        $this->assertSame('2024-01-01',$e->fresh()->contract_start_date->toDateString());
    }

    public function test_migration_conflict_preflight_stops_before_any_backfill(): void
    {
        $missing = $this->employee(['hire_date'=>'2024-01-01']);
        $conflict = $this->employee(['hire_date'=>'2024-01-01']);
        DB::table('employees')->where('id',$missing->id)->update(['contract_start_date'=>null]);
        DB::table('employees')->where('id',$conflict->id)->update(['contract_start_date'=>'2024-02-01']);
        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('employees:check-start-dates'));
        try { (require database_path('migrations/2026_11_06_000010_unify_employee_start_dates.php'))->up(); $this->fail('Expected conflict'); }
        catch (\RuntimeException $exception) { $this->assertStringContainsString('consolidation stopped',$exception->getMessage()); }
        $this->assertNull($missing->fresh()->contract_start_date);
        $this->assertSame('2024-02-01',$conflict->fresh()->contract_start_date->toDateString());
    }

    public function test_screens_have_one_editable_date_and_no_hire_date_field(): void
    {
        $e = $this->employee(['contract_start_date'=>'2024-01-01']);
        foreach (['/employees','/employees/'.$e->id,'/dashboard'] as $url) {
            $response = $this->get($url)->assertSuccessful();
            $response->assertDontSee('name="hire_date"',false);
            if ($url !== '/dashboard') $response->assertSee('name="contract_start_date"',false);
            if ($url==='/employees/'.$e->id) {
                $this->assertSame(1,substr_count($response->getContent(),'name="contract_start_date"'));
                $response->assertDontSee('id="employee_contract_start_date" type="date" name="contract_start_date" class="form-control" value="2024-01-01" readonly',false);
            }
        }
    }

    public function test_new_template_has_one_visible_start_date_and_legacy_column_is_blank(): void
    {
        $path = resource_path('import-templates/employees-import-template.xlsx');
        $rows = app(\App\Services\Imports\SpreadsheetReader::class)->read($path, 'xlsx');
        $this->assertContains('تاريخ بداية العقد', $rows[1]);
        $this->assertNotContains('تاريخ التعيين', $rows[1]);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $column = $xml->xpath('//s:col[@min="18"]')[0];
        $this->assertSame('1', (string) $column['hidden']);
        $zip->close();
    }

    public function test_import_accepts_canonical_only_and_rejects_conflicting_legacy_dates(): void
    {
        $path = tempnam(sys_get_temp_dir(),'start').'.xlsx';
        (new XlsxWriter())->writeSheets($path,$this->completeEmployeeSheets([['name'=>'الموظفين','rows'=>[
            ['الكود','الاسم الكامل','بداية العقد','تاريخ التعيين'],
            ['SC1','Start Canonical','2026-11-15',''],
            ['SC2','Start Conflict','2026-11-15','2026-11-10'],
            ['SC3','Start Legacy','','2026-11-17'],
        ]], ['name'=>'ملخص','rows'=>[['بند','قيمة'],['إجمالي','3']]]]));
        $file=UploadedFile::fake()->createWithContent('start.xlsx',file_get_contents($path)); unlink($path);
        $params=['file'=>$file,'ack_updates'=>'1'];
        $preview=$this->postJson('/employees/import/preview',$params)->assertSuccessful();
        $this->assertTrue($preview->json('sheets.0.eligible'));
        $this->post('/employees/import',$params+['review_token'=>$preview->json('review_token')])->assertSuccessful();
        foreach (['SC1'=>'2026-11-15','SC3'=>'2026-11-17'] as $code=>$date) {
            $e=Employee::where('employee_code',$code)->firstOrFail();
            $this->assertSame($date,$e->hire_date->toDateString());
            $this->assertSame($date,$e->contract_start_date->toDateString());
        }
        $this->assertFalse(Employee::where('employee_code','SC2')->exists());
    }
}
