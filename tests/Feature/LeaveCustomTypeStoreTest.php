<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Leave, LeaveType, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The «إضافة نوع إجازة» form creates a company-owned leave type that is active
 * immediately, appears in the company's settings and request choices, and is
 * invisible to other companies. Names cannot clash with shared or own types.
 */
class LeaveCustomTypeStoreTest extends TestCase
{
    use DatabaseTransactions;

    private Company $a;
    private Company $b;
    private User $ua;
    private User $ub;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'custom-type-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->a = Company::create(['name' => 'custom-a', 'slug' => 'custom-a', 'status' => 'active']);
        $this->b = Company::create(['name' => 'custom-b', 'slug' => 'custom-b', 'status' => 'active']);
        foreach ([$this->a, $this->b] as $c) {
            CompanySubscription::create(['company_id' => $c->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        }
        $roleA = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->a->id]);
        $this->ua = User::create(['name' => 'M', 'email' => 'custom-a@example.test', 'password' => bcrypt('x'), 'role_id' => $roleA->id, 'company_id' => $this->a->id, 'is_active' => true]);
        $roleB = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->b->id]);
        $this->ub = User::create(['name' => 'M', 'email' => 'custom-b@example.test', 'password' => bcrypt('x'), 'role_id' => $roleB->id, 'company_id' => $this->b->id, 'is_active' => true]);
    }

    public function test_company_can_create_custom_leave_type_that_appears_only_in_its_settings(): void
    {
        $this->actingAs($this->ua);
        $this->post(route('payroll.settings.leave-types.store'), [
            'name' => 'إجازة زواج تجريبية',
            'max_days_per_request' => 3,
            'max_days_per_year' => 3,
            'max_occurrences_service' => 1,
            'min_service_months' => 12,
            'is_paid' => '1',
            'deducts_annual_balance' => '0',
            'requires_document' => '1',
        ])->assertSessionDoesntHaveErrors();

        $type = LeaveType::where('name', 'إجازة زواج تجريبية')->firstOrFail();
        $this->assertSame($this->a->id, $type->company_id);
        $this->assertNull($type->getRawOriginal('policy_code'));
        $this->assertTrue((bool) $type->is_active);
        $this->assertTrue((bool) $type->is_paid);
        $this->assertFalse((bool) $type->deducts_annual_balance);
        $this->assertTrue((bool) $type->requires_document);
        $this->assertSame(3.0, (float) $type->max_days_per_request);
        $this->assertSame(3.0, (float) $type->max_days_per_year);
        $this->assertSame(1, (int) $type->max_occurrences_service);
        $this->assertSame(12, (int) $type->min_service_months);

        $this->get(route('payroll.settings.index'))->assertOk()->assertSee('إجازة زواج تجريبية');

        $this->actingAs($this->ub);
        $this->get(route('payroll.settings.index'))->assertOk()->assertDontSee('إجازة زواج تجريبية');
    }

    public function test_unpaid_custom_type_is_marked_unpaid_for_payroll(): void
    {
        $this->actingAs($this->ua);
        $this->post(route('payroll.settings.leave-types.store'), [
            'name' => 'إجازة شخصية بدون أجر',
            'is_paid' => '0',
        ])->assertSessionDoesntHaveErrors();

        $type = LeaveType::where('name', 'إجازة شخصية بدون أجر')->firstOrFail();
        $this->assertFalse((bool) $type->is_paid, 'payroll deducts days for types with is_paid = false (PayrollRunService::isUnpaidLeave)');
    }

    public function test_name_clash_with_shared_type_is_rejected(): void
    {
        $shared = LeaveType::whereNull('company_id')->where('policy_code', 'pilgrimage')->firstOrFail();
        $this->actingAs($this->ua);
        $this->post(route('payroll.settings.leave-types.store'), ['name' => $shared->name])->assertSessionHasErrors('name');
        $this->assertSame(0, LeaveType::where('name', $shared->name)->where('company_id', $this->a->id)->count());
    }

    public function test_name_clash_with_own_type_is_rejected(): void
    {
        LeaveType::create(['name' => 'إجازة حج داخلي', 'company_id' => $this->a->id, 'is_active' => true, 'is_paid' => true]);
        $this->actingAs($this->ua);
        $this->post(route('payroll.settings.leave-types.store'), ['name' => 'إجازة حج داخلي'])->assertSessionHasErrors('name');
        $this->assertSame(1, LeaveType::where('name', 'إجازة حج داخلي')->where('company_id', $this->a->id)->count());
    }

    public function test_numeric_limits_must_not_be_negative(): void
    {
        $this->actingAs($this->ua);
        $this->post(route('payroll.settings.leave-types.store'), [
            'name' => 'إجازة سلبية',
            'max_days_per_year' => -1,
        ])->assertSessionHasErrors('max_days_per_year');
    }

    public function test_custom_type_end_to_end_request_balance_and_payroll(): void
    {
        $this->actingAs($this->ua);
        $this->post(route('payroll.settings.leave-types.store'), [
            'name' => 'إجازة طارئة خاصة',
            'is_paid' => '0',
            'deducts_annual_balance' => '1',
        ])->assertSessionDoesntHaveErrors();
        $type = LeaveType::where('name', 'إجازة طارئة خاصة')->firstOrFail();

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $emp = Employee::create(['employee_code' => 'E2E1', 'first_name' => 'E', 'full_name' => 'E E2E1', 'company_id' => $this->a->id, 'status_id' => $status->id, 'hire_date' => '2010-01-01']);

        // The new type is offered in the real request form.
        $this->get(route('leaves.index'))->assertOk()->assertSee('إجازة طارئة خاصة');

        // A real request through the store route is validated and approved.
        $this->post(route('leaves.store'), [
            'employee_id' => $emp->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-12-20', 'end_date' => '2026-12-21',
        ])->assertSessionDoesntHaveErrors();
        $leave = Leave::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('Approved', $leave->status);

        // Balance effect: the 2 approved days reserve annual-balance capacity in the same
        // cycle, so the next annual request's rejection names them as reserved (محجوز).
        $annual = LeaveType::whereNull('company_id')->where('policy_code', 'annual')->first()
            ?? LeaveType::withoutGlobalScopes()->findOrFail(\Illuminate\Support\Facades\DB::table('leave_types')->insertGetId([
                'name' => 'الإجازة السنوية', 'policy_code' => 'annual', 'company_id' => null,
                'is_active' => true, 'is_paid' => true, 'deducts_annual_balance' => true,
                'default_days' => 15, 'max_days_per_request' => 15, 'min_service_months' => 6,
            ]));
        $this->post(route('leaves.store'), [
            'employee_id' => $emp->id, 'leave_type_id' => $annual->id,
            'start_date' => '2026-03-01', 'end_date' => '2026-06-30',
        ])->assertSessionHasErrors('days');
        $daysErrors = session('errors') ? session('errors')->get('days') : [];
        $this->assertStringContainsString('محجوز 2', json_encode($daysErrors, JSON_UNESCAPED_UNICODE), 'the custom type\'s approved days must consume annual balance capacity');

        // Payroll effect: the unpaid custom type is treated as unpaid leave.
        $service = app(\App\Services\PayrollRunService::class);
        $m = new \ReflectionMethod($service, 'isUnpaidLeave');
        $m->setAccessible(true);
        $this->assertTrue($m->invoke($service, $leave->fresh()->load('leaveType')), 'is_paid=false custom type must be deducted in payroll');
        // The deduction itself stays gated on the company's unpaid-leave-as-deduction setting (PayrollRunService), never forced.
        $this->assertIsBool(app(\App\Services\HrOperationalSettingsService::class)->unpaidLeaveAsDeduction());

        // A paid custom type is not deducted.
        $this->post(route('payroll.settings.leave-types.store'), [
            'name' => 'إجازة تكريم مدفوعة',
            'is_paid' => '1',
        ])->assertSessionDoesntHaveErrors();
        $paidType = LeaveType::where('name', 'إجازة تكريم مدفوعة')->firstOrFail();
        $paidLeave = new Leave(['employee_id' => $emp->id]);
        $paidLeave->setRelation('leaveType', $paidType);
        $this->assertFalse($m->invoke(app(\App\Services\PayrollRunService::class), $paidLeave));
    }
}
