<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\ImportColumnAlias;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Imports\EmployeeImportService;
use App\Services\Imports\SpreadsheetReader;
use App\Services\Imports\XlsxWriter;
use App\Services\PayrollCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeImportLearningTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;
    private EmployeeImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'wb-import-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = $this->makeCompany('LearningCo', 'learning-co', $plan);
        $this->user = $this->makeManager($this->company, 'learning@example.test');
        $this->actingAs($this->user);
        $this->service = new EmployeeImportService(new SpreadsheetReader(), new PayrollCalculator());
    }

    private function makeCompany(string $name, string $slug, SubscriptionPlan $plan): Company
    {
        $company = Company::create(['name' => $name, 'slug' => $slug, 'status' => 'active']);
        CompanySubscription::create(['company_id' => $company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $company;
    }

    private function makeManager(Company $company, string $email): User
    {
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $company->id]);
        $role->permissions()->sync(Permission::pluck('id'));

        return User::create(['name' => 'Manager', 'email' => $email, 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $company->id, 'is_active' => true]);
    }

    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lrn') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, $sheets);

        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    private function key(string $header): string
    {
        return $this->service->key($header);
    }

    private function commitImport(array $params): \Illuminate\Testing\TestResponse
    {
        $token = $this->postJson('/employees/import/preview', $params)->json('review_token');
        $this->assertNotEmpty($token, 'preview must issue a review token');

        return $this->post('/employees/import', $params + ['review_token' => $token]);
    }

    public function test_confirmed_mappings_are_learned_and_reused_on_the_next_file(): void
    {
        $mappings = json_encode([
            $this->key('رقم الملف') => 'employee_code',
            $this->key('الكود التأميني') => 'insurance_number',
        ]);

        $fileA = $this->xlsx([['name' => 'الموظفين', 'rows' => [
            ['رقم الملف', 'الاسم الكامل', 'الكود التأميني'],
            ['E-1', 'أحمد علي', 'INS-1'],
        ]]]);

        $responseA = $this->commitImport(['file' => $fileA, 'mappings' => $mappings]);
        $responseA->assertOk()->assertJson(['success' => true, 'learned_aliases' => 2]);
        $this->assertSame(2, ImportColumnAlias::where('company_id', $this->company->id)->count());
        $this->assertSame('E-1', Employee::where('company_id', $this->company->id)->value('employee_code'));

        // The next file with the same vocabulary imports with no manual mapping.
        $fileB = $this->xlsx([['name' => 'الموظفين', 'rows' => [
            ['رقم الملف', 'الاسم الكامل', 'الكود التأميني'],
            ['E-2', 'محمد سمير', 'INS-2'],
        ]]]);

        $responseB = $this->commitImport(['file' => $fileB, 'mappings' => '{}']);
        $responseB->assertOk()->assertJson(['success' => true, 'learned_aliases' => 0]);

        $employee = Employee::where('company_id', $this->company->id)->where('full_name', 'محمد سمير')->first();
        $this->assertNotNull($employee);
        $this->assertSame('E-2', $employee->employee_code);
        $this->assertSame('INS-2', $employee->insurance_number);
    }

    public function test_builtin_dictionary_wins_over_a_conflicting_learned_alias(): void
    {
        ImportColumnAlias::create([
            'company_id' => $this->company->id,
            'field' => 'national_id',
            'alias' => 'الكود',
            'alias_key' => $this->key('الكود'),
        ]);

        $response = $this->commitImport(['file' => $this->xlsx([['name' => 'الموظفين', 'rows' => [
            ['الكود', 'الاسم الكامل'],
            ['E-9', 'خالد سيد'],
        ]]]), 'mappings' => '{}']);

        $response->assertOk()->assertJson(['success' => true]);
        $employee = Employee::where('company_id', $this->company->id)->where('full_name', 'خالد سيد')->first();
        $this->assertNotNull($employee);
        $this->assertSame('E-9', $employee->employee_code, 'The built-in «الكود» mapping must beat the learned one.');
        $this->assertNull($employee->national_id);
    }

    public function test_learned_aliases_are_isolated_per_company(): void
    {
        ImportColumnAlias::create([
            'company_id' => $this->company->id,
            'field' => 'employee_code',
            'alias' => 'رقم الملف',
            'alias_key' => $this->key('رقم الملف'),
        ]);

        $file = fn () => $this->xlsx([['name' => 'الموظفين', 'rows' => [
            ['رقم الملف', 'ملاحظة داخلية'],
            ['E-1', 'شيء ما'],
        ]]]);

        $own = $this->postJson('/employees/import/preview', ['file' => $file()]);
        $own->assertOk();
        $this->assertNotContains('رقم الملف', $own->json('unknown_columns'), 'The learned alias must be recognised for its own company.');

        $plan = SubscriptionPlan::where('slug', 'wb-import-unlimited')->first();
        $other = $this->makeCompany('OtherCo', 'other-co', $plan);
        $this->actingAs($this->makeManager($other, 'other@example.test'));

        $foreign = $this->postJson('/employees/import/preview', ['file' => $file()]);
        $foreign->assertOk();
        $this->assertContains('رقم الملف', $foreign->json('unknown_columns'), "Another company must not see this company's learned aliases.");
    }
}
