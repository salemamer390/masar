<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use App\Services\{LeaveBalanceService, PayrollDefaultsService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Amer's 2026-11-09 approval: one source per leave rule, per-company limits,
 * no 15-day per-request cap on annual leave (the balance limits it), casual
 * max 2 days per request, no 6-month wait for casual (annual keeps it), and
 * the early-grant (exception) checkbox keeps working.
 */
class LeaveLegalPhaseOneTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a; private Company $b; private User $ua; private User $ub; private EmployeeStatus $st;
    private LeaveType $annual; private LeaveType $casual; private LeaveType $shared;

    private function company(string $slug): array
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'lss'], ['name' => 'T', 'employee_limit' => 10000, 'features' => []]);
        $c = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'M', 'company_id' => $c->id]);
        $u = User::create(['name' => 'M', 'email' => $slug.'@example.test', 'password' => bcrypt('x'), 'role_id' => $role->id, 'company_id' => $c->id, 'is_active' => true]);
        return [$c, $u];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->st = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        [$this->a, $this->ua] = $this->company('lss-a');
        [$this->b, $this->ub] = $this->company('lss-b');
        foreach ([$this->a, $this->b] as $c) app(PayrollDefaultsService::class)->ensureForCompany($c->id);
        // Shared (company_id NULL) types exactly as the shipped defaults: annual per-request 15, casual wait 6.
        $mk = fn (array $a) => LeaveType::withoutGlobalScopes()->find(DB::table('leave_types')->insertGetId(array_merge(['company_id' => null, 'is_active' => 1, 'default_days' => null], $a)));
        $this->annual = $mk(['name' => 'سنوية', 'policy_code' => 'annual_t', 'is_paid' => 1, 'deducts_annual_balance' => 1, 'max_days_per_request' => 15, 'min_service_months' => 6]);
        DB::table('leave_types')->where('id', $this->annual->id)->update(['policy_code' => 'annual']);
        $this->casual = $mk(['name' => 'عارضة', 'policy_code' => 'casual_t', 'is_paid' => 1, 'deducts_annual_balance' => 1, 'max_days_per_year' => 7, 'min_service_months' => 6]);
        DB::table('leave_types')->where('id', $this->casual->id)->update(['policy_code' => 'casual']);
        $this->shared = $mk(['name' => 'حج تجريبي', 'policy_code' => 'pilgrimage_t', 'is_paid' => 1, 'deducts_annual_balance' => 0, 'max_days_per_request' => 30, 'min_service_months' => 60, 'max_occurrences_service' => 1]);
        DB::table('leave_types')->where('id', $this->shared->id)->update(['policy_code' => 'pilgrimage']);
        $this->annual = LeaveType::withoutGlobalScopes()->find($this->annual->id);
        $this->casual = LeaveType::withoutGlobalScopes()->find($this->casual->id);
        $this->shared = LeaveType::withoutGlobalScopes()->find($this->shared->id);
    }

    private function emp(Company $c, string $code, string $hire): Employee
    {
        $this->actingAs($c->id === $this->a->id ? $this->ua : $this->ub);
        return Employee::create(['employee_code' => $code, 'first_name' => 'E', 'full_name' => 'E'.$code, 'company_id' => $c->id, 'status_id' => $this->st->id, 'hire_date' => $hire]);
    }

    private function request(User $u, Employee $e, LeaveType $t, string $s, string $en, array $extra = [])
    {
        $this->actingAs($u);
        // A fresh controller/service per request, like production.
        app()->forgetInstance(LeaveBalanceService::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        return $this->postJson(route('leaves.store'), array_merge(['employee_id' => $e->id, 'leave_type_id' => $t->id, 'start_date' => $s, 'end_date' => $en], $extra));
    }

    private function save(User $u, array $policies, array $types)
    {
        $this->actingAs($u);
        return $this->putJson(route('payroll.settings.leaves.update'), ['policies' => $policies, 'types' => $types]);
    }

    private function type(string $code, array $fields = []): LeaveType
    {
        $id = DB::table('leave_types')->insertGetId(array_merge(['name' => $code, 'policy_code' => $code, 'is_active' => 1, 'is_paid' => 1, 'deducts_annual_balance' => 0, 'requires_document' => 0], $fields));
        return LeaveType::withoutGlobalScopes()->find($id);
    }

    public function test_age_boundary_is_relative_to_the_balance_date(): void
    {
        $e = $this->emp($this->a, 'age', '2023-01-01'); $e->birth_date = '1976-11-09';
        $svc = new LeaveBalanceService;
        $this->assertSame(21.0, $svc->entitlement($e, \Carbon\Carbon::parse('2026-11-09')));
        $this->assertSame(30.0, $svc->entitlement($e, \Carbon\Carbon::parse('2026-11-10')));
        $this->assertSame(21.0, $svc->entitlement($e, \Carbon\Carbon::parse('2025-11-10')));
        $e->leave_entitlement_category = 'disabled_hazardous';
        $this->assertSame(52.0, $svc->entitlement($e, \Carbon\Carbon::parse('2026-11-09')));
    }

    public function test_maternity_calendar_period_and_postpartum_minimum(): void
    {
        $t = $this->type('maternity', ['max_days_per_request' => 120]);
        $e = $this->emp($this->a, 'mat', '2020-01-01');
        $this->request($this->ua, $e, $t, '2027-01-01', '2027-04-30', ['birth_date' => '2027-02-01'])->assertOk();
        $e2 = $this->emp($this->a, 'mat2', '2020-01-01');
        $this->request($this->ua, $e2, $t, '2027-01-01', '2027-05-01', ['birth_date' => '2027-02-01'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->request($this->ua, $e2, $t, '2027-01-01', '2027-04-30', ['birth_date' => '2027-04-01'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $this->request($this->ua, $e2, $t, '2027-01-01', '2027-04-30')->assertStatus(422)->assertJsonValidationErrors('document');
        $this->request($this->ua, $e2, $t, '2027-04-01', '2027-04-10', ['birth_date' => '2027-01-01'])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_childcare_period_includes_leap_year_and_gap_is_enforced(): void
    {
        $t = $this->type('childcare', ['max_days_per_request' => 730]);
        $e = $this->emp($this->a, 'child', '2020-01-01');
        $this->request($this->ua, $e, $t, '2027-03-01', '2029-02-28')->assertOk();
        $this->request($this->ua, $e, $t, '2029-03-01', '2029-03-04')->assertStatus(422)->assertJsonValidationErrors('start_date');
        $this->request($this->ua, $e, $t, '2031-03-01', '2031-03-04')->assertOk();
    }

    public function test_proof_is_required_and_private_with_tenant_isolation(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $t = $this->type('proof_test', ['requires_document' => 1]);
        $e = $this->emp($this->a, 'proof', '2020-01-01');
        $this->request($this->ua, $e, $t, '2027-06-01', '2027-06-01')->assertStatus(422)->assertJsonValidationErrors('document');
        $file = \Illuminate\Http\UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf');
        $this->request($this->ua, $e, $t, '2027-06-01', '2027-06-01', ['document' => $file])->assertOk();
        $leave = Leave::where('employee_id',$e->id)->firstOrFail();
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($leave->document_path);
        $this->actingAs($this->ua)->get(route('leaves.document', $leave))->assertOk();
        $this->actingAs($this->ub)->get(route('leaves.document', $leave))->assertNotFound();
    }

    public function test_exam_notice_is_ten_not_fifteen_days(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-09 12:00:00'));
        $t = $this->type('exams'); $e = $this->emp($this->a, 'exam','2020-01-01');
        $this->request($this->ua,$e,$t,'2026-11-18','2026-11-18')->assertStatus(422)->assertJsonValidationErrors('document');
        $this->request($this->ua,$e,$t,'2026-11-19','2026-11-19')->assertOk();
        $this->travelBack();
    }

    public function test_reduced_policy_requires_explicit_confirmation_and_is_audited(): void
    {
        $this->save($this->ua,['annual_second_year' => 10],[])->assertStatus(422)->assertJsonValidationErrors('below_reference');
        $this->assertSame('21', DB::table('leave_policy_settings')->where('company_id',$this->a->id)->where('setting_key','annual_second_year')->value('setting_value'));
        $this->putJson(route('payroll.settings.leaves.update'), ['policies'=>['annual_second_year'=>10], 'confirm_below_reference'=>1])->assertOk();
        $this->assertSame('10', DB::table('leave_policy_settings')->where('company_id',$this->a->id)->where('setting_key','annual_second_year')->value('setting_value'));
        $this->assertDatabaseHas('activity_logs',['company_id'=>$this->a->id,'action'=>'leave_policy_update']);
        $this->assertSame('21', DB::table('leave_policy_settings')->where('company_id',$this->b->id)->where('setting_key','annual_second_year')->value('setting_value'));
    }

    public function test_document_policy_override_is_company_scoped(): void
    {
        $t=$this->type('proof_override', ['requires_document'=>1]);
        $this->save($this->ua,['annual_first_year'=>15],[$t->id=>['requires_document'=>0]])->assertOk();
        $this->assertFalse((new LeaveBalanceService)->limitsFor($t,$this->a->id)['requires_document']);
        $this->assertTrue((new LeaveBalanceService)->limitsFor($t,$this->b->id)['requires_document']);
    }

    public function test_contagious_type_is_not_activated_with_an_unverified_pay_rule(): void
    {
        $t=LeaveType::withoutGlobalScopes()->where('policy_code','contagious_contact')->firstOrFail();
        $this->assertFalse($t->is_active);
        $cmp=\App\Support\LeaveLegalReference::typeStatus('contagious_contact',[],true,true,['contagious_max_months'=>3]);
        $this->assertSame('review',$cmp['status']);
    }
    public function test_contact_activation_requires_explicit_pay_and_is_company_scoped(): void
    {
        $this->actingAs($this->ua)->putJson(route('payroll.settings.leaves.update'), ['policies'=>['annual_first_year'=>15],'contact_active'=>1])->assertStatus(422)->assertJsonValidationErrors('contact_pay');
        $this->putJson(route('payroll.settings.leaves.update'), ['policies'=>['annual_first_year'=>15],'contact_active'=>1,'contact_pay'=>'unpaid'])->assertOk();
        $t=LeaveType::where('company_id',$this->a->id)->where('policy_code','contagious_contact_company_'.$this->a->id)->firstOrFail();
        $this->assertTrue($t->is_active);$this->assertFalse($t->is_paid);$this->assertSame('contagious_contact',$t->policy_code);
        $global=LeaveType::withoutGlobalScopes()->whereNull('company_id')->where('policy_code','contagious_contact')->firstOrFail();
        $this->assertFalse($global->is_active);
        $this->actingAs($this->ub);$this->assertNull(LeaveType::find($t->id));
    }

    public function test_inactive_contact_cannot_be_used_by_direct_post(): void
    {
        $t=LeaveType::withoutGlobalScopes()->where('policy_code','contagious_contact')->firstOrFail();
        $e=$this->emp($this->a,'contact-inactive','2020-01-01');
        $this->request($this->ua,$e,$t,'2027-08-01','2027-08-01')->assertStatus(422)->assertJsonValidationErrors('leave_type_id');
    }

    public function test_activated_contact_uses_calendar_limit_and_selected_pay(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->actingAs($this->ua)->putJson(route('payroll.settings.leaves.update'), ['policies'=>['annual_first_year'=>15],'contact_active'=>1,'contact_pay'=>'paid'])->assertOk();
        $t=LeaveType::where('company_id',$this->a->id)->where('policy_code','contagious_contact_company_'.$this->a->id)->firstOrFail();
        $e=$this->emp($this->a,'contact-active','2020-01-01');$f=\Illuminate\Http\UploadedFile::fake()->create('decision.pdf',10,'application/pdf');
        $this->request($this->ua,$e,$t,'2027-08-01','2027-10-31',['document'=>$f])->assertOk();
        $leave=Leave::where('employee_id',$e->id)->firstOrFail();$this->assertTrue($leave->leaveType->is_paid);
        $e2=$this->emp($this->a,'contact-long','2020-01-01');
        $this->request($this->ua,$e2,$t,'2027-08-01','2027-11-01',['document'=>$f])->assertStatus(422)->assertJsonValidationErrors('end_date');
    }

    public function test_blank_policy_cannot_silently_turn_an_entitlement_into_zero(): void
    {
        $this->save($this->ua,['annual_second_year'=>''],[])->assertStatus(422)->assertJsonValidationErrors('policies.annual_second_year');
    }

}
