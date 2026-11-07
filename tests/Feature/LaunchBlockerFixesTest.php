<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, Penalty, PenaltyType, Permission, Role, SubscriptionPlan, User};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LaunchBlockerFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function managerWithCompany(string $slug): User
    {
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => $slug.'-plan'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $company = Company::create(['name' => $slug, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        return User::create(['name' => 'Manager', 'email' => $slug.'@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
    }

    private function employeeFor(User $user, string $code): Employee
    {
        return Employee::create([
            'employee_code' => $code,
            'first_name' => 'Test',
            'full_name' => 'Test Employee '.$code,
            'company_id' => $user->company_id,
            'status_id' => EmployeeStatus::where('name', 'Active')->value('id'),
            'hire_date' => '2025-01-01',
            'shift_start' => '09:00',
            'shift_end' => '17:00',
        ]);
    }

    public function test_penalty_without_any_matching_type_returns_clear_error_not_500(): void
    {
        $user = $this->managerWithCompany('pen-no-type');
        $employee = $this->employeeFor($user, 'PN1');
        // This company has no penalty types and the default name lookup misses everything.
        $this->assertNull(PenaltyType::where('name', 'like', '%لفت نظر%')->first());

        $response = $this->actingAs($user)->postJson('/discipline/penalties', [
            'employee_id' => $employee->id,
            'penalty_date' => today()->toDateString(),
            'reason' => 'تأخير متكرر',
        ]);

        $response->assertStatus(422);
        $this->assertSame('لا يوجد نوع جزاء مطابق. أضف نوع جزاء من البيانات الأساسية (قوائم الجزاءات) ثم أعد المحاولة.', $response->json('errors.penalty_type_id.0'));
        $this->assertSame(0, Penalty::where('employee_id', $employee->id)->count());
    }

    public function test_penalty_with_existing_type_still_records_approved_immediately(): void
    {
        $user = $this->managerWithCompany('pen-with-type');
        $employee = $this->employeeFor($user, 'PW1');
        $type = PenaltyType::create(['company_id' => $user->company_id, 'name' => 'خصم تأخير', 'default_deduction_days' => 0.5]);

        $response = $this->actingAs($user)->postJson('/discipline/penalties', [
            'employee_id' => $employee->id,
            'penalty_type_id' => $type->id,
            'penalty_date' => today()->toDateString(),
            'reason' => 'تأخير متكرر',
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $penalty = Penalty::where('employee_id', $employee->id)->firstOrFail();
        // Penalties take effect directly; there is no approval step in this release.
        $this->assertSame('Approved', $penalty->status);
        $this->assertSame($type->id, $penalty->penalty_type_id);
        $this->assertSame('0.50', (string) $penalty->deduction_days);
    }

    public function test_penalty_name_fallback_matches_existing_type(): void
    {
        $user = $this->managerWithCompany('pen-name-fallback');
        $employee = $this->employeeFor($user, 'PF1');
        $type = PenaltyType::create(['company_id' => $user->company_id, 'name' => 'لفت نظر', 'default_deduction_days' => 0]);

        $this->actingAs($user)->postJson('/discipline/penalties', [
            'employee_id' => $employee->id,
            'penalty_date' => today()->toDateString(),
            'reason' => 'ملاحظة سلوكية',
        ])->assertOk();

        $this->assertSame($type->id, Penalty::where('employee_id', $employee->id)->firstOrFail()->penalty_type_id);
    }

    public function test_no_public_directory_shadows_a_registered_route(): void
    {
        // Regression guard for the /payroll 404: a same-named directory under public/
        // is served by the web server before Laravel ever sees the request.
        // A request matching an existing public/ directory (like /payroll did) is
        // answered by the web server before Laravel, so the route 404s in production.
        $shadowed = [];
        foreach (Route::getRoutes() as $route) {
            $uri = trim($route->uri(), '/');
            if ($uri !== '' && !str_contains($uri, '{') && is_dir(public_path($uri))) {
                $shadowed[] = $route->uri();
            }
        }
        $this->assertSame([], $shadowed, 'public/ directories shadowing routes: '.implode(', ', $shadowed));
    }
}
