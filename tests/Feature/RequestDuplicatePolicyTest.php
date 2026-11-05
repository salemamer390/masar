<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\HrRequest;
use App\Models\RequestType;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Duplicate policy on the requests page: an advance or a bonus cannot repeat
 * inside the same month; other dated types save with a warning carrying the
 * existing request's date; permission and mission never warn. Overtime hours
 * come from the from/to window, not from a manually typed number.
 */
class RequestDuplicatePolicyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(
            ['slug' => 'test-unlimited'],
            ['name' => 'Test plan', 'employee_limit' => 10000, 'features' => []]
        );
        $this->company = Company::create(['name' => 'Company DUP', 'slug' => 'company-dup', 'status' => 'active']);
        CompanySubscription::create([
            'company_id' => $this->company->id,
            'subscription_plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => today(),
        ]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $this->user = User::create([
            'name' => 'User DUP', 'email' => 'dup@example.test', 'password' => bcrypt('secret'),
            'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $this->employee = Employee::create([
            'employee_code' => '9101', 'first_name' => 'EmpD', 'full_name' => 'Employee Delta D',
            'company_id' => $this->company->id, 'status_id' => $status->id,
            'shift_start' => '09:00', 'shift_end' => '17:00', 'manual_annual_balance' => 21,
            'gross_salary' => 10000,
        ]);
        foreach (['advance' => 'سلفة', 'bonus' => 'مكافأة', 'incentive' => 'حافز', 'overtime' => 'إضافي', 'permission' => 'إذن'] as $code => $label) {
            RequestType::create([
                'code' => $code, 'display_name' => $label, 'source_key' => $code,
                'is_active' => true, 'company_id' => $this->company->id,
            ]);
        }

        $this->actingAs($this->user);
    }

    private function typeId(string $source): int
    {
        return RequestType::where('source_key', $source)->where('company_id', $this->company->id)->firstOrFail()->id;
    }

    private function postRequest(array $overrides)
    {
        return $this->post(route('requests.store'), array_merge([
            'employee_id' => $this->employee->id,
            'title' => 'طلب اختبار',
            'details' => 'تفاصيل',
        ], $overrides));
    }

    public function test_second_advance_in_same_month_is_blocked(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('advance'),
            'advance_request_date' => '2026-11-05', 'advance_amount' => 100,
        ])->assertSessionDoesntHaveErrors();

        $response = $this->postRequest([
            'request_type_id' => $this->typeId('advance'),
            'advance_request_date' => '2026-11-20', 'advance_amount' => 100,
            'advance_existing_confirm' => '1',
        ]);

        $response->assertSessionHasErrors(['advance_request_date']);
        $this->assertSame(1, HrRequest::count());
    }

    public function test_second_bonus_in_same_month_is_blocked(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('bonus'),
            'financial_request_date' => '2026-11-05', 'financial_amount' => 250,
        ])->assertSessionDoesntHaveErrors();

        $response = $this->postRequest([
            'request_type_id' => $this->typeId('bonus'),
            'financial_request_date' => '2026-11-20', 'financial_amount' => 300,
        ]);

        $response->assertSessionHasErrors(['financial_request_date']);
        $this->assertSame(1, HrRequest::count());
    }

    public function test_second_incentive_in_same_month_saves_with_a_dated_warning(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('incentive'),
            'financial_request_date' => '2026-11-05', 'financial_amount' => 100,
        ])->assertSessionDoesntHaveErrors();

        $response = $this->postRequest([
            'request_type_id' => $this->typeId('incentive'),
            'financial_request_date' => '2026-11-20', 'financial_amount' => 200,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertSessionHas('warning', fn (string $warning) => str_contains($warning, '2026-11-05'));
        $this->assertSame(2, HrRequest::count());
    }

    public function test_overtime_same_day_same_period_warns_but_saves(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'day',
            'from_time' => '17:00', 'to_time' => '19:00',
        ])->assertSessionDoesntHaveErrors();

        $response = $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'day',
            'from_time' => '19:00', 'to_time' => '21:00',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertSessionHas('warning', fn (string $warning) => str_contains($warning, '2026-11-05'));
        $this->assertSame(2, HrRequest::count());
    }

    public function test_overtime_same_day_other_period_saves_without_warning(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'day',
            'from_time' => '17:00', 'to_time' => '19:00',
        ])->assertSessionDoesntHaveErrors();

        $response = $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'night',
            'from_time' => '22:00', 'to_time' => '01:00',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertFalse(session()->has('warning'));
        $this->assertSame(2, HrRequest::count());
    }

    public function test_permission_in_same_month_neither_blocks_nor_warns(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('permission'),
            'request_date' => '2026-11-05', 'from_time' => '10:00', 'to_time' => '11:00',
        ])->assertSessionDoesntHaveErrors();

        $response = $this->postRequest([
            'request_type_id' => $this->typeId('permission'),
            'request_date' => '2026-11-20', 'from_time' => '12:00', 'to_time' => '13:00',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertFalse(session()->has('warning'));
        $this->assertSame(2, HrRequest::count());
    }

    public function test_overtime_hours_come_from_the_window_not_the_posted_number(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'day',
            'day_overtime_hours' => 9,
            'from_time' => '08:00', 'to_time' => '10:30',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(2.5, (float) HrRequest::firstOrFail()->payload['hours']);
    }

    public function test_overnight_overtime_window_crosses_midnight(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'night',
            'from_time' => '22:00', 'to_time' => '02:00',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(4.0, (float) HrRequest::firstOrFail()->payload['hours']);
    }

    public function test_update_recomputes_overtime_hours_and_warns_on_duplicate(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'day',
            'from_time' => '17:00', 'to_time' => '19:00',
        ])->assertSessionDoesntHaveErrors();
        $this->postRequest([
            'request_type_id' => $this->typeId('overtime'),
            'overtime_request_date' => '2026-11-06', 'overtime_period' => 'day',
            'from_time' => '17:00', 'to_time' => '19:00',
        ])->assertSessionDoesntHaveErrors();

        $second = HrRequest::orderByDesc('id')->firstOrFail();
        $response = $this->put(route('requests.update', $second), [
            'title' => 'إضافي معدل',
            'overtime_request_date' => '2026-11-05', 'overtime_period' => 'day',
            'day_overtime_hours' => 7,
            'from_time' => '17:00', 'to_time' => '18:00',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertSessionHas('warning', fn (string $warning) => str_contains($warning, '2026-11-05'));
        $this->assertSame(1.0, (float) $second->fresh()->payload['hours']);
        $this->assertSame('2026-11-05', $second->fresh()->payload['request_date']);
    }

    public function test_update_blocks_bonus_moved_into_a_month_with_an_existing_bonus(): void
    {
        $this->postRequest([
            'request_type_id' => $this->typeId('bonus'),
            'financial_request_date' => '2026-11-05', 'financial_amount' => 250,
        ])->assertSessionDoesntHaveErrors();
        $this->postRequest([
            'request_type_id' => $this->typeId('bonus'),
            'financial_request_date' => '2026-12-05', 'financial_amount' => 250,
        ])->assertSessionDoesntHaveErrors();

        $second = HrRequest::orderByDesc('id')->firstOrFail();
        $response = $this->put(route('requests.update', $second), [
            'title' => 'مكافأة معدلة',
            'financial_request_date' => '2026-11-20', 'financial_amount' => 250,
        ]);

        $response->assertSessionHasErrors(['financial_request_date']);
        $this->assertSame('2026-12-05', $second->fresh()->payload['request_date']);
    }
}
