<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Permanently erases one company and everything it owns: database rows and
 * uploaded files, as if the company never existed. Used only by the Owner page.
 *
 * Safety design:
 *  - Every table in the database must be classified below. A table nobody
 *    classified (for example one added by a later migration) makes the erase
 *    refuse to run (fail closed) instead of guessing.
 *  - Rows are matched by explicit ownership rules, never by a blanket
 *    "company_id" sweep, and rows with company_id NULL (shared defaults) or
 *    another company's id are never touched.
 *  - All database deletes run in one transaction. Files are removed only after
 *    the commit; the list of files is written to a manifest first so a failed
 *    file removal can be retried without the database rows.
 */
class CompanyErasureService
{
    public const MANIFEST_DIR = 'erasure-pending';

    /** Tables holding rows owned by a company, matched by an explicit rule in steps(). */
    private const OWNED = [
        'activity_logs', 'attendance', 'attendance_import_batches', 'attendance_import_days',
        'attendance_penalty_policies', 'attendance_penalty_tiers', 'attendance_period_archives',
        'attendance_punch_revisions', 'banks', 'branches', 'company_subscriptions', 'contact_messages',
        'departments', 'document_expiry_notifications', 'document_types', 'documents',
        'employee_bank_accounts', 'employee_emergency_contacts', 'employee_import_batches',
        'employee_import_row_revisions', 'employee_import_rows', 'employee_profile_details', 'employees',
        'employment_types', 'fingerprint_device_commands', 'fingerprint_device_sync_logs', 'fingerprint_devices',
        'hr_holidays', 'hr_jobs', 'hr_operational_settings', 'hr_requests', 'import_column_aliases',
        'insurance_organization_settings', 'insurance_record_history', 'insurance_records', 'leave_balances',
        'leave_opening_entries', 'leave_policy_settings', 'leave_type_company_limits', 'leave_types', 'leaves',
        'monthly_summary_snapshots', 'movement_records', 'movement_types', 'notifications', 'payment_methods',
        'payroll_allowance_settings', 'payroll_calculation_rules', 'payroll_calculation_settings',
        'payroll_deductions', 'payroll_records', 'payroll_runs', 'payroll_setting_changes', 'penalties',
        'penalty_types', 'raw_attendance_logs', 'request_types', 'role_permissions', 'roles', 'salaries',
        'sessions', 'settlements', 'subscription_renewal_requests', 'tax_brackets', 'tax_categories',
        'tax_reconciliations', 'user_permissions', 'users', 'password_reset_tokens',
    ];

    /** Shared or system tables: never deleted by company erasure. */
    private const SHARED = [
        'companies_placeholder_never_matches', 'employee_statuses', 'migrations', 'permissions', 'subscription_plans', 'tour_steps',
        // Found on the hosted database only (no repo migration creates them): global lookup/settings tables
        // with no company_id and no employee link. assertSchemaClassified() re-checks that on every run.
        'insurance_job_codes', 'system_settings',
    ];

    /**
     * Tables that exist on some databases only (hosted legacy). Owned through employees / payroll records
     * (overtime_records, payroll_earnings) or company_id (queue_jobs). Skipped where the table does not exist.
     */
    private const OPTIONAL_OWNED = ['overtime_records', 'payroll_earnings', 'queue_jobs'];

    /**
     * Queue storage. Job payloads are serialized objects with no reliable company marker, so rows cannot be
     * attributed to a company. A company erase is refused while any unattributable row exists, instead of
     * deleting another tenant's jobs or leaving this company's payload behind.
     */
    private const QUEUE = ['failed_jobs', 'jobs', 'job_batches'];

    /** Classified tables whose rows are mapped to a company through a parent table, not company_id. */
    private const CHILD = [
        'document_expiry_notifications', 'fingerprint_device_commands', 'fingerprint_device_sync_logs',
        'insurance_record_history', 'leave_balances', 'notifications', 'payroll_deductions',
        'raw_attendance_logs', 'role_permissions', 'sessions', 'tax_brackets', 'tax_reconciliations',
        'user_permissions', 'password_reset_tokens',
    ];

    /** Throws when the live schema contains a table this service does not know. */
    public function assertSchemaClassified(): void
    {
        $known = array_merge(self::OWNED, self::OPTIONAL_OWNED, self::QUEUE, self::SHARED, ['companies']);
        $unknown = array_values(array_diff($this->liveTables(), $known));
        if ($unknown !== []) {
            throw new RuntimeException('Company erasure refused: unclassified tables: ' . implode(', ', $unknown));
        }
        foreach ($this->liveTables() as $table) {
            if ($table !== 'companies' && Schema::hasColumn($table, 'company_id') && !in_array($table, array_merge(self::OWNED, self::OPTIONAL_OWNED), true)) {
                throw new RuntimeException("Company erasure refused: table {$table} has company_id but is not mapped.");
            }
        }
        // A table classified as shared must really be shared: no company column.
        foreach (self::SHARED as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'company_id')) {
                throw new RuntimeException("Company erasure refused: shared table {$table} has a company_id column.");
            }
        }
        foreach (['overtime_records', 'payroll_earnings'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'employee_id')) {
                throw new RuntimeException("Company erasure refused: table {$table} has no employee_id to attribute rows.");
            }
        }
        // Queue rows cannot be attributed to a company: refuse while any exist.
        foreach (self::QUEUE as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Company erasure refused: queue table {$table} is not empty. Clear queued and failed jobs first.");
            }
        }
        if (Schema::hasTable('queue_jobs') && Schema::hasColumn('queue_jobs', 'payload')) {
            $q = DB::table('queue_jobs')->whereNotNull('payload')->where('payload', '!=', '')->whereNull('company_id');
            if ($q->exists()) {
                throw new RuntimeException('Company erasure refused: queue_jobs holds queued payload rows that belong to no company. Clear them first.');
            }
        }
    }

    /** @return string[] */
    public function liveTables(): array
    {
        $db = DB::connection()->getDatabaseName();
        return array_map(fn ($r) => $r->t, DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = ?', [$db, 'BASE TABLE']));
    }

    /**
     * Ordered delete steps: [label, table, callable receiving a query builder scoped to that table]
     * Children first so foreign keys are satisfied.
     */
    private function steps(int $c): array
    {
        $emp = fn () => DB::table('employees')->where('company_id', $c)->select('id');
        $usr = fn () => DB::table('users')->where('company_id', $c)->select('id');
        $dev = fn () => DB::table('fingerprint_devices')->where('company_id', $c)->select('id');
        $rec = fn () => DB::table('payroll_records')->where('company_id', $c)->orWhereIn('employee_id', $emp())->select('id');
        $doc = fn () => DB::table('documents')->where('company_id', $c)->orWhereIn('employee_id', $emp())->select('id');
        $ins = fn () => DB::table('insurance_records')->where('company_id', $c)->orWhereIn('employee_id', $emp())->select('id');
        $tax = fn () => DB::table('tax_categories')->where('company_id', $c)->select('id');
        $own = fn ($q) => $q->where('company_id', $c);
        $ownOrEmp = fn ($q) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereIn('employee_id', $emp()));

        $byEmpOrRec = fn ($q) => $q->where(fn ($w) => $w->whereIn('employee_id', $emp())->orWhereIn('payroll_record_id', $rec()));

        return [
            ['overtime_records', $byEmpOrRec],
            ['payroll_earnings', $byEmpOrRec],
            ['queue_jobs', $own],
            ['attendance_punch_revisions', $own],
            ['attendance_import_days', $own],
            ['attendance_import_batches', $own],
            ['attendance', $ownOrEmp],
            ['attendance_period_archives', $own],
            ['attendance_penalty_tiers', $own],
            ['attendance_penalty_policies', $own],
            ['raw_attendance_logs', fn ($q) => $q->where(fn ($w) => $w->whereIn('employee_id', $emp())->orWhereIn('fingerprint_device_id', $dev()))],
            ['fingerprint_device_commands', fn ($q) => $q->whereIn('device_id', $dev())],
            ['fingerprint_device_sync_logs', fn ($q) => $q->whereIn('device_id', $dev())],
            ['fingerprint_devices', $own],
            ['employee_import_row_revisions', $own],
            ['employee_import_rows', $own],
            ['employee_import_batches', $own],
            ['payroll_deductions', fn ($q) => $q->whereIn('payroll_record_id', $rec())],
            ['settlements', $ownOrEmp],
            ['payroll_records', $ownOrEmp],
            ['payroll_runs', $own],
            ['payroll_setting_changes', $own],
            ['payroll_calculation_rules', $own],
            ['payroll_calculation_settings', $own],
            ['payroll_allowance_settings', $own],
            ['salaries', $ownOrEmp],
            ['tax_brackets', fn ($q) => $q->whereIn('tax_category_id', $tax())],
            ['tax_categories', $own],
            ['tax_reconciliations', fn ($q) => $q->whereIn('employee_id', $emp())],
            ['leave_balances', fn ($q) => $q->whereIn('employee_id', $emp())],
            ['leave_opening_entries', $ownOrEmp],
            ['leave_type_company_limits', $own],
            ['leaves', $ownOrEmp],
            ['leave_policy_settings', $own],
            ['leave_types', $own],
            ['penalties', $ownOrEmp],
            ['penalty_types', $own],
            ['movement_records', $ownOrEmp],
            ['movement_types', $own],
            ['hr_requests', $ownOrEmp],
            ['request_types', $own],
            ['document_expiry_notifications', fn ($q) => $q->whereIn('document_id', $doc())],
            ['documents', $ownOrEmp],
            ['document_types', $own],
            ['employee_bank_accounts', $ownOrEmp],
            ['employee_emergency_contacts', $ownOrEmp],
            ['employee_profile_details', $ownOrEmp],
            ['insurance_record_history', fn ($q) => $q->where(fn ($w) => $w->whereIn('employee_id', $emp())->orWhereIn('insurance_record_id', $ins()))],
            ['insurance_records', $ownOrEmp],
            ['insurance_organization_settings', $own],
            ['banks', $own],
            ['payment_methods', $own],
            ['hr_holidays', $own],
            ['hr_operational_settings', $own],
            ['import_column_aliases', $own],
            ['monthly_summary_snapshots', $own],
            ['contact_messages', $own],
            ['subscription_renewal_requests', $own],
            ['company_subscriptions', $own],
            ['activity_logs', $own],
            ['notifications', fn ($q) => $q->where('notifiable_type', \App\Models\User::class)->whereIn('notifiable_id', $usr())],
            ['sessions', fn ($q) => $q->whereIn('user_id', $usr())],
            ['password_reset_tokens', fn ($q) => $q->whereIn('email', DB::table('users')->where('company_id', $c)->pluck('email')->all())],
            ['user_permissions', fn ($q) => $q->whereIn('user_id', $usr())],
            ['users', $own],
            ['role_permissions', fn ($q) => $q->whereIn('role_id', DB::table('roles')->where('company_id', $c)->select('id'))],
            ['roles', $own],
            ['employees', $own],
            ['hr_jobs', $own],
            ['departments', $own],
            ['branches', $own],
            ['employment_types', $own],
        ];
    }

    /** Row counts per table that an erase would remove right now (read-only). */
    public function preview(int $companyId): array
    {
        $this->assertSchemaClassified();
        $counts = [];
        foreach ($this->steps($companyId) as [$table, $scope]) {
            if (!Schema::hasTable($table)) continue;
            $n = $scope(DB::table($table))->count();
            if ($n > 0) $counts[$table] = $n;
        }
        return $counts + ['files' => count($this->collectFiles($companyId))];
    }

    /**
     * Files owned by the company: exact paths stored in the database, the per-employee
     * folders, and temporary import files whose metadata names this company.
     * @return array<int, array{disk:string,type:string,path:string}>
     */
    public function collectFiles(int $c): array
    {
        $files = [];
        $add = function (string $path, string $type = 'file') use (&$files) {
            $path = ltrim(str_replace('\\', '/', $path), '/');
            if ($path === '' || str_contains($path, '..')) return;
            foreach (['local', 'public'] as $disk) $files[$disk . '|' . $type . '|' . $path] = ['disk' => $disk, 'type' => $type, 'path' => $path];
        };
        $empIds = DB::table('employees')->where('company_id', $c)->pluck('id');
        foreach (DB::table('employees')->where('company_id', $c)->whereNotNull('profile_photo_path')->pluck('profile_photo_path') as $p) $add($p);
        foreach (DB::table('documents')->where(fn ($w) => $w->where('company_id', $c)->orWhereIn('employee_id', $empIds))->whereNotNull('file_path')->pluck('file_path') as $p) $add($p);
        foreach (DB::table('leaves')->where(fn ($w) => $w->where('company_id', $c)->orWhereIn('employee_id', $empIds))->whereNotNull('document_path')->pluck('document_path') as $p) $add($p);
        foreach (DB::table('movement_records')->where(fn ($w) => $w->where('company_id', $c)->orWhereIn('employee_id', $empIds))->whereNotNull('attachment_path')->pluck('attachment_path') as $p) $add($p);
        foreach ($empIds as $id) {
            foreach (["employees/{$id}", "documents/{$id}", "leave-documents/{$id}"] as $dir) $add($dir, 'dir');
        }
        // Temporary attendance import files: metadata json names the company.
        $local = Storage::disk('local');
        foreach ($local->files('imports') as $file) {
            if (!str_ends_with($file, '.json')) continue;
            $meta = json_decode((string) $local->get($file), true);
            if (is_array($meta) && (int) ($meta['company_id'] ?? 0) === $c) {
                $token = pathinfo($file, PATHINFO_FILENAME);
                foreach ($local->files('imports') as $candidate) {
                    if (str_starts_with(basename($candidate), $token . '.')) $files['local|file|' . $candidate] = ['disk' => 'local', 'type' => 'file', 'path' => $candidate];
                }
            }
        }
        // Keep only what physically exists, so previews and manifests list real files.
        return array_values(array_filter($files, function ($f) {
            $disk = Storage::disk($f['disk']);
            return $f['type'] === 'dir' ? $disk->directoryExists($f['path']) : $disk->exists($f['path']);
        }));
    }

    /**
     * Erase the company. Returns a result array; file failures leave a manifest for retry.
     * @return array{rows:int,files_removed:int,files_failed:int,manifest:?string}
     */
    public function erase(int $companyId): array
    {
        $this->assertSchemaClassified();
        if (!DB::table('companies')->where('id', $companyId)->exists()) {
            throw new RuntimeException('Company not found.');
        }
        $files = $this->collectFiles($companyId);
        $manifest = null;
        if ($files !== []) {
            $manifest = self::MANIFEST_DIR . '/' . Str::uuid() . '.json';
            Storage::disk('local')->put($manifest, json_encode($files));
        }
        $rows = 0;
        try {
            DB::transaction(function () use ($companyId, &$rows) {
                foreach ($this->steps($companyId) as [$table, $scope]) {
                    if (!Schema::hasTable($table)) continue;
                    $rows += $scope(DB::table($table))->delete();
                }
                $rows += DB::table('companies')->where('id', $companyId)->delete();
                $left = $this->remainingReferences($companyId);
                if ($left !== []) {
                    throw new RuntimeException('Company erasure rolled back: rows still reference the company in: ' . implode(', ', $left));
                }
            });
        } catch (\Throwable $e) {
            if ($manifest) Storage::disk('local')->delete($manifest);
            throw $e;
        }
        $result = $this->removeFiles($files);
        if ($result['failed'] === 0 && $manifest) {
            Storage::disk('local')->delete($manifest);
            $manifest = null;
        }
        return ['rows' => $rows, 'files_removed' => $result['removed'], 'files_failed' => $result['failed'], 'manifest' => $manifest];
    }

    /** Tables that still hold a row pointing at the (deleted) company id. */
    private function remainingReferences(int $c): array
    {
        $left = [];
        foreach ($this->liveTables() as $table) {
            if ($table !== 'companies' && Schema::hasColumn($table, 'company_id') && DB::table($table)->where('company_id', $c)->exists()) $left[] = $table;
        }
        return $left;
    }

    /** @return array{removed:int,failed:int} */
    private function removeFiles(array $files): array
    {
        $removed = 0; $failed = 0;
        foreach ($files as $f) {
            $disk = Storage::disk($f['disk']);
            try {
                if ($f['type'] === 'dir') {
                    if ($disk->directoryExists($f['path'])) { $ok = $disk->deleteDirectory($f['path']); } else { $ok = true; }
                } else {
                    $ok = $disk->exists($f['path']) ? $disk->delete($f['path']) : true;
                }
            } catch (\Throwable) { $ok = false; }
            $ok ? $removed++ : $failed++;
        }
        return ['removed' => $removed, 'failed' => $failed];
    }

    /** Retry file removal for manifests left behind by a failed cleanup. Returns manifests still pending. */
    public function retryPendingFiles(): int
    {
        $local = Storage::disk('local');
        $pending = 0;
        foreach ($local->files(self::MANIFEST_DIR) as $manifest) {
            $files = json_decode((string) $local->get($manifest), true);
            if (!is_array($files)) { $pending++; continue; }
            $r = $this->removeFiles($files);
            if ($r['failed'] === 0) $local->delete($manifest); else $pending++;
        }
        return $pending;
    }

    public function pendingManifestCount(): int
    {
        return count(Storage::disk('local')->files(self::MANIFEST_DIR));
    }
}
