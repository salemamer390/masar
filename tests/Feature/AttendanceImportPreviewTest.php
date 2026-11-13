<?php

namespace Tests\Feature;

use App\Models\{Attendance, Company, CompanySubscription, Employee, EmployeeStatus, Role, SubscriptionPlan, User};
use App\Services\Imports\XlsxWriter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceImportPreviewTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private Company $company;
    private Employee $day;      // 09:00-17:00
    private Employee $night;    // 22:00-06:00

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:00:00', 'Africa/Cairo'));
        Storage::fake('local');
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'import-preview-unlimited'], ['name' => 'Test', 'employee_limit' => 10000, 'features' => []]);
        $this->company = Company::create(['name' => 'ImportPreview', 'slug' => 'import-preview', 'status' => 'active']);
        CompanySubscription::create(['company_id' => $this->company->id, 'subscription_plan_id' => $plan->id, 'status' => 'active', 'starts_at' => today()]);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $this->company->id]);
        $role->permissions()->sync(\App\Models\Permission::pluck('id'));
        $this->user = User::create(['name' => 'Manager', 'email' => 'import-preview@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($this->user);
        $this->day = Employee::create(['employee_code' => 'I1', 'first_name' => 'Hoda', 'full_name' => 'Hoda Import', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
        $this->night = Employee::create(['employee_code' => 'I2', 'first_name' => 'Khaled', 'full_name' => 'Khaled Night', 'company_id' => $this->company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '22:00', 'shift_end' => '06:00']);
        foreach (['payroll_period_start_day' => '1', 'payroll_period_end_day' => '31'] as $key => $value) {
            DB::table('hr_operational_settings')->insert(['company_id' => $this->company->id, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function workbook(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new XlsxWriter())->writeSheets($path, $sheets);
        return new UploadedFile($path, 'import.xlsx', null, UPLOAD_ERR_OK, true);
    }

    private function dailyRows(array $extra = []): array
    {
        return [['name' => 'اليومي', 'rows' => array_merge([
            ['رقم الموظف', 'تاريخ يوم العمل', 'أول بصمة', 'آخر بصمة', 'الحالة'],
        ], $extra)]];
    }

    /** Upload + first preview. @return array{0:string,1:string[],2:string,3:string} token, unit keys, html, preview key */
    private function preview(UploadedFile $file): array
    {
        $response = $this->post('/attendance/import/preview', ['file' => $file]);
        $response->assertOk();
        return $this->extractPreview($response->getContent());
    }

    private function extractPreview(string $html): array
    {
        preg_match('/name="import_token" value="([0-9a-f-]{36})"/', $html, $tokenMatch);
        preg_match_all('/class="form-check-input unit-radio"[^>]*value="([^"]+)"/', $html, $unitMatches);
        preg_match('/name="preview_key" id="previewKey" value="([0-9a-f]{64})"/', $html, $keyMatch);
        return [$tokenMatch[1] ?? '', $unitMatches[1] ?? [], $html, $keyMatch[1] ?? ''];
    }

    /** Re-preview with changed selections, as the automatic preview refresh does. */
    private function rePreview(string $token, string $unit, array $map = [], array $options = []): array
    {
        $response = $this->post('/attendance/import/preview', ['import_token' => $token, 'unit' => $unit, 'map' => $map, 'options' => $options]);
        $response->assertOk();
        return $this->extractPreview($response->getContent());
    }

    private function import(string $token, string $unit, string $key, array $map = [], array $options = [])
    {
        return $this->post('/attendance/import', ['import_token' => $token, 'unit' => $unit, 'map' => $map, 'options' => $options, 'preview_key' => $key]);
    }

    public function test_preview_lists_detected_sheets_and_imports_nothing(): void
    {
        [$token, $units, $html] = $this->preview($this->workbook([
            ['name' => 'اليومي', 'rows' => [
                ['رقم الموظف', 'تاريخ يوم العمل', 'أول بصمة', 'آخر بصمة', 'الحالة'],
                ['I1', '2026-11-02', '09:00', '17:00', 'حاضر'],
            ]],
            ['name' => 'البصمات الخام', 'rows' => [
                ['رقم الموظف', 'التاريخ والوقت'],
                ['I1', '2026-11-03 09:01:00'],
                ['I1', '2026-11-03 17:05:00'],
            ]],
        ]));
        $this->assertCount(2, $units);
        $this->assertStringContainsString('اليومي', $html);
        $this->assertStringContainsString('البصمات الخام', $html);
        $this->assertStringContainsString('اعتماد واستيراد', $html);
        $this->assertSame(0, Attendance::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_daily_sheet_imports_confirmed_records(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:12', '17:03', 'حاضر'],
            ['I1', '2026-11-03', '08:55', '17:00', 'حاضر'],
        ])));
        $this->import($token, $units[0], $key)->assertRedirect(route('attendance.index'));
        $rows = Attendance::where('employee_id', $this->day->id)->orderBy('attendance_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-11-02', $rows[0]->attendance_date->toDateString());
        $this->assertSame('import', $rows[0]->source);
        $this->assertSame('Approved', $rows[0]->approval_status);
        $this->assertStringStartsWith('09:12', (string) $rows[0]->check_in);
    }

    public function test_raw_punches_group_into_one_overnight_record(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook([
            ['name' => 'البصمات', 'rows' => [
                ['رقم الموظف', 'التاريخ والوقت'],
                ['I2', '2026-11-02 21:58:00'],
                ['I2', '2026-11-03 06:02:00'],
                ['I2', '2026-11-03 22:01:00'],
                ['I2', '2026-11-04 05:59:00'],
            ]],
        ]));
        $this->import($token, $units[0], $key);
        $rows = Attendance::where('employee_id', $this->night->id)->orderBy('attendance_date')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-11-02', $rows[0]->attendance_date->toDateString());
        $this->assertStringStartsWith('21:58', (string) $rows[0]->check_in);
        $this->assertStringStartsWith('06:02', (string) $rows[0]->check_out);
    }

    public function test_per_employee_sheets_group_into_one_unit_and_resolve_by_code(): void
    {
        $sheet = fn (string $code, string $name, string $day) => [
            'name' => $name,
            'rows' => [
                ['الموظف', $name],
                ['الكود', $code],
                ['القسم', 'التشغيل'],
                [''],
                ['التاريخ', 'اليوم', 'الحضور', 'الانصراف', 'الحالة'],
                [$day, 'الأحد', '09:05', '17:10', 'حاضر'],
            ],
        ];
        [$token, $units, $html, $key] = $this->preview($this->workbook([
            $sheet('I1', 'Hoda Import', '2026-11-02'),
            $sheet('I2', 'Khaled Night', '2026-11-02'),
        ]));
        $this->assertCount(1, $units, 'same-shape per-employee sheets form one unit');
        $this->assertStringContainsString('2 ورقة', $html);
        $this->import($token, $units[0], $key);
        $this->assertDatabaseHas('attendance', ['employee_id' => $this->day->id]);
        $this->assertDatabaseHas('attendance', ['employee_id' => $this->night->id]);
    }

    public function test_employee_identity_requires_code_and_flags_mismatch(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook([
            ['name' => 'اليومي', 'rows' => [
                ['رقم الموظف', 'اسم الموظف', 'تاريخ يوم العمل', 'أول بصمة', 'آخر بصمة'],
                ['I1', 'Hoda Import', '2026-11-02', '09:00', '17:00'],
                ['I1', 'Wrong Name', '2026-11-03', '09:00', '17:00'],
                ['ZZZ', 'Ghost', '2026-11-03', '09:00', '17:00'],
            ]],
        ]));
        $response = $this->import($token, $units[0], $key);
        $response->assertSessionHas('attendance_import_errors', function ($errors) {
            $text = implode(' ', $errors);
            return str_contains($text, 'لا يطابق اسم الموظف') && str_contains($text, 'غير موجود');
        });
        $rows = Attendance::where('employee_id', $this->day->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('2026-11-02', $rows[0]->attendance_date->toDateString());
    }

    public function test_name_only_rows_are_rejected_for_clarification_not_resolved(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook([
            ['name' => 'بالاسم', 'rows' => [
                ['اسم الموظف', 'التاريخ', 'الحضور', 'الانصراف'],
                ['Hoda Import', '2026-11-03', '09:00', '17:00'],
            ]],
        ]));
        $response = $this->import($token, $units[0], $key);
        $response->assertSessionHas('attendance_import_errors', fn ($errors) => str_contains(implode(' ', $errors), 'كود الموظف مطلوب'));
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count());
    }

    public function test_blank_rows_are_skipped_not_fabricated_as_absent(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '', '', ''],
            ['I1', '2026-11-03', '', '', 'غائب'],
        ])));
        $response = $this->import($token, $units[0], $key);
        $response->assertSessionHas('attendance_import_errors', fn ($errors) => str_contains(implode(' ', $errors), 'بلا بصمات'));
        $rows = Attendance::where('employee_id', $this->day->id)->get();
        $this->assertCount(1, $rows, 'only the explicit Absent row is imported');
        $this->assertSame('Absent', $rows[0]->status);
        $this->assertSame('2026-11-03', $rows[0]->attendance_date->toDateString());
    }

    public function test_changing_mapping_requires_fresh_preview_before_commit(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:12', '17:03', 'حاضر'],
        ])));
        // Commit with a swapped mapping but the stale preview key: rejected, nothing written.
        $rejected = $this->import($token, $units[0], $key, ['check_in' => 3, 'check_out' => 2]);
        $rejected->assertStatus(422);
        $this->assertStringContainsString('تغيّرت الورقة أو التعيين', $rejected->getContent());
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count());

        // The rejection re-renders the preview with the new interpretation and a fresh key; confirming that works.
        [, , , $freshKey] = $this->extractPreview($rejected->getContent());
        $this->import($token, $units[0], $freshKey, ['check_in' => 3, 'check_out' => 2]);
        $row = Attendance::where('employee_id', $this->day->id)->first();
        $this->assertStringStartsWith('17:03', (string) $row->check_in, 'swapped mapping applied after re-preview');
        $this->assertStringStartsWith('09:12', (string) $row->check_out);
    }

    public function test_repreview_recalculates_stats_with_new_mapping(): void
    {
        [$token, $units] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:12', '17:03', 'حاضر'],
        ])));
        [, , $html] = $this->rePreview($token, $units[0], ['check_in' => 3, 'check_out' => 2]);
        $this->assertStringContainsString('17:03', $html);
    }

    public function test_existing_records_require_explicit_overwrite_confirmation(): void
    {
        Attendance::create(['employee_id' => $this->day->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-02', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Approved', 'source' => 'manual']);
        [$token, $units, $html, $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:30', '18:00', 'حاضر'],
        ])));
        $this->assertStringContainsString('سيُحدَّث 1 سجلًا موجودًا', $html);
        $this->assertStringContainsString('يدوي', $html);

        // Without the checkbox: rejected, the manual record is untouched.
        $rejected = $this->import($token, $units[0], $key);
        $rejected->assertStatus(422);
        $this->assertStringContainsString('أكّد خيار التحديث', $rejected->getContent());
        $this->assertSame('manual', Attendance::where('employee_id', $this->day->id)->first()->source);

        // The acknowledgement is commit-time consent: checking it must not invalidate the
        // preview, so the original preview key commits directly with the overwrite option.
        $this->import($token, $units[0], $key, [], ['overwrite' => 1])->assertRedirect(route('attendance.index'));
        $row = Attendance::where('employee_id', $this->day->id)->first();
        $this->assertSame('import', $row->source);
        $this->assertStringStartsWith('09:30', (string) $row->check_in);
    }

    public function test_overwrite_acknowledgement_keeps_the_preview_valid(): void
    {
        Attendance::create(['employee_id' => $this->day->id, 'company_id' => $this->company->id, 'attendance_date' => '2026-11-02', 'check_in' => '09:00', 'check_out' => '17:00', 'status' => 'Present', 'approval_status' => 'Approved', 'source' => 'manual']);
        [$token, $units, $html, $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:30', '18:00', 'حاضر'],
        ])));

        // Re-rendering the preview with the overwrite option set returns the same preview key:
        // the checkbox is consent, not interpretation, so it never forces a refresh.
        [, , , $refreshedKey] = $this->rePreview($token, $units[0], [], ['overwrite' => 1]);
        $this->assertSame($key, $refreshedKey, 'overwrite toggle must not change the preview key');

        // The preview auto-refreshes on sheet/mapping changes and no longer marks the
        // acknowledgement checkbox as a stale-making change.
        $this->assertStringContainsString('requestSubmit', $html);
        $this->assertStringNotContainsString('markStale', $html);
    }

    public function test_ambiguous_text_dates_require_an_explicit_format(): void
    {
        $csv = "كود الموظف,التاريخ,الحضور,الانصراف\n"
            . "I1,03/04/2026,09:00,17:00\n";
        $file = UploadedFile::fake()->createWithContent('attendance.csv', $csv);
        [$token, $units, $html, $key] = $this->preview($file);
        $this->assertStringContainsString('تنسيق التاريخ النصي', $html);

        $rejected = $this->import($token, $units[0], $key);
        $rejected->assertStatus(422);
        $this->assertStringContainsString('تواريخ نصية غامضة', $rejected->getContent());
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count());

        [, , , $formatKey] = $this->rePreview($token, $units[0], [], ['date_format' => 'dmy']);
        $this->import($token, $units[0], $formatKey, [], ['date_format' => 'dmy']);
        $row = Attendance::where('employee_id', $this->day->id)->first();
        $this->assertSame('2026-04-03', $row->attendance_date->toDateString(), 'd/m honored, not guessed m/d');
    }

    public function test_impossible_text_dates_are_rejected_not_rolled_over(): void
    {
        $csv = "كود الموظف,التاريخ,الحضور,الانصراف\n"
            . "I1,31/02/2026,09:00,17:00\n"
            . "I1,13/13/2026,09:00,17:00\n"
            . "I1,04/25/2026,09:00,17:00\n";
        $file = UploadedFile::fake()->createWithContent('attendance.csv', $csv);
        [$token, $units] = $this->preview($file);
        [, , , $formatKey] = $this->rePreview($token, $units[0], [], ['date_format' => 'dmy']);
        $response = $this->import($token, $units[0], $formatKey, [], ['date_format' => 'dmy']);
        $response->assertSessionHas('attendance_import_errors', fn ($errors) => str_contains(implode(' ', $errors), 'تاريخ الحضور غير صالح'));
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count(), 'no rolled-over dates imported');
    }

    public function test_strict_dates_and_datetimes_reject_overflow_in_every_path(): void
    {
        $controller = app(\App\Http\Controllers\AttendanceImportController::class);
        foreach (['parseDateStrict', 'parseDateTimeStrict'] as $methodName) {
            $method = new \ReflectionMethod($controller, $methodName);
            foreach (['31/02/2026', '13/13/2026', '04/25/2026', '2026-02-31'] as $invalid) {
                $this->assertNull($method->invoke($controller, $invalid, 'dmy'), $methodName . ' ' . $invalid);
            }
            $this->assertNull($method->invoke($controller, '2026-02-31', ''), $methodName . ' ISO without format');
        }
        $method = new \ReflectionMethod($controller, 'parseDateTimeStrict');
        foreach (['31/02/2026 09:00', '04/25/2026 09:00', '13/13/2026 09:00', '02/11/2026 25:00', '2026-11-02 09:65'] as $invalid) {
            $this->assertNull($method->invoke($controller, $invalid, 'dmy'), $invalid);
        }
    }

    public function test_ambiguous_date_after_row_fifty_still_requires_format(): void
    {
        $rows = array_fill(0, 55, ['I1', '2026-11-02', '09:00', '17:00', 'حاضر']);
        $rows[] = ['I1', '03/04/2026', '09:00', '17:00', 'حاضر'];
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows($rows)));
        $response = $this->import($token, $units[0], $key);
        $response->assertStatus(422);
        $this->assertStringContainsString('تواريخ نصية غامضة', $response->getContent());
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count());
    }

    public function test_corrupt_workbook_is_deleted_on_load(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:00', '17:00', 'حاضر'],
        ])));
        foreach (Storage::disk('local')->files('imports') as $file) {
            if (str_starts_with(basename($file), $token . '.') && !str_ends_with($file, '.json')) Storage::disk('local')->put($file, 'invalid zip');
        }
        $this->import($token, $units[0], $key)->assertSessionHasErrors('file');
        $this->assertEmpty(array_filter(Storage::disk('local')->files('imports'), fn ($f) => str_starts_with(basename($f), $token . '.')));
    }

    public function test_old_orphan_workbook_is_pruned_but_fresh_one_is_kept(): void
    {
        Storage::disk('local')->put('imports/orphan.xlsx','orphan');
        touch(Storage::disk('local')->path('imports/orphan.xlsx'),now()->subHours(2)->timestamp);
        Storage::disk('local')->put('imports/fresh.xlsx','fresh');
        touch(Storage::disk('local')->path('imports/fresh.xlsx'),now()->timestamp);
        \App\Http\Controllers\AttendanceImportController::purgeStaleImportFiles();
        Storage::disk('local')->assertMissing('imports/orphan.xlsx');
        Storage::disk('local')->assertExists('imports/fresh.xlsx');
    }

    public function test_stale_import_files_are_pruned(): void
    {
        [$token] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:00', '17:00', 'حاضر'],
        ])));
        // Age the fresh preview past the TTL.
        $meta = json_decode(Storage::disk('local')->get("imports/{$token}.json"), true);
        $meta['created_at'] = now()->subHours(2)->toIso8601String();
        Storage::disk('local')->put("imports/{$token}.json", json_encode($meta));
        Storage::disk('local')->put("imports/corrupt-token.json", '{not valid json');

        \App\Http\Controllers\AttendanceImportController::purgeStaleImportFiles();

        $this->assertEmpty(array_filter(Storage::disk('local')->files('imports'), fn ($f) => str_starts_with(basename($f), $token . '.')), 'expired preview files pruned');
        Storage::disk('local')->assertMissing('imports/corrupt-token.json');

        // A fresh preview survives the sweep.
        [$freshToken] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-03', '09:00', '17:00', 'حاضر'],
        ])));
        \App\Http\Controllers\AttendanceImportController::purgeStaleImportFiles();
        Storage::disk('local')->assertExists("imports/{$freshToken}.json");
    }

    public function test_day_with_three_punches_is_skipped_for_manual_review(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook([
            ['name' => 'البصمات', 'rows' => [
                ['رقم الموظف', 'التاريخ والوقت'],
                ['I1', '2026-11-02 08:55:00'],
                ['I1', '2026-11-02 13:10:00'],
                ['I1', '2026-11-02 17:05:00'],
            ]],
        ]));
        $response = $this->import($token, $units[0], $key);
        $response->assertSessionHas('attendance_import_errors', fn ($errors) => str_contains(implode(' ', $errors), '3 بصمات'));
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count());
    }

    public function test_overlapping_long_shift_windows_are_an_ambiguity_error(): void
    {
        $this->night->forceFill(['shift_start' => '04:00', 'shift_end' => '23:00'])->save();
        [$token, $units, , $key] = $this->preview($this->workbook([
            ['name' => 'البصمات', 'rows' => [
                ['رقم الموظف', 'التاريخ والوقت'],
                ['I2', '2026-11-03 01:00:00'],
            ]],
        ]));
        $response = $this->import($token, $units[0], $key);
        $response->assertSessionHas('attendance_import_errors', fn ($errors) => str_contains(implode(' ', $errors), 'ورديتين متداخلتين'));
        $this->assertSame(0, Attendance::where('employee_id', $this->night->id)->count());
    }

    public function test_token_is_bound_to_uploading_user_and_company(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:00', '17:00', 'حاضر'],
        ])));
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $otherCompany = Company::create(['name' => 'Other', 'slug' => 'other-import', 'status' => 'active']);
        $role = Role::create(['name' => 'company_manager', 'display_name' => 'Manager', 'company_id' => $otherCompany->id]);
        $role->permissions()->sync(\App\Models\Permission::pluck('id'));
        $other = User::create(['name' => 'Other', 'email' => 'other-import@example.test', 'password' => bcrypt('test-only'), 'role_id' => $role->id, 'company_id' => $otherCompany->id, 'is_active' => true]);
        // Cross-company: blocked by the permission middleware before the token is even read.
        $this->actingAs($other)
            ->post('/attendance/import', ['import_token' => $token, 'unit' => $units[0], 'map' => [], 'options' => [], 'preview_key' => $key])
            ->assertForbidden();

        // Same company but a different user: the token itself refuses them.
        $colleague = User::create(['name' => 'Colleague', 'email' => 'colleague-import@example.test', 'password' => bcrypt('test-only'), 'role_id' => $this->user->role_id, 'company_id' => $this->company->id, 'is_active' => true]);
        $this->actingAs($colleague)
            ->post('/attendance/import', ['import_token' => $token, 'unit' => $units[0], 'map' => [], 'options' => [], 'preview_key' => $key])
            ->assertSessionHasErrors('file');
        $this->actingAs($colleague)
            ->post('/attendance/import/preview', ['import_token' => $token, 'unit' => $units[0]])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->count());
    }

    public function test_token_expires_and_replay_after_success_is_rejected(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:00', '17:00', 'حاضر'],
        ])));
        $this->import($token, $units[0], $key)->assertRedirect(route('attendance.index'));
        // Replay with the same token+key: temp file was consumed.
        $this->import($token, $units[0], $key)->assertSessionHasErrors('file');
        $this->assertSame(1, Attendance::where('employee_id', $this->day->id)->count());

        // TTL: a fresh preview expires after 30 minutes.
        [$token2, $units2, , $key2] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-05', '09:00', '17:00', 'حاضر'],
        ])));
        Carbon::setTestNow(Carbon::parse('2026-11-26 18:31:00', 'Africa/Cairo'));
        $this->import($token2, $units2[0], $key2)->assertSessionHasErrors('file');
        $this->assertSame(0, Attendance::where('employee_id', $this->day->id)->where('attendance_date', '2026-11-05')->count());
    }

    public function test_temp_files_are_cleaned_up_after_import(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:00', '17:00', 'حاضر'],
        ])));
        Storage::disk('local')->assertExists("imports/{$token}.json");
        $this->import($token, $units[0], $key);
        Storage::disk('local')->assertMissing("imports/{$token}.json");
        $this->assertEmpty(array_filter(Storage::disk('local')->files('imports'), fn ($f) => str_starts_with(basename($f), $token . '.')));
    }

    public function test_non_attendance_sheets_are_skipped_with_reasons(): void
    {
        [, $units, $html] = $this->preview($this->workbook([
            ['name' => 'اليومي', 'rows' => [
                ['رقم الموظف', 'تاريخ يوم العمل', 'أول بصمة', 'آخر بصمة'],
                ['I1', '2026-11-02', '09:00', '17:00'],
            ]],
            ['name' => 'الملخص', 'rows' => [
                ['إجمالي أيام العمل', 'الإضافي', 'الخصومات'],
                ['22', '3', '0'],
            ]],
        ]));
        $this->assertCount(1, $units);
        $this->assertStringContainsString('الملخص', $html);
        $this->assertStringContainsString('أوراق تم تخطيها', $html);
    }

    public function test_extra_punch_day_leaves_existing_attendance_explicitly_unchanged(): void
    {
        $attendance=Attendance::create(['employee_id'=>$this->day->id,'attendance_date'=>'2026-11-02','check_in'=>'09:10','check_out'=>'17:10','status'=>'Present','source'=>'manual']);
        [$token,$units,$html,$key]=$this->preview($this->workbook([['name'=>'البصمات','rows'=>[
            ['رقم الموظف','التاريخ والوقت'],['I1','2026-11-02 08:55:00'],['I1','2026-11-02 13:00:00'],['I1','2026-11-02 17:05:00'],
        ]]]));
        $this->assertStringContainsString('السجل الحالي سيبقى كما هو',$html);
        $this->assertStringNotContainsString('أؤكد تحديث 1',$html);
        $this->import($token,$units[0],$key)->assertSessionHasNoErrors();
        $this->assertStringStartsWith('09:10',$attendance->fresh()->check_in);
        $this->assertSame('pending',\App\Models\AttendanceImportDay::latest('id')->first()->resolution);
    }

    public function test_raw_blank_punch_row_never_creates_absence_and_source_is_retained(): void
    {
        [$token,$units,,$key]=$this->preview($this->workbook([['name'=>'البصمات','rows'=>[
            ['رقم الموظف','التاريخ والوقت'],['I1',''],['I1','2026-11-02 09:00:00'],['I1','2026-11-02 17:00:00'],
        ]]]));
        $r=$this->import($token,$units[0],$key);$r->assertSessionHas('attendance_import_errors');
        $this->assertSame(1,Attendance::where('employee_id',$this->day->id)->count());
        $this->assertSame(0,Attendance::where('employee_id',$this->day->id)->where('status','Absent')->count());
        $batch=\App\Models\AttendanceImportBatch::latest('id')->first();
        $this->assertCount(4,$batch->original_data['sheets'][0]['rows']);
    }

    public function test_reimport_updates_instead_of_duplicating(): void
    {
        $make = fn () => $this->workbook($this->dailyRows([
            ['I1', '2026-11-02', '09:00', '18:30', 'حاضر'],
        ]));
        [$token, $units, , $key] = $this->preview($make());
        $this->import($token, $units[0], $key);

        [$token2, $units2, , ] = $this->preview($make());
        // The re-import would overwrite the first import's row: confirm explicitly.
        [, , , $confirmKey] = $this->rePreview($token2, $units2[0], [], ['overwrite' => 1]);
        $this->import($token2, $units2[0], $confirmKey, [], ['overwrite' => 1]);
        $rows = Attendance::where('employee_id', $this->day->id)->get();
        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('18:30', (string) $rows[0]->check_out);
    }

    public function test_upload_progress_request_returns_token_that_opens_normal_preview(): void
    {
        $response = $this->withHeaders(['X-Import-Upload' => '1', 'Accept' => 'application/json'])
            ->post('/attendance/import/preview', ['file' => $this->workbook($this->dailyRows([['I1', '2026-11-20', '09:00', '17:00', '']]))]);
        $response->assertOk()->assertJsonStructure(['import_token']);
        $token = $response->json('import_token');
        $this->post('/attendance/import/preview', ['import_token' => $token])->assertOk()->assertSee($token, false);
    }

    public function test_upload_progress_request_reports_unreadable_sheet_as_json_error(): void
    {
        $file = $this->workbook([['name' => 'x', 'rows' => [['a', 'b'], ['1', '2']]]]);
        $this->withHeaders(['X-Import-Upload' => '1', 'Accept' => 'application/json'])
            ->post('/attendance/import/preview', ['file' => $file])
            ->assertStatus(422)->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'لم يُعثر'));
    }

    public function test_common_header_names_are_recognised_without_manual_mapping(): void
    {
        $rows = [['name' => 'Sheet1', 'rows' => [['كود العامل', 'تاريخ اليوم', 'دخول', 'خروج'], ['I1', '2026-11-20', '09:00', '17:00']]]];
        [$token, $units] = $this->preview($this->workbook($rows));
        $this->assertNotSame('', $token);
        $this->assertNotEmpty($units);
        $english = [['name' => 'Sheet1', 'rows' => [['Employee ID', 'Date', 'Time In', 'Time Out'], ['I1', '2026-11-20', '09:00', '17:00']]]];
        [, $units] = $this->preview($this->workbook($english));
        $this->assertNotEmpty($units);
    }

    public function test_sheet_name_hint_only_classifies_when_columns_are_inconclusive(): void
    {
        $service = app(\App\Services\Imports\EmployeeImportService::class);
        $method = new \ReflectionMethod($service, 'detectWorkbookSheetKind');
        $method->setAccessible(true);
        $this->assertSame('attendance', $method->invoke($service, [['x', 'y']], [], 'Attendance'));
        $this->assertSame('employees', $method->invoke($service, [['x', 'y']], [], 'بيانات العاملين'));
        $this->assertSame('unknown', $method->invoke($service, [['x', 'y']], [], 'Sheet1'));
        $this->assertSame('auxiliary', $method->invoke($service, [['x', 'y']], [], 'لوحة التحكم'));
    }
    public function test_complete_rejection_export_contains_more_than_25_errors_and_no_mutations(): void
    {
        $rows = [];
        for ($i=0; $i<40; $i++) $rows[] = ['MISSING-'.$i, '2026-11-20', '09:00', '17:00', 'Present'];
        [$token, $units, $html] = $this->preview($this->workbook($this->dailyRows($rows)));
        $this->assertStringContainsString('MISSING-39', $html);
        $before = Attendance::count();
        $response = $this->post('/attendance/import/rejected', ['import_token'=>$token, 'unit'=>$units[0]]);
        $response->assertOk()->assertDownload('attendance-rejected.xlsx');
        $sheets = (new \App\Services\Imports\SpreadsheetReader())->readAll($response->baseResponse->getFile()->getPathname(), 'xlsx');
        $this->assertCount(41, $sheets[0]['rows']);
        $this->assertStringContainsString('MISSING-39', json_encode($sheets));
        $this->assertSame($before, Attendance::count());
    }

    public function test_rejection_export_refuses_other_user_and_expired_upload(): void
    {
        [$token, $units] = $this->preview($this->workbook($this->dailyRows([['MISSING','2026-11-20','09:00','17:00','Present']])));
        $originalId = $this->user->id;
        $other = $this->user->replicate(); $other->email='other-import@example.test'; $other->save();
        $this->actingAs($other)->post('/attendance/import/rejected',['import_token'=>$token,'unit'=>$units[0]])->assertStatus(410);
        $this->actingAs(User::find($originalId));
        Carbon::setTestNow(now()->addMinutes(31));
        $this->post('/attendance/import/rejected',['import_token'=>$token,'unit'=>$units[0]])->assertStatus(410);
    }

    public function test_wizard_result_keeps_full_report_after_source_is_consumed(): void
    {
        $rows = [['I1','2026-11-20','09:00','17:00','Present']];
        for($i=0;$i<35;$i++) $rows[]=['BAD-'.$i,'2026-11-20','09:00','17:00','Present'];
        [$token,$units,,$key]=$this->preview($this->workbook($this->dailyRows($rows)));
        $response=$this->post('/attendance/import',['import_token'=>$token,'unit'=>$units[0],'preview_key'=>$key,'wizard'=>'1']);
        $response->assertRedirect(); $url=$response->headers->get('Location');
        $this->assertStringContainsString('/attendance/import-result/',$url);
        $this->get($url)->assertOk()->assertSee('35');
        $download=$this->get($url.'/rejected'); $download->assertOk()->assertDownload('attendance-rejected.xlsx');
        $sheets=(new \App\Services\Imports\SpreadsheetReader())->readAll($download->baseResponse->getFile()->getPathname(),'xlsx');
        $this->assertCount(36,$sheets[0]['rows']);
        $other=$this->user->replicate(); $other->email='report-other@example.test'; $other->save();
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->get($url.'/rejected')->assertNotFound();
        $this->actingAs($this->user); Carbon::setTestNow(now()->addMinutes(31));
        $this->get($url)->assertStatus(410);
    }


    public function test_arabic_names_digits_dates_times_and_statuses_are_normalized_without_renaming_employee(): void
    {
        $this->day->update(['employee_code' => '101', 'full_name' => 'عامر سالم جمعه']);
        [$token, $units, $html, $key] = $this->preview($this->workbook([['name' => 'اليومي', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'التاريخ', 'الحضور', 'الانصراف', 'الحالة'],
            ['١٠١.٠', 'عَامِر سـالم جمعة', '٢٠٢٦-١١-٢٠', '٠٩:٣٠', '٠٥:٠٠ م', 'متاخر'],
        ]]]));
        $this->assertStringContainsString('تمت مطابقة الاسم بعد توحيد الحروف والتشكيل', $html);
        $this->assertStringContainsString('2026-11-20', $html);
        $this->assertSame(0, Attendance::count());
        $this->import($token, $units[0], $key)->assertRedirect();
        $row = Attendance::where('employee_id', $this->day->id)->firstOrFail();
        $this->assertSame('09:30:00', $row->getRawOriginal('check_in'));
        $this->assertSame('17:00:00', $row->getRawOriginal('check_out'));
        $this->assertSame('Late', $row->status);
        $this->assertSame('عامر سالم جمعه', $this->day->fresh()->full_name);
    }

    public function test_unknown_attendance_status_is_rejected_in_preview_and_commit_not_saved_raw(): void
    {
        [$token, $units, $html, $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-20', '', '', 'حالة غريبة'],
        ])));
        $this->assertStringContainsString('حالة الحضور', $html);
        $this->assertStringContainsString('غير معروفة', $html);
        $this->import($token, $units[0], $key)->assertRedirect();
        $this->assertSame(0, Attendance::count());
    }

    public function test_status_only_aliases_map_to_existing_canonical_states(): void
    {
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-20', '', '', 'اجازه'],
            ['I1', '2026-11-21', '', '', 'غياب'],
            ['I1', '2026-11-22', '', '', 'حضور'],
        ])));
        $this->import($token, $units[0], $key)->assertRedirect();
        $this->assertSame(['Leave', 'Absent', 'Present'], Attendance::where('employee_id', $this->day->id)->orderBy('attendance_date')->pluck('status')->all());
    }

    public function test_normalized_code_collisions_are_rejected_and_leading_zero_codes_stay_distinct(): void
    {
        $this->day->update(['employee_code' => '101']);
        $this->night->update(['employee_code' => '101.0']);
        [$token, $units, $html, $key] = $this->preview($this->workbook($this->dailyRows([
            ['۱۰۱', '2026-11-20', '09:00', '17:00', ''],
        ])));
        $this->assertStringContainsString('أكثر من سجل بعد توحيد الأرقام', $html);
        $this->import($token, $units[0], $key)->assertRedirect();
        $this->assertSame(0, Attendance::count());
        $this->night->update(['employee_code' => '0101']);
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['٠١٠١', '2026-11-20', '22:00', '06:00', ''],
        ])));
        $this->import($token, $units[0], $key)->assertRedirect();
        $this->assertSame($this->night->id, Attendance::firstOrFail()->employee_id);
    }

    public function test_arabic_ambiguous_dates_still_require_explicit_format_and_invalid_times_are_rejected(): void
    {
        [$token, $units, $html, $key] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '٠٣/٠٤/٢٠٢٦', '09:00', '17:00', ''],
        ])));
        $this->assertStringContainsString('غامض', $html);
        $this->import($token, $units[0], $key)->assertStatus(422);
        $this->assertSame(0, Attendance::count());
        [, , $html] = $this->preview($this->workbook($this->dailyRows([
            ['I1', '2026-11-20', '٢٥:٠٠', '17:00', 'حاضر'],
        ])));
        $this->assertStringContainsString('توقيت حضور أو انصراف غير صالح', $html);
    }

    public function test_workbook_attendance_uses_the_same_normalization_and_never_saves_unknown_status(): void
    {
        $this->day->update(['employee_code' => '101', 'full_name' => 'أحمد مصطفى']);
        $file = $this->workbook([['name' => 'الحضور', 'rows' => [
            ['كود الموظف', 'اسم الموظف', 'التاريخ', 'وقت الحضور', 'وقت الانصراف', 'الحالة'],
            ['۱۰۱.۰', 'احمد مصطفي', '٢٠٢٦-١١-٢٠', '٠٩:٠٠', '٠٥:٠٠ م', 'حضور'],
            ['١٠١', 'أحمد مصطفى', '2026-11-21', '', '', 'راحة'],
            ['١٠١', 'شخص مختلف', '2026-11-22', '09:00', '17:00', 'حاضر'],
            ['١٠١', 'أحمد مصطفى', '2026-11-23', '٢٥:٠٠', '17:00', 'حاضر'],
        ]]]);
        $report = \App\Support\CompanyContext::run($this->company->id, fn () => app(\App\Services\Imports\WorkbookAttendanceImporter::class)->import($file->getRealPath(), 'xlsx', app(\App\Services\PayrollPeriodGuard::class), app(\App\Services\PayrollRunService::class)));
        $this->assertSame(1, $report['imported']);
        $this->assertSame(3, $report['skipped']);
        $this->assertCount(1, $report['warnings']);
        $this->assertSame('Present', Attendance::firstOrFail()->status);
        $this->assertCount(3, $report['failures']);
    }

    public function test_raw_punches_accept_arabic_digits_without_changing_shift_aggregation(): void
    {
        $this->day->update(['employee_code' => '101']);
        [$token, $units, , $key] = $this->preview($this->workbook([['name' => 'البصمات', 'rows' => [
            ['كود الموظف', 'التاريخ والوقت'],
            ['١٠١', '٢٠٢٦-١١-٢٠ ٠٩:٠٠'],
            ['۱۰۱', '٢٠٢٦-١١-٢٠ ١٧:٠٠'],
        ]]]));
        $this->import($token, $units[0], $key)->assertRedirect();
        $row = Attendance::where('employee_id', $this->day->id)->firstOrFail();
        $this->assertSame('09:00:00', $row->getRawOriginal('check_in'));
        $this->assertSame('17:00:00', $row->getRawOriginal('check_out'));
    }

    public function test_normalized_codes_preserve_case_insensitive_lookup_and_zero_is_a_valid_code(): void
    {
        $this->day->update(['employee_code' => 'HR-012']);
        $this->night->update(['employee_code' => '0']);
        [$token, $units, , $key] = $this->preview($this->workbook($this->dailyRows([
            ['hr-٠١٢', '2026-11-20', '09:00', '17:00', ''],
            ['٠', '2026-11-20', '22:00', '06:00', ''],
        ])));
        $this->import($token, $units[0], $key)->assertRedirect();
        $this->assertSame(2, Attendance::count());
    }
}
