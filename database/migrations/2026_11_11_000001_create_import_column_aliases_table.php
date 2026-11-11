<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Per-company learned column names for the employee workbook import:
        // when a user confirms "this header means this field" during review,
        // the pair is remembered here so the next file is recognised
        // automatically. Scoped per company - one tenant's vocabulary never
        // leaks into another's. Partial-install safe like the other
        // import-lineage migrations.
        if (!Schema::hasTable('import_column_aliases')) Schema::create('import_column_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('field', 50);            // EmployeeImportService::COLUMNS key
            $t->string('alias', 190);           // header text exactly as the user's file had it
            $t->string('alias_key', 190);       // normalised comparison key (ParsesImportValues::key)
            $t->timestamps();
            $t->unique(['company_id', 'alias_key']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('import_column_aliases');
    }
};
