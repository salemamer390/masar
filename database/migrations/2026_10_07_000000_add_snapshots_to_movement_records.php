<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movement_records', function (Blueprint $table) {
            $table->string('reason', 500)->nullable()->after('new_salary');
            $table->json('old_values')->nullable()->after('reason');
            $table->json('new_values')->nullable()->after('old_values');
            $table->string('attachment_path')->nullable()->after('notes');
            $table->index('movement_date');
        });
    }

    public function down(): void
    {
        Schema::table('movement_records', function (Blueprint $table) {
            $table->dropIndex(['movement_date']);
            $table->dropColumn(['reason', 'old_values', 'new_values', 'attachment_path']);
        });
    }
};
