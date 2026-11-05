<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Amer's server hit SQLSTATE 42S01 on 2026_11_05_000001: an earlier interrupted
 * migrate left employee_import_batches created but the migration unrecorded and
 * employee_import_rows missing. MySQL DDL is not transactional, so every lineage
 * migration must complete a partial install: create only what is missing and
 * never disturb existing tables or their rows.
 */
class ImportLineageMigrationRepairTest extends TestCase
{
    private function dropLineageSchema(): void
    {
        foreach (['movement_records', 'employees'] as $table) {
            if (Schema::hasColumn($table, 'import_batch_id')) {
                Schema::table($table, function ($t) { $t->dropConstrainedForeignId('import_batch_id'); });
            }
        }
        Schema::dropIfExists('employee_import_row_revisions');
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_import_batches');
    }

    private function runEmployeeLineageMigration(): void
    {
        $migration = require database_path('migrations/2026_11_05_000001_create_employee_import_lineage.php');
        $migration->up();
    }

    public function test_partial_install_completes_without_touching_existing_data(): void
    {
        $this->dropLineageSchema();
        try {
            // The interrupted state on his server: batches exists (with a row), rows does not.
            Schema::create('employee_import_batches', function ($t) {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('file_name');
                $t->longText('sheets')->nullable();
                $t->unsignedInteger('created')->default(0);
                $t->unsignedInteger('updated')->default(0);
                $t->unsignedInteger('unchanged')->default(0);
                $t->unsignedInteger('skipped')->default(0);
                $t->unsignedInteger('failed')->default(0);
                $t->unsignedInteger('rejected')->default(0);
                $t->longText('distributed')->nullable();
                $t->timestamps();
            });
            $companyId = (int) (DB::table('companies')->value('id') ?? DB::table('companies')->insertGetId(['name' => 'Migration Repair Co', 'slug' => 'migration-repair-co', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
            DB::table('employee_import_batches')->insert(['company_id' => $companyId, 'file_name' => 'old-partial.xlsx', 'created' => 12, 'created_at' => now(), 'updated_at' => now()]);

            $this->runEmployeeLineageMigration();

            $this->assertTrue(Schema::hasTable('employee_import_rows'));
            $this->assertTrue(Schema::hasTable('employee_import_row_revisions'));
            $this->assertTrue(Schema::hasColumn('employees', 'import_batch_id'));
            $this->assertTrue(Schema::hasColumn('movement_records', 'import_batch_id'));
            // The pre-existing table and its data survived untouched.
            $batch = DB::table('employee_import_batches')->where('file_name', 'old-partial.xlsx')->first();
            $this->assertNotNull($batch);
            $this->assertSame(12, (int) $batch->created);

            // A re-run over the fully-installed schema is a no-op, never an error.
            $this->runEmployeeLineageMigration();
            $this->assertSame(1, DB::table('employee_import_batches')->where('file_name', 'old-partial.xlsx')->count());
        } finally {
            // Always leave the complete schema behind for the import feature tests.
            $this->runEmployeeLineageMigration();
        }
    }

    public function test_attendance_lineage_migration_is_partial_install_safe(): void
    {
        Schema::dropIfExists('attendance_punch_revisions');
        Schema::dropIfExists('attendance_import_days');
        try {
            $migration = require database_path('migrations/2026_11_04_000001_create_attendance_import_lineage.php');
            $migration->up(); // batches exists already; days was missing
            $this->assertTrue(Schema::hasTable('attendance_import_batches'));
            $this->assertTrue(Schema::hasTable('attendance_import_days'));
            $this->assertTrue(Schema::hasTable('attendance_punch_revisions'));
            $migration->up(); // idempotent over the complete schema
            $this->assertTrue(Schema::hasTable('attendance_import_batches'));
        } finally {
            $migration = require database_path('migrations/2026_11_04_000001_create_attendance_import_lineage.php');
            $migration->up();
        }
    }
}
