<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // Metadata-only lineage: which file, who, when, sheet classification and
        // outcome counts. The raw workbook is never retained by default (PII,
        // salaries) - only rows that need human review are kept, encrypted.
        // Partial-install safe: MySQL DDL is not transactional, so a previous
        // interrupted run may have created some of these. Create only what is
        // missing and never touch existing tables or their data.
        if (!Schema::hasTable('employee_import_batches')) Schema::create('employee_import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('file_name');
            $t->longText('sheets')->nullable(); // json: sheet name => detected kind
            $t->unsignedInteger('created')->default(0);
            $t->unsignedInteger('updated')->default(0);
            $t->unsignedInteger('unchanged')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('rejected')->default(0);
            $t->longText('distributed')->nullable(); // json: attendance/leaves/penalties/requests counts
            $t->timestamps();
        });
        if (!Schema::hasTable('employee_import_rows')) Schema::create('employee_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained('employee_import_batches')->restrictOnDelete();
            $t->string('sheet')->nullable();
            $t->unsignedInteger('line');
            $t->string('kind', 30)->default('employees'); // employees | attendance | leaves | penalties | requests | unknown
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('pending'); // pending | fixed | dismissed
            $t->longText('payload');   // encrypted json: raw cell values for review/fix
            $t->longText('errors');    // json: rejection reasons shown to the reviewer
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        if (!Schema::hasTable('employee_import_row_revisions')) Schema::create('employee_import_row_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('import_row_id')->constrained('employee_import_rows')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 30); // fix | dismiss
            $t->text('reason');
            $t->longText('before_data');  // encrypted json
            $t->longText('after_data');   // encrypted json
            $t->timestamp('created_at');
        });
        if (!Schema::hasColumn('employees', 'import_batch_id')) Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('import_batch_id')->nullable()->after('id')->constrained('employee_import_batches')->nullOnDelete();
        });
        if (!Schema::hasColumn('movement_records', 'import_batch_id')) Schema::table('movement_records', function (Blueprint $t) {
            $t->foreignId('import_batch_id')->nullable()->after('id')->constrained('employee_import_batches')->nullOnDelete();
        });
    }

    public function down(): void {
        Schema::table('movement_records', function (Blueprint $t) {
            $t->dropConstrainedForeignId('import_batch_id');
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('import_batch_id');
        });
        Schema::dropIfExists('employee_import_row_revisions');
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_import_batches');
    }
};
