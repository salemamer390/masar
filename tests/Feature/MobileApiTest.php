<?php
namespace Tests\Feature;
use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Role, SubscriptionPlan, User, Attendance, PayrollRun, PayrollRecord, Permission};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class MobileApiTest extends TestCase {
 use DatabaseTransactions;
    private Company $companyA;
    private Company $companyB;
    private User $userA;
    private User $userB;
    private Employee $employeeA;
    private Employee $employeeB;
    private int $statusId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $this->statusId = $status->id;
        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );

        $this->companyA = Company::create(['name' => 'Company A', 'slug' => 'company-a', 'status' => 'active']);
        $this->companyB = Company::create(['name' => 'Company B', 'slug' => 'company-b', 'status' => 'active']);
        foreach ([$this->companyA, $this->companyB] as $company) {
            CompanySubscription::create([
                'company_id' => $company->id,
                'subscription_plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => today(),
            ]);
        }

        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager A', 'company_id' => $this->companyA->id]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager B', 'company_id' => $this->companyB->id]);

        $this->userA = User::create(['name' => 'User A', 'email' => 'a@example.test', 'password' => bcrypt('secret'), 'role_id' => $roleA->id, 'company_id' => $this->companyA->id, 'is_active' => true]);
        $this->userB = User::create(['name' => 'User B', 'email' => 'b@example.test', 'password' => bcrypt('secret'), 'role_id' => $roleB->id, 'company_id' => $this->companyB->id, 'is_active' => true]);

        $this->employeeA = Employee::create(['employee_code' => '9001', 'first_name' => 'EmpA', 'full_name' => 'Employee Alpha A', 'company_id' => $this->companyA->id, 'status_id' => $this->statusId]);
        $this->employeeB = Employee::create(['employee_code' => '9002', 'first_name' => 'EmpB', 'full_name' => 'Employee Bravo B', 'company_id' => $this->companyB->id, 'status_id' => $this->statusId]);
    }


 public function test_no_mobile_signup_route_is_exposed(): void {
  $this->postJson('/mobile/v1/register',[])->assertNotFound();
  $this->postJson('/mobile/v1/signup',[])->assertNotFound();
 }
 public function test_session_and_real_login(): void {
  $this->getJson('/mobile/v1/session')->assertOk()->assertJsonPath('user',null)->assertJsonStructure(['csrf_token','permissions']);
  $this->postJson('/mobile/v1/login',['email'=>'a@example.test','password'=>'wrong'])->assertUnprocessable();
  $this->postJson('/mobile/v1/login',['email'=>'a@example.test','password'=>'secret'])->assertOk()->assertJsonPath('user.id',$this->userA->id);
  $this->getJson('/mobile/v1/employees')->assertOk()->assertSee('Employee Alpha A')->assertDontSee('Employee Bravo B');
  $this->postJson('/mobile/v1/logout')->assertOk();
  $this->getJson('/mobile/v1/employees')->assertUnauthorized();
 }
 public function test_csrf_stays_enabled_outside_the_test_bypass(): void {
  $this->app['env']='production';
  $this->postJson('/mobile/v1/login',['email'=>'a@example.test','password'=>'secret'])->assertStatus(419);
 }
 public function test_auth_and_company_isolation(): void {
  $this->getJson('/mobile/v1/employees')->assertUnauthorized();
  $this->actingAs($this->userA)->getJson('/mobile/v1/employees')->assertOk()->assertJsonPath('total',1)->assertDontSee('Employee Bravo B');
  $this->getJson('/mobile/v1/employees/'.$this->employeeB->id)->assertNotFound();
  $this->getJson('/mobile/v1/employees/'.$this->employeeA->id)->assertOk()->assertJsonPath('data.id',$this->employeeA->id);
  $this->putJson('/mobile/v1/employees/'.$this->employeeB->id,['full_name'=>'Hijacked'])->assertNotFound();
  $this->postJson('/mobile/v1/attendance/clock-in',['employee_id'=>$this->employeeB->id])->assertNotFound();
 }
 public function test_inactive_account_and_subscription_are_enforced(): void {
  $this->userA->update(['is_active'=>false]);
  $this->postJson('/mobile/v1/login',['email'=>'a@example.test','password'=>'secret'])->assertForbidden();
  $this->actingAs($this->userA)->getJson('/mobile/v1/employees')->assertForbidden();
  $this->userA->update(['is_active'=>true]);$this->companyA->update(['status'=>'inactive']);
  $this->postJson('/mobile/v1/login',['email'=>'a@example.test','password'=>'secret'])->assertForbidden();
 }
 public function test_read_only_role_cannot_write(): void {
  $role=Role::create(['name'=>'viewer','display_name'=>'Viewer','company_id'=>$this->companyA->id]);
  $role->permissions()->sync(Permission::where('name','employees.view')->pluck('id'));
  $this->userA->update(['role_id'=>$role->id]);$this->userA->unsetRelation('role');
  $this->actingAs($this->userA)->getJson('/mobile/v1/employees')->assertOk();
  $this->postJson('/mobile/v1/employees',['full_name'=>'Blocked'])->assertForbidden();
  $this->getJson('/mobile/v1/payroll')->assertForbidden();
 }
 public function test_reads_use_existing_rules_and_do_not_create_payroll(): void {
  $this->actingAs($this->userA);
  $before=PayrollRun::count();$records=PayrollRecord::count();
  foreach(['dashboard','attendance','leaves','penalties','documents','requests','payroll','notifications','reports','summary'] as $path) {
   $this->getJson('/mobile/v1/'.$path)->assertOk();
  }
  $this->assertSame($before,PayrollRun::count());$this->assertSame($records,PayrollRecord::count());
  $this->getJson('/mobile/v1/payroll?month=bogus')->assertUnprocessable();
  foreach(['employees','leaves','penalties','documents','requests'] as $module) $this->getJson('/mobile/v1/lookups?module='.$module)->assertOk();
 }
 public function test_server_clock_does_not_fabricate_or_overwrite_punches(): void {
  $this->actingAs($this->userA);
  $this->postJson('/mobile/v1/attendance/clock-out',['employee_id'=>$this->employeeA->id])->assertUnprocessable();
  $this->postJson('/mobile/v1/attendance/clock-in',['employee_id'=>$this->employeeA->id])->assertOk();
  $r=Attendance::where('employee_id',$this->employeeA->id)->firstOrFail();$this->assertNull($r->check_out);
  $this->postJson('/mobile/v1/attendance/clock-in',['employee_id'=>$this->employeeA->id])->assertUnprocessable();
  $this->assertSame($r->check_in,$r->fresh()->check_in);
 }

 public function test_employee_create_update_and_document_upload_use_real_controllers(): void {
  $this->actingAs($this->userA);
  $created=$this->postJson('/mobile/v1/employees',['full_name'=>'Mobile Hire','salary_source'=>'gross','salary_value'=>6000,'contract_start_date'=>'2026-01-01'])->assertOk();
  $id=$created->json('employee.id');
  $this->assertNotNull($id);$employee=Employee::findOrFail($id);
  $this->assertEquals($this->companyA->id,$employee->company_id);$this->assertSame('2026-01-01',$employee->hire_date->toDateString());
  $this->putJson('/mobile/v1/employees/'.$id,['full_name'=>'Mobile Updated','phone'=>'01000000000','contract_start_date'=>'2025-12-01'])->assertOk();
  $this->assertSame('Mobile Updated',$employee->fresh()->full_name);
  $this->assertSame('2025-12-01',$employee->fresh()->contract_start_date->toDateString());
  $this->assertSame('2025-12-01',$employee->fresh()->hire_date->toDateString());
  Storage::fake('public');
  $response=$this->postJson('/mobile/v1/employees/'.$id.'/documents',['document_name'=>'Test PDF','document_file'=>\Illuminate\Http\UploadedFile::fake()->create('test.pdf',10,'application/pdf')])->assertOk();
  $doc=\App\Models\Document::findOrFail($response->json('document.id'));
  $this->get('/mobile/v1/documents/'.$doc->id.'/file')->assertOk();
  $this->actingAs($this->userB)->get('/mobile/v1/documents/'.$doc->id.'/file')->assertNotFound();
 }
 public function test_administrative_leave_and_penalty_forms_are_effective_and_audited(): void {
  $this->actingAs($this->userA);$this->travelTo(\Carbon\Carbon::parse('2026-11-08 12:00:00'));$this->employeeA->update(['hire_date'=>'2025-01-01','manual_annual_balance'=>21]);
  $type=\App\Models\LeaveType::create(['name'=>'Mobile annual','policy_code'=>'annual','is_paid'=>true,'deducts_annual_balance'=>true,'is_active'=>true,'company_id'=>$this->companyA->id]);
  $leave=$this->postJson('/mobile/v1/leaves',['employee_id'=>$this->employeeA->id,'leave_type_id'=>$type->id,'start_date'=>today()->toDateString(),'end_date'=>today()->toDateString(),'reason'=>'Mobile test'])->assertOk();
  $this->assertSame('Approved',$leave->json('leave.status'));
  $this->postJson('/mobile/v1/leaves',['employee_id'=>$this->employeeA->id,'leave_type_id'=>$type->id,'start_date'=>today()->toDateString(),'end_date'=>today()->toDateString()])->assertUnprocessable();
  $id=$leave->json('leave.id');
  $this->patchJson('/mobile/v1/leaves/'.$id.'/reject',['rejection_reason'=>'Test reversal'])->assertOk();
  $this->assertSame('Rejected',\App\Models\Leave::findOrFail($id)->status);
  $penaltyType=\App\Models\PenaltyType::create(['name'=>'Mobile penalty','is_active'=>true,'company_id'=>$this->companyA->id]);
  $penalty=$this->postJson('/mobile/v1/penalties',['employee_id'=>$this->employeeA->id,'penalty_type_id'=>$penaltyType->id,'penalty_date'=>today()->toDateString(),'reason'=>'Mobile test','amount'=>10])->assertOk();
  $this->assertDatabaseHas('penalties',['employee_id'=>$this->employeeA->id,'amount'=>10,'issued_by'=>$this->userA->id]);
 }
 public function test_request_subtypes_validate_dates_confirmations_and_company_scope(): void {
  $this->actingAs($this->userA);$this->employeeA->update(['hire_date'=>'2025-01-01','gross_salary'=>6000]);
  foreach(['permission','mission','overtime','advance','bonus','incentive','commission','profit_share','other'] as $source){
   $type=\App\Models\RequestType::create(['code'=>'mobile-'.$source,'display_name'=>$source,'source_key'=>$source,'is_active'=>true,'company_id'=>$this->companyA->id]);
   $body=['employee_id'=>$this->employeeA->id,'request_type_id'=>$type->id,'title'=>'Mobile '.$source,'details'=>'Test only','request_date'=>today()->toDateString(),'overtime_request_date'=>today()->toDateString(),'advance_request_date'=>today()->toDateString(),'financial_request_date'=>today()->toDateString(),'other_request_date'=>today()->toDateString(),'from_time'=>'17:00','to_time'=>'18:00','overtime_period'=>'day','advance_amount'=>100,'financial_amount'=>100];
   $this->postJson('/mobile/v1/requests',$body)->assertOk()->assertJsonPath('request.status','Approved');
   $this->postJson('/mobile/v1/requests',[...$body,'employee_id'=>$this->employeeB->id])->assertNotFound();
  }
 }
 public function test_payroll_lifecycle_returns_json_and_blocks_locked_inputs_and_cross_company(): void {
  $this->actingAs($this->userA);
  $run=PayrollRun::create(['company_id'=>$this->companyA->id,'payroll_month'=>today()->startOfMonth()->toDateString(),'status'=>'Draft']);
  $record=PayrollRecord::create(['payroll_run_id'=>$run->id,'employee_id'=>$this->employeeA->id,'payroll_month'=>$run->payroll_month,'status'=>'Draft','net_salary'=>1000]);
  $this->postJson('/mobile/v1/payroll/records/'.$record->id.'/pay')->assertUnprocessable()->assertJsonStructure(['errors']);
  $this->postJson('/mobile/v1/payroll/records/'.$record->id.'/approve')->assertOk()->assertJsonPath('success',true);
  $this->postJson('/mobile/v1/payroll/records/'.$record->id.'/pay')->assertOk();
  $this->assertNotNull($record->fresh()->paid_at);
  $this->putJson('/mobile/v1/payroll/records/'.$record->id.'/inputs',['bonus'=>50])->assertUnprocessable();
  $this->actingAs($this->userB)->postJson('/mobile/v1/payroll/records/'.$record->id.'/approve')->assertNotFound();
  $this->postJson('/mobile/v1/payroll/runs/'.$run->id.'/lock')->assertNotFound();
 }

 public function test_mobile_explicit_generation_reconciliation_exports_and_run_lifecycle(): void {
  $this->actingAs($this->userA);$this->travelTo(\Carbon\Carbon::parse('2026-11-08 12:00:00'));
  $this->employeeA->update(['hire_date'=>'2025-01-01','shift_start'=>'09:00','shift_end'=>'17:00']);
  \App\Models\Salary::create(['employee_id'=>$this->employeeA->id,'effective_from'=>'2025-01-01','salary_type'=>'gross','salary_input'=>6000,'gross_salary'=>6000,'basic_salary'=>6000,'allowances'=>0,'net_salary'=>5100,'insurance_wage'=>6000,'applied_insurance_wage'=>6000,'employee_insurance'=>300,'employer_insurance'=>600,'income_tax'=>0,'martyrs_fund'=>0,'status'=>'Active']);
  $start=$this->postJson('/mobile/v1/payroll/generate/start',['month'=>'2026-10-01'])->assertOk();
  $id=$start->json('run_id');
  $this->postJson('/mobile/v1/payroll/generate/batch',['month'=>'2026-10-01','offset'=>0,'batch_size'=>25])->assertOk()->assertJsonPath('complete',true);
  $run=PayrollRun::findOrFail($id);$this->assertSame('Draft',$run->status);
  $record=$run->records()->firstOrFail();
  $this->putJson('/mobile/v1/payroll/records/'.$record->id.'/inputs',['bonus'=>50])->assertOk();
  $this->assertEquals(50,$record->fresh()->bonus);
  $this->getJson('/mobile/v1/payroll?month=2026-10')->assertOk()->assertJsonPath('run.id',$id)->assertJsonPath('reconciliation.records',1);
  $this->get('/mobile/v1/payroll/export?month=2026-10-01&format=xlsx')->assertOk()->assertHeader('Content-Type','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  $this->get('/mobile/v1/reports/export/employees')->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
  $this->postJson('/mobile/v1/payroll/runs/'.$id.'/approve')->assertOk()->assertJsonPath('success',true);
  $this->assertSame('Approved',$run->fresh()->status);
  $this->postJson('/mobile/v1/payroll/runs/'.$id.'/lock')->assertOk();
  $this->assertSame('Locked',$run->fresh()->status);
  $this->putJson('/mobile/v1/payroll/records/'.$record->id.'/inputs',['bonus'=>100])->assertUnprocessable();
  $this->postJson('/mobile/v1/payroll/generate/start',['month'=>'2026-10-01'])->assertUnprocessable();
  $this->deleteJson('/mobile/v1/payroll/runs/'.$id)->assertOk();
  $this->assertNull($run->fresh()); // Explicit payroll.delete preserves the existing owner-authorized delete rule, even for locked runs.
 }
}
