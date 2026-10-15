<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'termination_type')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('termination_type', 20)->nullable()->after('termination_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'termination_type')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('termination_type');
            });
        }
    }
};
