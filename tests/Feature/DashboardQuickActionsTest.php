<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Permission, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardQuickActionsTest extends TestCase
{
    use DatabaseTransactions;

    private function managerWithSubscription(): User
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'dash-cleanup-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => 'DashCleanup', 'slug' => 'dash-cleanup', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $user = User::create(['name' => 'Manager', 'email' => 'dash-cleanup@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
        Employee::create(['employee_code' => 'DC1', 'first_name' => 'Test', 'full_name' => 'Test Employee', 'company_id' => $company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        return $user;
    }

    public function test_dashboard_omits_payroll_run_button_and_subscription_strip(): void
    {
        $this->actingAs($this->managerWithSubscription());

        $html = $this->get('/dashboard')->assertOk()->getContent();

        // Removed per owner request: payroll run quick action + subscription details strip.
        $this->assertStringNotContainsString('إصدار مسير الرواتب', $html);
        $this->assertStringNotContainsString('الباقة الحالية', $html);

        // The remaining quick actions stay available for a full-permission manager.
        $this->assertStringContainsString('موظف جديد', $html);
        $this->assertStringContainsString('تسجيل إجازة', $html);
        $this->assertStringContainsString('تسجيل حضور', $html);
        $this->assertStringContainsString('تسجيل جزاء', $html);
        $this->assertStringContainsString('طلب HR جديد', $html);
    }
}
