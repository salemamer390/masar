<?php
namespace Tests\Feature;
use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Role, Salary, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
class SharedSalaryModalTest extends TestCase
{
    use DatabaseTransactions;
    private function fixture(string $slug): array
    {
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'shared-modal-test'], ['name'=>'Test','employee_limit'=>10000,'features'=>[]]);
        $company = Company::create(['slug'=>$slug,'name'=>$slug,'status'=>'active']);
        CompanySubscription::create(['company_id'=>$company->id,'subscription_plan_id'=>$plan->id,'status'=>'active','starts_at'=>today()]);
        $role = Role::create(['company_id'=>$company->id,'name'=>'company_manager','display_name'=>'Manager']);
        $user = User::create(['name'=>'Manager','email'=>$slug.'@example.test','password'=>bcrypt('test-only'),'company_id'=>$company->id,'role_id'=>$role->id,'is_active'=>true]);
        $this->actingAs($user);
        $status = EmployeeStatus::firstOrCreate(['name'=>'Active']);
        $employee = Employee::create(['employee_code'=>'SM-'.$slug,'full_name'=>'موظف اختبار','first_name'=>'موظف','company_id'=>$company->id,'status_id'=>$status->id,'hire_date'=>'2025-01-01']);
        $salary = Salary::create(['employee_id'=>$employee->id,'salary_type'=>'gross','salary_input'=>12000,'gross_salary'=>12000,'basic_salary'=>9600,'allowances'=>2400,'housing_allowance'=>720,'transportation_allowance'=>840,'meal_allowance'=>480,'clothing_allowance'=>360,'effective_from'=>'2026-01-01','status'=>'Active','no_insurance'=>true]);
        return [$user,$salary];
    }
    public function test_details_endpoint_is_the_same_component_as_salary_cards_without_saving_recalculation(): void
    {
        [, $salary] = $this->fixture('modal-a');
        $before = $salary->fresh()->getAttributes();
        $fragment = $this->get(route('salaries.details',$salary))->assertOk()->getContent();
        $this->get(route('salaries.index'))->assertOk()->assertSee(trim($fragment),false);
        $this->assertSame($before,$salary->fresh()->getAttributes());
    }
    public function test_historical_structure_is_available_but_another_company_is_not(): void
    {
        [$owner,$salary] = $this->fixture('modal-b');
        $salary->update(['status'=>'Inactive']);
        $this->get(route('salaries.details',$salary))->assertOk()->assertSee('salaryDetails'.$salary->id,false);
        [, $otherSalary] = $this->fixture('modal-c');
        $this->actingAs($owner);
        $this->get(route('salaries.details',$otherSalary))->assertNotFound();
    }
}
