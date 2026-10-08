<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->foreignId('manager_approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('manager_approved_at')->nullable()->after('manager_approved_by');
            $table->string('rejection_reason', 500)->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table) {
            $table->dropForeign(['manager_approved_by']);
            $table->dropColumn(['manager_approved_by', 'manager_approved_at', 'rejection_reason']);
        });
    }
};
