<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Company;
use App\Models\CompanySubscription;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Imports\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeImportSheetKindsTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'kinds-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'KindsCo', 'slug' => 'kinds-co', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'kinds@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function workbook(array $extraSheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wk') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, array_merge([[
            'name' => 'الموظفين',
            'rows' => [
                ['الكود', 'الاسم الكامل', 'رقم الهاتف'],
                ['401', 'أحمد سالم', '01012345678'],
            ],
        ]], $extraSheets));

        return UploadedFile::fake()->createWithContent('workbook.xlsx', file_get_contents($path));
    }

    private function kindsOf(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('sheets'))->pluck('kind', 'name')->all();
    }

    /**
     * Commit through the reviewed plan: store only executes the exact
     * configuration the preview signed, so the token comes from a preview
     * with the same file and parameters.
     */
    private function commitImport(array $params): \Illuminate\Testing\TestResponse
    {
        $token = $this->postJson('/employees/import/preview', $params)->json('review_token');
        $this->assertNotEmpty($token, 'preview must issue a review token');

        return $this->postJson('/employees/import', $params + ['review_token' => $token]);
    }

    public function test_dashboard_summary_and_account_sheets_are_auxiliary_by_name_not_attendance(): void
    {
        $response = $this->postJson('/employees/import/preview', ['file' => $this->workbook([
            ['name' => 'ملخص شؤون العاملين', 'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['401', '2026-11-02', '09:00', '17:00'],
            ]],
            ['name' => 'حسابات اللوحه', 'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور'],
                ['401', '2026-11-02', '09:00'],
            ]],
            ['name' => 'لوحة التحكم', 'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور'],
                ['401', '2026-11-02', '09:00'],
            ]],
            ['name' => 'Dashboard', 'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور'],
                ['401', '2026-11-02', '09:00'],
            ]],
            ['name' => 'Sheet5', 'rows' => [
                ['ملخص شؤون العاملين'],
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['401', '2026-11-02', '09:00', '17:00'],
            ]],
        ])]);
        $response->assertOk();

        $kinds = $this->kindsOf($response);
        foreach (['ملخص شؤون العاملين', 'حسابات اللوحه', 'لوحة التحكم', 'Dashboard', 'Sheet5'] as $name) {
            $this->assertSame('auxiliary', $kinds[$name] ?? null, "sheet {$name} must be auxiliary");
        }
        // None of them may reach the attendance importer in the dry run.
        $this->assertSame(0, (int) $response->json('domains.attendance.imported'));
    }

    public function test_time_columns_without_a_date_column_are_not_attendance(): void
    {
        $response = $this->postJson('/employees/import/preview', ['file' => $this->workbook([
            ['name' => 'ورقة مجمعة', 'rows' => [
                ['كود الموظف', 'وقت الحضور', 'دقائق التأخير'],
                ['401', '09:00', '5'],
            ]],
        ])]);
        $response->assertOk();

        $kinds = $this->kindsOf($response);
        $this->assertNotSame('attendance', $kinds['ورقة مجمعة'] ?? null);
        $this->assertSame(0, (int) $response->json('domains.attendance.imported'));
    }

    public function test_user_override_turns_an_auxiliary_sheet_into_a_real_attendance_import(): void
    {
        $sheets = [[
            'name' => 'كشف الحضور الشهري',
            'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['401', '2026-11-02', '09:00', '17:00'],
            ],
        ]];

        $preview = $this->postJson('/employees/import/preview', ['file' => $this->workbook($sheets)]);
        $preview->assertOk();
        $this->assertSame('auxiliary', $this->kindsOf($preview)['كشف الحضور الشهري'] ?? null);
        $this->assertSame(0, (int) $preview->json('domains.attendance.imported'));

        $commit = $this->commitImport([
            'file' => $this->workbook($sheets),
            'sheet_kinds' => json_encode(['كشف الحضور الشهري' => 'attendance']),
        ]);
        $commit->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $commit->json('distributed.attendance.imported'));

        $employee = Employee::where('employee_code', '401')->firstOrFail();
        $this->assertTrue(
            Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-02')->exists(),
            'the reviewed override must reach the attendance parser'
        );
    }

    public function test_ignored_and_unknown_sheets_never_write_anything(): void
    {
        $sheets = [[
            'name' => 'الحضور',
            'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['401', '2026-11-02', '09:00', '17:00'],
            ],
        ]];

        $ignored = $this->commitImport([
            'file' => $this->workbook($sheets),
            'sheet_kinds' => json_encode(['الحضور' => 'ignore']),
        ]);
        $ignored->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, (int) $ignored->json('distributed.attendance.imported'));
        $this->assertSame(0, Attendance::count());

        $unknown = $this->commitImport([
            'file' => $this->workbook($sheets),
            'sheet_kinds' => json_encode(['الحضور' => 'unknown']),
            'ack_updates' => '1',
        ]);
        $unknown->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, (int) $unknown->json('distributed.attendance.imported'));
        $this->assertSame(0, Attendance::count());
    }

    public function test_tampered_kind_values_are_dropped_and_detection_still_applies(): void
    {
        $response = $this->postJson('/employees/import/preview', [
            'file' => $this->workbook([[
                'name' => 'الحضور',
                'rows' => [
                    ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                    ['401', '2026-11-02', '09:00', '17:00'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['الحضور' => 'hacker", "admin']),
        ]);
        $response->assertOk();
        $this->assertSame('attendance', $this->kindsOf($response)['الحضور'] ?? null);
    }

    public function test_reviewed_column_mapping_commits_nonstandard_headers(): void
    {
        $sheets = [[
            'name' => 'بصمات',
            'rows' => [
                ['مسلسل', 'اليوم', 'بصمة الدخول', 'بصمة الخروج'],
                ['401', '2026-11-03', '08:55', '17:10'],
            ],
        ]];

        // Unmapped: the parser cannot guess these headers and imports nothing.
        $preview = $this->postJson('/employees/import/preview', [
            'file' => $this->workbook($sheets),
            'sheet_kinds' => json_encode(['بصمات' => 'attendance']),
        ]);
        $preview->assertOk();
        $this->assertSame(0, (int) $preview->json('domains.attendance.imported'));

        // The user's reviewed mapping drives the parser at commit.
        $commit = $this->commitImport([
            'file' => $this->workbook($sheets),
            'sheet_kinds' => json_encode(['بصمات' => 'attendance']),
            'domain_mappings' => json_encode(['بصمات' => ['code' => 0, 'date' => 1, 'in' => 2, 'out' => 3]]),
        ]);
        $commit->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $commit->json('distributed.attendance.imported'));

        $employee = Employee::where('employee_code', '401')->firstOrFail();
        $record = Attendance::where('employee_id', $employee->id)->whereDate('attendance_date', '2026-11-03')->first();
        $this->assertNotNull($record, 'the reviewed mapping must reach the parser');
        $this->assertSame('08:55:00', $record->check_in);
        $this->assertSame('17:10:00', $record->check_out);
    }

    public function test_commit_without_review_token_is_rejected_and_writes_nothing(): void
    {
        $response = $this->postJson('/employees/import', [
            'file' => $this->workbook([[
                'name' => 'الحضور',
                'rows' => [
                    ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                    ['401', '2026-11-02', '09:00', '17:00'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['الحضور' => 'attendance']),
        ]);
        $response->assertStatus(422);
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Attendance::count());
    }

    public function test_commit_with_changed_kinds_after_preview_is_rejected(): void
    {
        $file = $this->workbook([[
            'name' => 'الحضور',
            'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['401', '2026-11-02', '09:00', '17:00'],
            ],
        ]]);
        $token = $this->postJson('/employees/import/preview', [
            'file' => $file,
            'sheet_kinds' => json_encode(['الحضور' => 'attendance']),
        ])->json('review_token');
        $this->assertNotEmpty($token);

        // The kinds changed after the preview signed a different plan.
        $response = $this->postJson('/employees/import', [
            'file' => $file,
            'sheet_kinds' => json_encode(['الحضور' => 'ignore']),
            'review_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Attendance::count());
    }

    public function test_commit_with_changed_domain_mapping_after_preview_is_rejected(): void
    {
        $file = $this->workbook([[
            'name' => 'بصمات',
            'rows' => [
                ['مسلسل', 'اليوم', 'بصمة الدخول', 'بصمة الخروج'],
                ['401', '2026-11-03', '08:55', '17:10'],
            ],
        ]]);
        $token = $this->postJson('/employees/import/preview', [
            'file' => $file,
            'sheet_kinds' => json_encode(['بصمات' => 'attendance']),
            'domain_mappings' => json_encode(['بصمات' => ['code' => 0, 'date' => 1, 'in' => 2, 'out' => 3]]),
        ])->json('review_token');
        $this->assertNotEmpty($token);

        // The reviewed mapping changed after the preview signed the plan.
        $response = $this->postJson('/employees/import', [
            'file' => $file,
            'sheet_kinds' => json_encode(['بصمات' => 'attendance']),
            'domain_mappings' => json_encode(['بصمات' => ['code' => 0, 'date' => 1, 'in' => 3, 'out' => 2]]),
            'review_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Attendance::count());
    }

    public function test_commit_requires_insurance_identifier_server_side(): void
    {
        $response = $this->commitImportRaw([
            'file' => $this->workbook([[
                'name' => 'التأمينات',
                'rows' => [
                    ['مسلسل', 'رقم الاشتراك', 'القيمة'],
                    ['401', 'INS-100', '3000'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['التأمينات' => 'insurance']),
            'domain_mappings' => json_encode(['التأمينات' => ['code' => 0, 'insured_salary' => 2]]),
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('أكمل ربط الحقول المطلوبة', (string) $response->json('message'));
        $this->assertSame(0, Employee::count());
    }

    public function test_commit_with_changed_file_after_preview_is_rejected(): void
    {
        $token = $this->postJson('/employees/import/preview', [
            'file' => $this->workbook([[
                'name' => 'الحضور',
                'rows' => [
                    ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                    ['401', '2026-11-02', '09:00', '17:00'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['الحضور' => 'attendance']),
        ])->json('review_token');
        $this->assertNotEmpty($token);

        $response = $this->postJson('/employees/import', [
            'file' => $this->workbook([[
                'name' => 'الحضور',
                'rows' => [
                    ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                    ['401', '2026-11-03', '10:00', '18:00'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['الحضور' => 'attendance']),
            'review_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Attendance::count());
    }

    public function test_commit_with_tampered_kind_value_is_rejected(): void
    {
        $file = $this->workbook([[
            'name' => 'الحضور',
            'rows' => [
                ['كود الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف'],
                ['401', '2026-11-02', '09:00', '17:00'],
            ],
        ]]);
        $token = $this->postJson('/employees/import/preview', [
            'file' => $file,
            'sheet_kinds' => json_encode(['الحضور' => 'attendance']),
        ])->json('review_token');

        $response = $this->postJson('/employees/import', [
            'file' => $file,
            'sheet_kinds' => json_encode(['الحضور' => 'hacker", "admin']),
            'review_token' => $token,
        ]);
        $response->assertStatus(422);
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Attendance::count());
    }

    public function test_commit_requires_complete_domain_mapping_server_side(): void
    {
        // No date column at all, and the reviewed mapping leaves date unset:
        // the server must refuse, not import a partial plan.
        $response = $this->commitImportRaw([
            'file' => $this->workbook([[
                'name' => 'بصمات',
                'rows' => [
                    ['مسلسل', 'بصمة الدخول', 'بصمة الخروج'],
                    ['401', '08:55', '17:10'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['بصمات' => 'attendance']),
            'domain_mappings' => json_encode(['بصمات' => ['code' => 0, 'in' => 1, 'out' => 2]]),
        ]);
        $response->assertStatus(422);
        $this->assertStringContainsString('أكمل ربط الحقول المطلوبة', (string) $response->json('message'));
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Attendance::count());
    }

    public function test_reviewed_leaves_mapping_commits_nonstandard_headers(): void
    {
        LeaveType::create(['name' => 'سنوية']);
        $commit = $this->commitImport([
            'file' => $this->workbook([[
                'name' => 'طلبات الإجازة',
                'rows' => [
                    ['الرقم الوظيفي', 'نوع الإجازة', 'البداية', 'النهاية'],
                    ['401', 'سنوية', '2026-11-10', '2026-11-12'],
                ],
            ]]),
            'sheet_kinds' => json_encode(['طلبات الإجازة' => 'leaves']),
            'domain_mappings' => json_encode(['طلبات الإجازة' => ['code' => 0, 'leave_type' => 1, 'start' => 2, 'end' => 3]]),
        ]);
        $commit->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, (int) $commit->json('distributed.leaves.imported'));

        $employee = Employee::where('employee_code', '401')->firstOrFail();
        $leave = Leave::where('employee_id', $employee->id)->first();
        $this->assertNotNull($leave, 'the reviewed leaves mapping must reach the parser');
        $this->assertSame('2026-11-10', $leave->start_date->toDateString());
        $this->assertSame('2026-11-12', $leave->end_date->toDateString());
    }

    /** Preview + commit with the same parameters, without asserting the token exists (for rejection tests). */
    private function commitImportRaw(array $params): \Illuminate\Testing\TestResponse
    {
        $token = $this->postJson('/employees/import/preview', $params)->json('review_token');

        return $this->postJson('/employees/import', $params + ['review_token' => $token]);
    }
}
