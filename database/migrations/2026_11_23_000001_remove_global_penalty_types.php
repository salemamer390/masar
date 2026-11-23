<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the shared (company_id NULL) penalty types. A global type that a
     * company's penalties still use is first copied into a company-owned type
     * with the same name and those penalties are repointed, so no penalty
     * record is deleted. Only then are the global rows removed.
     */
    public function up(): void
    {
        if (! Schema::hasTable('penalty_types') || ! Schema::hasTable('penalties') || ! Schema::hasColumn('penalty_types', 'company_id')) {
            return;
        }

        DB::transaction(function (): void {
            $globals = DB::table('penalty_types')->whereNull('company_id')->get();
            foreach ($globals as $global) {
                $used = DB::table('penalties')
                    ->leftJoin('employees', 'employees.id', '=', 'penalties.employee_id')
                    ->where('penalties.penalty_type_id', $global->id)
                    ->selectRaw('COALESCE(penalties.company_id, employees.company_id) AS cid, COUNT(*) AS n')
                    ->groupBy('cid')
                    ->get();
                foreach ($used as $row) {
                    if ($row->cid === null) {
                        throw new RuntimeException("Penalties of global type {$global->id} have no company; aborting without changes.");
                    }
                    $own = DB::table('penalty_types')->where('company_id', $row->cid)->where('name', $global->name)->first();
                    $ownId = $own?->id ?? DB::table('penalty_types')->insertGetId([
                        'name' => $global->name,
                        'is_active' => $global->is_active,
                        'default_deduction_days' => $global->default_deduction_days,
                        'company_id' => $row->cid,
                    ]);
                    DB::table('penalties')
                        ->where('penalty_type_id', $global->id)
                        ->where(function ($q) use ($row): void {
                            $q->where('company_id', $row->cid)
                                ->orWhereIn('employee_id', DB::table('employees')->where('company_id', $row->cid)->select('id'));
                        })
                        ->update(['penalty_type_id' => $ownId]);
                }
                if (DB::table('penalties')->where('penalty_type_id', $global->id)->exists()) {
                    throw new RuntimeException("Global penalty type {$global->id} is still referenced; aborting without changes.");
                }
            }
            DB::table('penalty_types')->whereNull('company_id')->delete();
        });
    }

    public function down(): void
    {
        // Removed global types are intentionally not recreated.
    }
};
