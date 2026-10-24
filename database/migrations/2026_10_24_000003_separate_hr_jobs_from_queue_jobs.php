<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's database queue also uses the conventional `jobs` name.
        // The HR application needs a different table for job titles.
        if (Schema::hasTable('jobs')) {
            $isQueueTable = Schema::hasColumn('jobs', 'payload');
            if ($isQueueTable && !Schema::hasTable('queue_jobs')) {
                Schema::rename('jobs', 'queue_jobs');
            } elseif (!$isQueueTable && !Schema::hasTable('hr_jobs')) {
                Schema::rename('jobs', 'hr_jobs');
            }
        }

        if (!Schema::hasTable('hr_jobs')) {
            Schema::create('hr_jobs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->string('title', 150);
                $table->string('job_code', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'title'], 'hr_jobs_company_title_unique');
            });
        }

        // Existing installations may have foreign keys still named against
        // the old table. Recreate them against the new HR table when needed.
        foreach ([
            'employees' => ['job_id'],
            'movement_records' => ['old_job_id', 'new_job_id'],
        ] as $tableName => $columns) {
            if (!Schema::hasTable($tableName)) continue;
            foreach ($columns as $column) {
                if (!Schema::hasColumn($tableName, $column)) continue;
                try { Schema::table($tableName, fn (Blueprint $table) => $table->dropForeign([$column])); } catch (Throwable) {}
                try {
                    Schema::table($tableName, function (Blueprint $table) use ($column): void {
                        $table->foreign($column)->references('id')->on('hr_jobs')->nullOnDelete();
                    });
                } catch (Throwable) {}
            }
        }
    }

    public function down(): void
    {
        // Do not merge HR titles back into the queue table automatically.
    }
};
