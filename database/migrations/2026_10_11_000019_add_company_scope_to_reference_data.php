<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['departments'=>['name'],'jobs'=>['title'],'branches'=>['name'],'employment_types'=>['name'],'leave_types'=>['name','policy_code'],'penalty_types'=>['name'],'movement_types'=>['name'],'request_types'=>['code'],'document_types'=>['name'],'banks'=>['name'],'payment_methods'=>['name']];
        foreach ($tables as $table => $columns) {
            if (!Schema::hasTable($table) || Schema::hasColumn($table, 'company_id')) continue;
            Schema::table($table, function (Blueprint $blueprint): void { $blueprint->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete(); });
            $dropped = [];
            foreach (DB::select('SHOW INDEX FROM '.$table) as $index) {
                if ((int) $index->Non_unique === 0 && $index->Key_name !== 'PRIMARY' && !in_array($index->Key_name, $dropped, true)) {
                    DB::statement('ALTER TABLE '.$table.' DROP INDEX '.$index->Key_name);
                    $dropped[] = $index->Key_name;
                }
            }
            Schema::table($table, function (Blueprint $blueprint) use ($columns, $table): void { $blueprint->unique(array_merge(['company_id'], $columns), $table.'_company_reference_unique'); });
        }
    }
    public function down(): void
    {
        foreach (['departments','jobs','branches','employment_types','leave_types','penalty_types','movement_types','request_types','document_types','banks','payment_methods'] as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'company_id')) continue;
            Schema::table($table, function (Blueprint $blueprint) use ($table): void { $blueprint->dropUnique($table.'_company_reference_unique'); $blueprint->dropForeign(['company_id']); $blueprint->dropColumn('company_id'); });
        }
    }
};
