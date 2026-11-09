<?php
namespace Tests\Feature;
use Tests\TestCase;
class SharedDialogSourceTest extends TestCase
{
    public function test_salary_modal_has_one_rendered_component_and_payroll_does_not_rebuild_financial_fields(): void
    {
        $salary = file_get_contents(resource_path('views/salaries/index.blade.php'));
        $payroll = file_get_contents(resource_path('views/payroll/index.blade.php'));
        $this->assertStringContainsString("@include('salaries.partials.details-modal'", $salary);
        $this->assertStringContainsString('data-salary-details-url', $payroll);
        $this->assertStringNotContainsString('payrollSalaryDetails', $payroll);
        $this->assertStringContainsString('document.importNode(modal, true)', $payroll);
    }
    public function test_hr_creation_uses_shared_markup_and_runtime_on_both_entry_points(): void
    {
        $requests = file_get_contents(resource_path('views/requests/index.blade.php'));
        $dashboard = file_get_contents(resource_path('views/dashboard/index.blade.php'));
        $runtime = file_get_contents(resource_path('views/requests/partials/create-behavior.blade.php'));
        $this->assertStringContainsString("@include('requests.partials.create-modal')", $requests);
        $this->assertStringContainsString("@include('requests.partials.create-behavior')", $requests);
        $this->assertStringContainsString('script[data-request-create-runtime]', $dashboard);
        $this->assertSame(3, substr_count($runtime, '<script data-request-create-runtime>'));
        $this->assertStringContainsString('advance-request-fields', $runtime);
        $this->assertStringContainsString('overtime-request-fields', $runtime);
    }
}
