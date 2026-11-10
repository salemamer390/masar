<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a company-owned custom leave type that already has requests archives
 * it instead of deleting the row. archived_at distinguishes that deliberate
 * removal from an ordinary inactive type: archived types leave the settings
 * list for good and can never be switched back on through a settings save.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leave_types') || Schema::hasColumn('leave_types', 'archived_at')) return;
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leave_types') || !Schema::hasColumn('leave_types', 'archived_at')) return;
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
