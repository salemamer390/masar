<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('hr_jobs')) return;

        $database = DB::getDatabaseName();
        $constraints = DB::select(
            'SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = ?
               AND REFERENCED_TABLE_NAME IN (\'jobs\', \'queue_jobs\')',
            [$database]
        );

        foreach ($constraints as $constraint) {
            if (!in_array($constraint->TABLE_NAME, ['employees', 'movement_records'], true)) continue;
            if (!in_array($constraint->COLUMN_NAME, ['job_id', 'old_job_id', 'new_job_id'], true)) continue;
            $table = str_replace('`', '``', $constraint->TABLE_NAME);
            $name = str_replace('`', '``', $constraint->CONSTRAINT_NAME);
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }

        foreach ([
            'employees' => ['job_id'],
            'movement_records' => ['old_job_id', 'new_job_id'],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($tableName, $column)) continue;
                $constraintName = $tableName . '_' . $column . '_hr_jobs_fk';
                $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
                    ->where('CONSTRAINT_SCHEMA', $database)
                    ->where('TABLE_NAME', $tableName)
                    ->where('CONSTRAINT_NAME', $constraintName)
                    ->exists();
                if ($exists) continue;
                Schema::table($tableName, function (Blueprint $table) use ($column, $constraintName): void {
                    $table->foreign($column, $constraintName)->references('id')->on('hr_jobs')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        // The previous constraints cannot be restored safely because the
        // `jobs` table may be the Laravel queue table on the target server.
    }
};
