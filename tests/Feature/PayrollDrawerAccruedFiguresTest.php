<?php

namespace Tests\Feature;

use App\Models\{Company, CompanySubscription, Employee, EmployeeStatus, PayrollRecord, Role, SubscriptionPlan, User};
use App\Services\PayrollRunService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PayrollDrawerAccruedFiguresTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:00', 'Africa/Cairo'));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'integration-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'DrawerCo', 'slug' => 'drawer-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $user = User::create(['name' => 'Manager', 'email' => 'drawer@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($user);
        $this->employee = Employee::create(['employee_code' => 'DRAWER1', 'first_name' => 'Lump', 'full_name' => 'Lump Employee', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    public function test_drawer_accrued_figures_use_the_contractual_prorated_amounts(): void
    {
        // Contractual row: basic 6000 + lump allowances 2600 (no itemized split),
        // gross 8600 - the engine's internal split (gross/1.25 = 6880) differs from
        // the contractual basic, which is exactly when the drawer contradicted itself.
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 8600,
            'basic_salary' => 6000, 'allowances' => 2600,
            'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 8600, 'net_salary' => 8000, 'no_insurance' => true, 'status' => 'Active',
        ]);

        // Clock is 2026-11-02: two days into the period, factor 2/30.
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record = $run->records()->where('employee_id', $this->employee->id)->firstOrFail();

        // The record persists the contractual prorated amounts.
        $this->assertEqualsWithDelta(400.00, (float) $record->basic_salary, 0.01); // 6000 x 2/30
        $this->assertEqualsWithDelta(173.33, (float) $record->allowances, 0.01);   // 2600 x 2/30

        // «المستحق حتى اليوم» is the same contractual prorated basic, not the engine split.
        $this->assertEqualsWithDelta((float) $record->basic_salary, (float) data_get($record->calculation_snapshot, 'to_date_entitlement'), 0.01);

        // The drawer total includes the lump «البدلات» line: total = accrued + allowances.
        $response = $this->get(route('payroll.employee-salaries', ['month' => '2026-11-01']));
        $response->assertOk();
        // The people JSON is HTML-hex-escaped (JSON_HEX_QUOT): match the value, not the quote.
        $this->assertMatchesRegularExpression('/entitlements.{0,8}:573\\.33/', $response->getContent()); // 400.00 + 173.33

        // A structure with a contractual itemized split keeps its own amounts,
        // prorated - the engine's split never replaces them on the record.
        $employee2 = Employee::create(['employee_code' => 'DRAWER2', 'first_name' => 'Split', 'full_name' => 'Split Employee', 'company_id' => $this->company->id, 'status_id' => $this->employee->status_id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $employee2->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 8600,
            'basic_salary' => 6000, 'allowances' => 2600,
            'housing_allowance' => 600, 'transportation_allowance' => 500, 'meal_allowance' => 500, 'clothing_allowance' => 1000,
            'gross_salary' => 8600, 'net_salary' => 8000, 'no_insurance' => true, 'status' => 'Active',
        ]);
        app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record2 = PayrollRecord::withoutGlobalScopes()->where('employee_id', $employee2->id)->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(40.00, (float) $record2->housing_allowance, 0.01);        // 600 x 2/30
        $this->assertEqualsWithDelta(33.33, (float) $record2->transportation_allowance, 0.01); // 500 x 2/30
        $this->assertEqualsWithDelta(33.33, (float) $record2->meal_allowance, 0.01);
        $this->assertEqualsWithDelta(66.67, (float) $record2->clothing_allowance, 0.01);
    }
    private function auditRecord(): PayrollRecord
    {
        // Reuse this test's existing drawer fixture, not a screen lifecycle.
        \App\Models\Salary::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'effective_from' => '2026-01-01', 'salary_type' => 'gross', 'salary_input' => 8600,
            'basic_salary' => 6000, 'allowances' => 2600,
            'housing_allowance' => 0, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0,
            'gross_salary' => 8600, 'net_salary' => 8000, 'no_insurance' => true, 'status' => 'Active',
        ]);
        $run = app(PayrollRunService::class)->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        return $run->records()->where('employee_id', $this->employee->id)->firstOrFail();
    }

    public function test_partial_period_money_reconciles_without_a_phantom_piastre(): void
    {
        $record = $this->auditRecord();
        $this->assertSame('573.33', number_format((float) $record->monthly_total, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $record->net_salary, 2, '.', ''));
        $this->assertSame('573.33', number_format((float) $record->employee_cost, 2, '.', ''));
        $this->assertEquals(round((float) $record->basic_salary + (float) $record->allowances - (float) $record->deductions, 2), (float) $record->net_salary);
    }

    public function test_explicit_manual_inputs_survive_repeated_generation_including_zero(): void
    {
        $record = $this->auditRecord();
        $service = app(PayrollRunService::class);
        $record = $service->recalculateRecord($record, ['other_earnings' => 500, 'other_deductions' => 25]);
        $before = $record->net_salary;
        $service->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record->refresh();
        $this->assertEquals(500, $record->other_earnings);
        $this->assertEquals(25, $record->other_deductions);
        $this->assertEquals($before, $record->net_salary);
        $record = $service->recalculateRecord($record, ['other_earnings' => 0]);
        $service->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record->refresh();
        $this->assertEquals(0, $record->other_earnings);
        $this->assertSame(0, (int) data_get($record->calculation_snapshot, 'manual_inputs.other_earnings'));
        $this->assertEquals(25, $record->other_deductions);
    }

    public function test_audited_legacy_override_is_preserved_but_automatic_inputs_still_refresh(): void
    {
        $record = $this->auditRecord();
        $service = app(PayrollRunService::class);
        $record = $service->recalculateRecord($record, ['other_earnings' => 500]);
        $snapshot = $record->calculation_snapshot;
        unset($snapshot['manual_inputs']);
        $record->update(['calculation_snapshot' => $snapshot]);
        \App\Models\ActivityLog::record('payroll_inputs_update', 'test', $record, ['other_earnings' => 0], ['other_earnings' => 500], 'payroll');
        Carbon::setTestNow(Carbon::parse('2026-11-03 18:00:00', 'Africa/Cairo'));
        $service->generate(Carbon::parse('2026-11-01'), null, $this->company->id);
        $record->refresh();
        $this->assertEquals(500, $record->other_earnings);
        $this->assertEquals(3, data_get($record->calculation_snapshot, 'paid_days'));
        $this->assertEquals(3, data_get($record->calculation_snapshot, 'inputs.absence_days'));
    }

    public function test_sparse_payslip_labels_keep_their_meaning_in_both_languages(): void
    {
        $record = $this->auditRecord();
        $record->update(['meal_allowance' => 17, 'day_overtime_amount' => 0, 'ot_day_amount' => 0,
            'night_overtime_amount' => 23, 'overtime_amount' => 23, 'commission' => 29]);
        foreach (['ar', 'en'] as $locale) {
            $captured = null;
            \Illuminate\Support\Facades\View::composer('payroll.payslip-pdf', function ($view) use (&$captured) { $captured = $view->getData(); });
            $response = $this->withSession(['locale' => $locale])->get(route('payroll.employee-salaries.payslip-pdf', $record));
            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $earnings = array_column($captured['earnings'], 1, 0);
            $deductions = array_column($captured['deductions'], 1, 0);
            $this->assertEquals(17, $earnings[$locale === 'ar' ? 'بدل الوجبة' : 'Meal allowance']);
            $this->assertEquals(23, $earnings[$locale === 'ar' ? 'الإضافي الليلي' : 'Night overtime']);
            $this->assertEquals(29, $earnings[$locale === 'ar' ? 'العمولات' : 'Commissions']);
            $this->assertEquals(573.33, $deductions[$locale === 'ar' ? 'خصم الغياب' : 'Absence deduction']);
            $this->assertArrayNotHasKey($locale === 'ar' ? 'تأمين الموظف' : 'Employee insurance', $deductions);
        }
    }

    public function test_controller_fed_drawer_labels_follow_english_locale(): void
    {
        $this->auditRecord();
        $response = $this->withSession(['locale' => 'en'])->get(route('payroll.employee-salaries', ['month' => '2026-11-01']));
        $response->assertOk();
        $people = $response->viewData('prototypePeople');
        $this->assertSame('Draft', $people[0]['stateLabel']);
        $this->assertStringStartsWith('Structure #', $people[0]['structureLabel']);
        $this->assertSame('Accrued to date', $people[0]['items'][0][0]);
        $this->assertSame('Absence deduction', $people[0]['deductions'][0][0]);
    }

    public function test_excel_headers_and_direction_follow_the_selected_locale(): void
    {
        $this->auditRecord();
        foreach (['ar', 'en'] as $locale) {
            $response = $this->withSession(['locale' => $locale])->get(route('payroll.export', ['month' => '2026-11-01', 'format' => 'xlsx']));
            $response->assertOk();
            $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
            $this->assertSame($locale === 'ar', $sheet->getRightToLeft());
            $this->assertSame($locale === 'ar' ? 'الموظف' : 'Employee', (string) $sheet->getCell('A1')->getValue());
            foreach ($sheet->rangeToArray('A1:X1')[0] as $heading) {
                if ($locale === 'en') $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06ff}]/u', $heading);
            }
        }
    }

}
