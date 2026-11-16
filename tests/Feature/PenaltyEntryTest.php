<?php

namespace Tests\Feature;

use App\Http\Controllers\JobDataController;
use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, PayrollRecord, Penalty, PenaltyType, Permission, Role, Salary, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PenaltyEntryTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'Africa/Cairo'));
        $company = Company::create(['name'=>'Penalty Entry', 'slug'=>'penalty-entry', 'status'=>'active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug'=>'penalty-entry-plan'], ['name'=>'Test', 'employee_limit'=>10000, 'features'=>[]]);
        CompanySubscription::create(['company_id'=>$company->id, 'subscription_plan_id'=>$plan->id, 'status'=>'active', 'starts_at'=>today()]);
        $role = Role::create(['name'=>'company_manager', 'display_name'=>'Manager', 'company_id'=>$company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name'=>'Manager', 'email'=>'penalty-entry@example.test', 'password'=>bcrypt('test-only'), 'role_id'=>$role->id, 'company_id'=>$company->id, 'is_active'=>true]);
        $this->actingAs($this->user);
        $status = EmployeeStatus::firstOrCreate(['name'=>'Active']);
        $this->employee = Employee::create(['employee_code'=>'PENTRY', 'first_name'=>'Penalty', 'full_name'=>'Penalty Employee', 'company_id'=>$company->id, 'status_id'=>$status->id, 'hire_date'=>'2025-01-01', 'shift_start'=>'09:00', 'shift_end'=>'17:00']);
        foreach (['payroll_period_start_day'=>'1', 'payroll_period_end_day'=>'31'] as $key=>$value) {
            DB::table('hr_operational_settings')->insert(['company_id'=>$company->id, 'setting_key'=>$key, 'setting_value'=>$value, 'label'=>$key, 'created_at'=>now(), 'updated_at'=>now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function payload(array $changes = []): array
    {
        return array_replace(['employee_id'=>$this->employee->id, 'penalty_type_name'=>'توجيه إداري جديد', 'penalty_date'=>'2026-11-16', 'reason'=>'سبب الإجراء', 'deduct_from_salary'=>0], $changes);
    }

    private function summary(): array
    {
        return (new \ReflectionMethod(JobDataController::class, 'buildMonthlyRows'))
            ->invoke(app(JobDataController::class), collect([$this->employee->fresh()]), Carbon::parse('2026-11-01'))->first();
    }

    public function test_new_typed_type_is_created_in_company_master_and_reused_exactly(): void
    {
        $first = $this->postJson('/discipline/penalties', $this->payload())->assertOk();
        $typeId = $first->json('penalty.penalty_type_id');
        $this->assertDatabaseHas('penalty_types', ['id'=>$typeId, 'name'=>'توجيه إداري جديد', 'company_id'=>$this->user->company_id, 'is_active'=>1, 'default_deduction_days'=>0]);
        $this->postJson('/discipline/penalties', $this->payload(['penalty_type_name'=>'  توجيه   إداري جديد  ']))->assertOk()->assertJsonPath('penalty.penalty_type_id', $typeId);
        $this->assertSame(1, PenaltyType::where('name', 'توجيه إداري جديد')->count());
        $this->get('/master?type=penalties')->assertOk()->assertSee('توجيه إداري جديد');
    }

    public function test_existing_type_with_default_days_does_not_deduct_when_checkbox_is_off(): void
    {
        $type = PenaltyType::create(['name'=>'خصم افتراضي', 'company_id'=>$this->user->company_id, 'default_deduction_days'=>3, 'is_active'=>true]);
        $response = $this->postJson('/discipline/penalties', $this->payload(['penalty_type_name'=>$type->name, 'deduction_days'=>5, 'amount'=>1000]))->assertOk();
        $penalty = Penalty::findOrFail($response->json('penalty.id'));
        $this->assertSame($type->id, $penalty->penalty_type_id);
        $this->assertSame('0.00', $penalty->deduction_days);
        $this->assertSame('0.00', $penalty->amount);
        $this->assertSame('Approved', $penalty->status);
        $this->assertSame(0.0, (float) $this->summary()['penalty_days']);
        $this->assertSame(1, $this->summary()['penalty_count']);
    }

    public function test_checkbox_requires_positive_days_and_bad_input_creates_no_master_type(): void
    {
        foreach ([null, 0, -1, 0.001, 1.555, 1000, 'invalid'] as $days) {
            $this->postJson('/discipline/penalties', $this->payload(['deduct_from_salary'=>1, 'deduction_days'=>$days]))->assertUnprocessable()->assertJsonValidationErrors('deduction_days');
        }
        $this->assertSame(0, PenaltyType::where('name','توجيه إداري جديد')->count());
        $this->assertSame(0, Penalty::where('employee_id',$this->employee->id)->count());
    }

    public function test_type_name_is_exact_not_a_partial_or_wildcard_match(): void
    {
        $long = PenaltyType::create(['name'=>'تأخير متكرر', 'company_id'=>$this->user->company_id, 'is_active'=>true]);
        $r = $this->postJson('/discipline/penalties', $this->payload(['penalty_type_name'=>'تأخير']))->assertOk();
        $this->assertNotSame($long->id, $r->json('penalty.penalty_type_id'));
        $this->assertDatabaseHas('penalty_types',['id'=>$r->json('penalty.penalty_type_id'),'name'=>'تأخير']);
    }

    public function test_typed_global_type_is_reused_but_other_company_type_is_not(): void
    {
        $global = PenaltyType::create(['name'=>'Global entry test', 'company_id'=>$this->user->company_id, 'is_active'=>true]);
        DB::table('penalty_types')->where('id',$global->id)->update(['company_id'=>null]);
        $this->postJson('/discipline/penalties',$this->payload(['penalty_type_name'=>$global->name]))->assertOk()->assertJsonPath('penalty.penalty_type_id',$global->id);
        $other = Company::create(['name'=>'Other Penalty Co', 'slug'=>'other-penalty-co', 'status'=>'active']);
        $foreign = PenaltyType::create(['name'=>'Foreign entry test','company_id'=>$other->id,'is_active'=>true]);
        $this->postJson('/discipline/penalties',$this->payload(['penalty_type_id'=>$foreign->id]))->assertUnprocessable()->assertJsonValidationErrors('penalty_type_id');
        $r=$this->postJson('/discipline/penalties',$this->payload(['penalty_type_name'=>$foreign->name]))->assertOk();
        $this->assertNotSame($foreign->id,$r->json('penalty.penalty_type_id'));
        $this->assertDatabaseHas('penalty_types',['id'=>$r->json('penalty.penalty_type_id'),'company_id'=>$this->user->company_id]);
    }

    public function test_details_field_is_removed_and_not_used_for_new_records(): void
    {
        $html=$this->get('/discipline')->assertOk()->getContent();
        $this->assertStringNotContainsString('name="details"',$html);
        $this->assertStringContainsString('name="penalty_type_name"',$html);
        $this->assertStringContainsString('data-penalty-deduct',$html);
        $r=$this->postJson('/discipline/penalties',$this->payload(['details'=>'Removed field ignored']))->assertOk();
        $this->assertSame('سبب الإجراء',Penalty::findOrFail($r->json('penalty.id'))->action);
    }

    public function test_checkbox_days_reach_existing_payroll_and_monthly_summary_immediately(): void
    {
        Salary::create(['company_id'=>$this->user->company_id,'employee_id'=>$this->employee->id,'effective_from'=>'2026-01-01','salary_type'=>'gross','salary_input'=>12000,'basic_salary'=>12000,'allowances'=>0,'housing_allowance'=>0,'transportation_allowance'=>0,'meal_allowance'=>0,'clothing_allowance'=>0,'gross_salary'=>12000,'net_salary'=>12000,'no_insurance'=>true,'status'=>'Active']);
        $run=app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'),[$this->employee->id],$this->user->company_id);
        $record=PayrollRecord::where('payroll_run_id',$run->id)->where('employee_id',$this->employee->id)->firstOrFail();
        $netBefore=(float)$record->net_salary;
        $r=$this->postJson('/discipline/penalties',$this->payload(['deduct_from_salary'=>1,'deduction_days'=>1.5]))->assertOk();
        $id=$r->json('penalty.id');
        $this->assertSame(1.5,(float)$this->summary()['penalty_days']);
        $record->refresh();
        $this->assertSame(1.5,(float)$record->penalty_days);
        $this->assertEqualsWithDelta(480,(float)$record->penalty_deduction,0.01);
        $this->assertEqualsWithDelta($netBefore-480,(float)$record->net_salary,0.01);
        $this->putJson('/discipline/penalties/'.$id,$this->payload(['deduct_from_salary'=>0,'deduction_days'=>9]))->assertOk();
        $record->refresh();
        $this->assertSame(0.0,(float)$record->penalty_days);
        $this->assertSame(0.0,(float)$record->penalty_deduction);
        $this->assertSame(0.0,(float)$this->summary()['penalty_days']);
        $this->assertEqualsWithDelta($netBefore,(float)$record->net_salary,0.01);
    }

    public function test_legacy_client_keeps_type_default_without_checkbox(): void
    {
        $type=PenaltyType::create(['name'=>'Legacy default','company_id'=>$this->user->company_id,'default_deduction_days'=>2,'is_active'=>true]);
        $data=$this->payload(['penalty_type_id'=>$type->id]);
        unset($data['deduct_from_salary']);
        $r=$this->postJson('/discipline/penalties',$data)->assertOk();
        $this->assertSame('2.00',Penalty::findOrFail($r->json('penalty.id'))->deduction_days);
    }

    public function test_edit_preserves_historical_details_and_legacy_amount_metadata(): void
    {
        $r=$this->postJson('/discipline/penalties',$this->payload())->assertOk();
        $p=Penalty::findOrFail($r->json('penalty.id'));
        $p->update(['amount'=>123,'action'=>'Historical details']);
        $this->putJson('/discipline/penalties/'.$p->id,$this->payload(['reason'=>'Changed reason']))->assertOk();
        $this->assertSame('123.00',$p->fresh()->amount);
        $this->assertSame('Historical details',$p->fresh()->action);
        $this->assertSame('0.00',$p->fresh()->deduction_days);
    }

    public function test_finalized_payroll_period_rejects_save_without_creating_type_or_changing_payroll(): void
    {
        $run=app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'),[$this->employee->id],$this->user->company_id);
        $run->update(['status'=>'Locked']);
        $before=PayrollRecord::where('payroll_run_id',$run->id)->get()->toArray();
        $this->postJson('/discipline/penalties',$this->payload(['deduct_from_salary'=>1,'deduction_days'=>2]))->assertUnprocessable();
        $this->assertSame(0,Penalty::where('employee_id',$this->employee->id)->count());
        $this->assertSame(0,PenaltyType::where('name','توجيه إداري جديد')->count());
        $this->assertSame($before,PayrollRecord::where('payroll_run_id',$run->id)->get()->toArray());
        $this->assertSame('Locked',$run->fresh()->status);
    }

    public function test_edit_can_create_new_type_and_keep_immediate_status(): void
    {
        $r=$this->postJson('/discipline/penalties',$this->payload())->assertOk();
        $id=$r->json('penalty.id');
        $this->putJson('/discipline/penalties/'.$id,$this->payload(['penalty_type_name'=>'نوع جديد عند التعديل','deduct_from_salary'=>1,'deduction_days'=>0.25]))->assertOk();
        $p=Penalty::findOrFail($id);
        $this->assertSame('نوع جديد عند التعديل',$p->penaltyType->name);
        $this->assertSame('0.25',$p->deduction_days);
        $this->assertSame('Approved',$p->status);
    }
}
