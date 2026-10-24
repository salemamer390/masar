<?php

use App\Services\DefaultCompanyRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fixes found in the payroll audit:
 *  1. The "unpaid leave" type was flagged as paid, so unpaid leave was never deducted.
 *  2. Attendance no longer has an approval step: old Draft rows become Approved.
 *  3. Draft payroll records that sat in a run without a company are moved to their own company's run.
 *  4. New permission payroll.delete (deleting a payroll run) and the default role catalogue
 *     for every company that is missing some of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')->where('policy_code', 'unpaid')->update(['is_paid' => 0]);

        DB::table('attendance')->where('approval_status', 'Draft')->update(['approval_status' => 'Approved']);

        $this->moveOrphanRecords();

        if (!DB::table('permissions')->where('name', 'payroll.delete')->exists()) {
            DB::table('permissions')->insert([
                'name' => 'payroll.delete', 'display_name' => 'Delete Payroll Runs', 'module' => 'payroll', 'action' => 'delete',
            ]);
        }

        $roles = app(DefaultCompanyRoles::class);
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $roles->ensureForCompany((int) $companyId, false);
        }
    }

    private function moveOrphanRecords(): void
    {
        $orphanRuns = DB::table('payroll_runs')->whereNull('company_id')->get();
        foreach ($orphanRuns as $orphan) {
            $records = DB::table('payroll_records')->where('payroll_run_id', $orphan->id)->get(['id', 'company_id', 'payroll_month']);
            foreach ($records as $record) {
                if ($record->company_id === null) continue;

                $target = DB::table('payroll_runs')
                    ->where('company_id', $record->company_id)
                    ->whereDate('payroll_month', $record->payroll_month)
                    ->first();
                if (!$target) {
                    $targetId = DB::table('payroll_runs')->insertGetId([
                        'company_id' => $record->company_id,
                        'payroll_month' => $orphan->payroll_month,
                        'status' => 'Draft',
                        'created_by' => $orphan->created_by,
                        'settings_snapshot' => $orphan->settings_snapshot,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } elseif (in_array($target->status, ['Approved', 'Locked'], true)) {
                    continue; // never push a record into a finalized run
                } else {
                    $targetId = $target->id;
                }

                DB::table('payroll_records')->where('id', $record->id)->update(['payroll_run_id' => $targetId]);
            }

            if ($orphan->status === 'Draft' && DB::table('payroll_records')->where('payroll_run_id', $orphan->id)->doesntExist()) {
                DB::table('payroll_runs')->where('id', $orphan->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Data fixes are not reverted.
    }
};
