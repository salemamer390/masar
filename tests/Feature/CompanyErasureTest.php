<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\EmployeeStatus;
use App\Services\CompanyErasureService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Destructive-path isolation proof on disposable fixtures: two companies, one erased.
 */
class CompanyErasureTest extends TestCase
{
    use DatabaseTransactions;

    /** Insert a row, auto-filling any NOT NULL column without a default. */
    private function row(string $table, array $values): int
    {
        $db = DB::connection()->getDatabaseName();
        $cols = DB::select('SELECT column_name AS n, data_type AS t, is_nullable AS nl, column_default AS d, extra AS x FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$db, $table]);
        foreach ($cols as $c) {
            if (array_key_exists($c->n, $values) || $c->nl === 'YES' || $c->d !== null || str_contains((string) $c->x, 'auto_increment') || str_contains((string) $c->x, 'GENERATED')) continue;
            $values[$c->n] = match (true) {
                in_array($c->t, ['int', 'bigint', 'smallint', 'tinyint', 'decimal', 'double', 'float']) => 1,
                in_array($c->t, ['date']) => '2026-01-01',
                in_array($c->t, ['datetime', 'timestamp']) => '2026-01-01 00:00:00',
                $c->t === 'json' => '{}',
                default => 'x' . uniqid(),
            };
        }
        return (int) DB::table($table)->insertGetId($values);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function makeCompany(string $tag): array
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $company = Company::create(['name' => "Erase Co $tag", 'slug' => 'erase-' . $tag . uniqid(), 'email' => "$tag@erase.test", 'phone' => '0100000000' . strlen($tag)]);
        $c = $company->id;
        $role = $this->row('roles', ['company_id' => $c, 'name' => 'company_manager', 'display_name' => "M $tag"]);
        $user = $this->row('users', ['company_id' => $c, 'role_id' => $role, 'name' => "U $tag", 'email' => "u-$tag-" . uniqid() . '@erase.test', 'password' => 'x']);
        $emp = $this->row('employees', ['company_id' => $c, 'status_id' => $status->id, 'employee_code' => "E$tag" . uniqid(), 'first_name' => $tag, 'full_name' => "Emp $tag",
            'profile_photo_path' => "employees/PLACEHOLDER/profile/{$tag}.jpg"]);
        DB::table('employees')->where('id', $emp)->update(['profile_photo_path' => "employees/$emp/profile/photo.jpg"]);
        $dev = $this->row('fingerprint_devices', ['company_id' => $c]);
        $cat = $this->row('tax_categories', ['company_id' => $c]);
        $run = $this->row('payroll_runs', ['company_id' => $c]);
        $rec = $this->row('payroll_records', ['company_id' => $c, 'employee_id' => $emp, 'payroll_run_id' => $run]);
        $lt = $this->row('leave_types', ['company_id' => $c]);
        $doc = $this->row('documents', ['company_id' => $c, 'employee_id' => $emp, 'file_path' => "documents/$emp/passport.pdf", 'file_name' => 'passport.pdf']);
        $ids = [
            'company' => $c, 'role' => $role, 'user' => $user, 'employee' => $emp, 'device' => $dev, 'category' => $cat, 'run' => $run, 'record' => $rec, 'doc' => $doc,
            'bracket' => $this->row('tax_brackets', ['tax_category_id' => $cat]),
            'deduction' => $this->row('payroll_deductions', ['payroll_record_id' => $rec]),
            'attendance' => $this->row('attendance', ['company_id' => $c, 'employee_id' => $emp, 'attendance_date' => '2026-01-01']),
            'raw_log' => $this->row('raw_attendance_logs', ['employee_id' => $emp, 'fingerprint_device_id' => $dev]),
            'salary' => $this->row('salaries', ['company_id' => $c, 'employee_id' => $emp]),
            'leave' => $this->row('leaves', ['company_id' => $c, 'employee_id' => $emp, 'leave_type_id' => $lt, 'document_path' => "leave-documents/$emp/l.pdf"]),
            'leave_type' => $lt,
            'bank' => $this->row('banks', ['company_id' => $c]),
            'activity' => $this->row('activity_logs', ['company_id' => $c]),
            'expiry' => $this->row('document_expiry_notifications', ['document_id' => $doc]),
            'sub' => $this->row('company_subscriptions', ['company_id' => $c, 'subscription_plan_id' => $this->plan()]),
            'session' => 0,
        ];
        DB::table('sessions')->insert(['id' => "s-$tag-" . uniqid(), 'user_id' => $user, 'payload' => 'x', 'last_activity' => time()]);
        DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_type' => \App\Models\User::class, 'notifiable_id' => $user, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        foreach (["employees/$emp/profile/photo.jpg", "documents/$emp/passport.pdf", "leave-documents/$emp/l.pdf", "employees/$emp/extra/other.png"] as $f) {
            Storage::disk('local')->put($f, 'bytes-' . $tag);
        }
        Storage::disk('public')->put("employees/$emp/legacy.jpg", 'legacy-' . $tag);
        Storage::disk('local')->put("imports/tok-$tag.json", json_encode(['company_id' => $c, 'created_at' => now()->toIso8601String()]));
        Storage::disk('local')->put("imports/tok-$tag.xlsx", 'xl');
        return $ids;
    }

    private function plan(): int
    {
        return DB::table('subscription_plans')->where('slug', 'erase-test-plan')->value('id')
            ?? $this->row('subscription_plans', ['slug' => 'erase-test-plan', 'name' => 'Erase test plan', 'employee_limit' => 10]);
    }

    private function snapshot(array $ids): array
    {
        $out = [];
        foreach ([
            'companies' => 'company', 'roles' => 'role', 'users' => 'user', 'employees' => 'employee', 'fingerprint_devices' => 'device',
            'tax_categories' => 'category', 'payroll_runs' => 'run', 'payroll_records' => 'record', 'documents' => 'doc', 'tax_brackets' => 'bracket',
            'payroll_deductions' => 'deduction', 'attendance' => 'attendance', 'raw_attendance_logs' => 'raw_log', 'salaries' => 'salary',
            'leaves' => 'leave', 'leave_types' => 'leave_type', 'banks' => 'bank', 'activity_logs' => 'activity', 'document_expiry_notifications' => 'expiry',
            'company_subscriptions' => 'sub',
        ] as $table => $key) {
            $out[$table] = DB::table($table)->where('id', $ids[$key])->exists();
        }
        $out['sessions'] = DB::table('sessions')->where('user_id', $ids['user'])->exists();
        $out['notifications'] = DB::table('notifications')->where('notifiable_id', $ids['user'])->exists();
        return $out;
    }

    public function test_erase_removes_only_the_selected_company_rows_and_files(): void
    {
        $sharedBank = $this->row('banks', ['company_id' => null]);
        $sharedType = $this->row('leave_types', ['company_id' => null]);
        $a = $this->makeCompany('a');
        $b = $this->makeCompany('b');
        // Snapshot shared tables after fixtures exist: the fixture itself may create the shared test plan on a fresh DB.
        $shared = ['plans' => DB::table('subscription_plans')->count(), 'perms' => DB::table('permissions')->count(), 'statuses' => DB::table('employee_statuses')->count()];
        $before = $this->snapshot($b);
        $this->assertNotContains(false, $before);
        $companiesBefore = DB::table('companies')->count();

        $preview = app(CompanyErasureService::class)->preview($a['company']);
        $this->assertSame(1, $preview['employees']);
        $this->assertGreaterThanOrEqual(5, $preview['files']);
        $this->assertSame($companiesBefore, DB::table('companies')->count(), 'preview must not delete');

        $result = app(CompanyErasureService::class)->erase($a['company']);
        $this->assertSame(0, $result['files_failed']);
        $this->assertNull($result['manifest']);

        // Company A: every row and file gone.
        $after = $this->snapshot($a);
        $this->assertNotContains(true, $after, 'company A rows left: ' . json_encode(array_keys(array_filter($after))));
        foreach (['company_subscriptions', 'activity_logs', 'attendance', 'salaries', 'leaves', 'roles', 'users', 'employees'] as $t) {
            if (\Schema::hasColumn($t, 'company_id')) $this->assertSame(0, DB::table($t)->where('company_id', $a['company'])->count(), $t);
        }
        foreach (["employees/{$a['employee']}/profile/photo.jpg", "documents/{$a['employee']}/passport.pdf", "leave-documents/{$a['employee']}/l.pdf", "employees/{$a['employee']}/extra/other.png", 'imports/tok-a.json', 'imports/tok-a.xlsx'] as $f) {
            Storage::disk('local')->assertMissing($f);
        }
        Storage::disk('public')->assertMissing("employees/{$a['employee']}/legacy.jpg");
        $this->assertSame([], Storage::disk('local')->files(CompanyErasureService::MANIFEST_DIR));

        // Company B and shared records: untouched.
        $this->assertSame($before, $this->snapshot($b));
        foreach (["employees/{$b['employee']}/profile/photo.jpg", "documents/{$b['employee']}/passport.pdf", "leave-documents/{$b['employee']}/l.pdf", "employees/{$b['employee']}/extra/other.png", 'imports/tok-b.json', 'imports/tok-b.xlsx'] as $f) {
            Storage::disk('local')->assertExists($f);
        }
        Storage::disk('public')->assertExists("employees/{$b['employee']}/legacy.jpg");
        $this->assertTrue(DB::table('banks')->where('id', $sharedBank)->exists());
        $this->assertTrue(DB::table('leave_types')->where('id', $sharedType)->exists());
        $this->assertSame($shared, ['plans' => DB::table('subscription_plans')->count(), 'perms' => DB::table('permissions')->count(), 'statuses' => DB::table('employee_statuses')->count()]);
        $this->assertSame($companiesBefore - 1, DB::table('companies')->count());
    }

    public function test_failed_database_delete_rolls_back_and_keeps_files(): void
    {
        $a = $this->makeCompany('c');
        $other = Company::create(['name' => 'Blocker', 'slug' => 'blocker' . uniqid()]);
        // A foreign row from ANOTHER company pointing at company A's leave type blocks the delete (NO ACTION foreign key).
        $oe = $this->row('employees', ['company_id' => $other->id, 'status_id' => EmployeeStatus::firstOrCreate(['name' => 'Active'])->id, 'employee_code' => 'OB' . uniqid(), 'first_name' => 'o', 'full_name' => 'Other']);
        $this->row('leaves', ['company_id' => $other->id, 'employee_id' => $oe, 'leave_type_id' => $a['leave_type']]);
        try {
            app(CompanyErasureService::class)->erase($a['company']);
            $this->fail('erase should have been blocked');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('failed to assert', $e->getMessage());
        }
        $this->assertTrue(DB::table('companies')->where('id', $a['company'])->exists());
        $this->assertTrue(DB::table('employees')->where('id', $a['employee'])->exists());
        Storage::disk('local')->assertExists("employees/{$a['employee']}/profile/photo.jpg");
        $this->assertSame([], Storage::disk('local')->files(CompanyErasureService::MANIFEST_DIR));
    }

    public function test_unclassified_table_makes_erase_refuse(): void
    {
        DB::statement('CREATE TABLE zz_unmapped_probe (id INT PRIMARY KEY, company_id INT NULL)');
        try {
            $a = $this->makeCompany('d');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('unclassified');
            app(CompanyErasureService::class)->erase($a['company']);
        } finally {
            DB::statement('DROP TABLE IF EXISTS zz_unmapped_probe');
        }
    }

    public function test_file_cleanup_failure_leaves_retry_manifest_and_retry_clears_it(): void
    {
        $a = $this->makeCompany('e');
        $path = Storage::disk('local')->path("employees/{$a['employee']}/profile/photo.jpg");
        // Make the photo undeletable by swapping its parent directory permissions.
        chmod(dirname($path), 0555);
        try {
            if (is_writable(dirname($path))) $this->markTestSkipped('Running as a user that ignores directory permissions.');
            $result = app(CompanyErasureService::class)->erase($a['company']);
            $this->assertGreaterThan(0, $result['files_failed']);
            $this->assertNotNull($result['manifest']);
            $this->assertFalse(DB::table('companies')->where('id', $a['company'])->exists());
            $this->assertSame(1, app(CompanyErasureService::class)->pendingManifestCount());
        } finally {
            chmod(dirname($path), 0755);
        }
        $this->assertSame(0, app(CompanyErasureService::class)->retryPendingFiles());
        Storage::disk('local')->assertMissing("employees/{$a['employee']}/profile/photo.jpg");
        $this->assertSame(0, app(CompanyErasureService::class)->pendingManifestCount());
    }

    private const HOSTED_ONLY = ['failed_jobs', 'insurance_job_codes', 'overtime_records', 'payroll_earnings', 'queue_jobs', 'system_settings'];

    /** Create the six tables found only on the hosted database (structures as read from phpMyAdmin), outside the test transaction. */
    private function createHostedOnlyTables(): void
    {
        DB::rollBack();
        foreach (self::HOSTED_ONLY as $t) DB::statement("DROP TABLE IF EXISTS $t");
        DB::statement('CREATE TABLE failed_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, uuid VARCHAR(255), connection TEXT, queue TEXT, payload LONGTEXT, exception LONGTEXT, failed_at TIMESTAMP NULL)');
        DB::statement('CREATE TABLE insurance_job_codes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50), title VARCHAR(255), is_active TINYINT(1), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        DB::statement('CREATE TABLE overtime_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_id BIGINT UNSIGNED, payroll_record_id BIGINT UNSIGNED NULL, overtime_date DATE, hours DECIMAL(10,2), amount DECIMAL(12,2), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        DB::statement('CREATE TABLE payroll_earnings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payroll_record_id BIGINT UNSIGNED, employee_id BIGINT UNSIGNED, earning_type VARCHAR(100), amount DECIMAL(12,2), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        DB::statement('CREATE TABLE queue_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NULL, title VARCHAR(150), job_code VARCHAR(50), is_active TINYINT(1), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, queue VARCHAR(255) NULL, payload LONGTEXT NULL, attempts TINYINT UNSIGNED NULL, reserved_at INT UNSIGNED NULL, available_at INT UNSIGNED NULL)');
        DB::statement('CREATE TABLE system_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, setting_key VARCHAR(255), setting_value TEXT, setting_group VARCHAR(100), label VARCHAR(255), is_public TINYINT(1), created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)');
        DB::beginTransaction();
    }

    private function dropHostedOnlyTables(): void
    {
        DB::rollBack();
        foreach (self::HOSTED_ONLY as $t) DB::statement("DROP TABLE IF EXISTS $t");
        DB::beginTransaction();
    }

    public function test_hosted_only_tables_are_owned_or_shared_and_erase_only_the_target_company(): void
    {
        $this->createHostedOnlyTables();
        try {
            $a = $this->makeCompany('ha');
            $b = $this->makeCompany('hb');
            foreach ([$a, $b] as $x) {
                DB::table('overtime_records')->insert(['employee_id' => $x['employee'], 'payroll_record_id' => $x['record'], 'overtime_date' => '2026-01-01', 'hours' => 1, 'amount' => 10]);
                DB::table('payroll_earnings')->insert(['employee_id' => $x['employee'], 'payroll_record_id' => $x['record'], 'earning_type' => 'bonus', 'amount' => 5]);
                DB::table('queue_jobs')->insert(['company_id' => $x['company'], 'title' => 'Job ' . $x['company'], 'job_code' => 'J' . $x['company'], 'is_active' => 1]);
            }
            DB::table('insurance_job_codes')->insert(['code' => 'C1', 'title' => 'shared', 'is_active' => 1]);
            DB::table('system_settings')->insert(['setting_key' => 'k', 'setting_value' => 'v', 'setting_group' => 'g', 'label' => 'l', 'is_public' => 0]);
            $svc = app(CompanyErasureService::class);
            $svc->assertSchemaClassified();
            $counts = $svc->preview($a['company']);
            $this->assertSame(1, $counts['overtime_records']);
            $this->assertSame(1, $counts['payroll_earnings']);
            $this->assertSame(1, $counts['queue_jobs']);

            $svc->erase($a['company']);

            $this->assertSame(0, DB::table('overtime_records')->where('employee_id', $a['employee'])->count());
            $this->assertSame(0, DB::table('payroll_earnings')->where('employee_id', $a['employee'])->count());
            $this->assertSame(0, DB::table('queue_jobs')->where('company_id', $a['company'])->count());
            $this->assertSame(1, DB::table('overtime_records')->where('employee_id', $b['employee'])->count());
            $this->assertSame(1, DB::table('payroll_earnings')->where('employee_id', $b['employee'])->count());
            $this->assertSame(1, DB::table('queue_jobs')->where('company_id', $b['company'])->count());
            $this->assertSame(1, DB::table('insurance_job_codes')->count());
            $this->assertSame(1, DB::table('system_settings')->count());
            $this->assertTrue(DB::table('companies')->where('id', $b['company'])->exists());
        } finally {
            $this->dropHostedOnlyTables();
        }
    }

    public function test_non_empty_failed_jobs_or_unattributable_queue_rows_make_erase_refuse_and_delete_nothing(): void
    {
        $this->createHostedOnlyTables();
        try {
            $a = $this->makeCompany('hc');
            DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x']);
            try {
                app(CompanyErasureService::class)->erase($a['company']);
                $this->fail('failed_jobs row must block erase');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('failed_jobs', $e->getMessage());
            }
            $this->assertTrue(DB::table('companies')->where('id', $a['company'])->exists());
            $this->assertSame(1, DB::table('employees')->where('company_id', $a['company'])->count());
            DB::table('failed_jobs')->delete();

            DB::table('queue_jobs')->insert(['company_id' => null, 'title' => 'q', 'job_code' => 'q', 'is_active' => 1, 'queue' => 'default', 'payload' => '{"job":"x"}']);
            try {
                app(CompanyErasureService::class)->erase($a['company']);
                $this->fail('unattributable queue payload must block erase');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('queue_jobs', $e->getMessage());
            }
            $this->assertTrue(DB::table('companies')->where('id', $a['company'])->exists());
            $this->assertSame(1, DB::table('queue_jobs')->count());
        } finally {
            $this->dropHostedOnlyTables();
        }
    }

    public function test_shared_table_that_gains_a_company_column_makes_erase_refuse(): void
    {
        $this->createHostedOnlyTables();
        DB::rollBack();
        DB::statement('ALTER TABLE system_settings ADD COLUMN company_id BIGINT UNSIGNED NULL');
        DB::beginTransaction();
        try {
            $a = $this->makeCompany('hd');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('system_settings has company_id');
            app(CompanyErasureService::class)->erase($a['company']);
        } finally {
            $this->dropHostedOnlyTables();
        }
    }
}
