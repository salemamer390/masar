<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Trace workbook-created operational rows back to their import batch so
        // review can list (and the company can clean up) exactly what an import
        // created. Partial-install safe: add only missing columns, never touch
        // existing data or unrelated tables.
        foreach (['leaves', 'penalties', 'hr_requests'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'import_batch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignId('import_batch_id')->nullable()->after('id')->constrained('employee_import_batches')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void {
        foreach (['leaves', 'penalties', 'hr_requests'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'import_batch_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('import_batch_id');
                });
            }
        }
    }
};
