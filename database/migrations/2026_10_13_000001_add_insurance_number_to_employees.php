<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'insurance_number')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->string('insurance_number', 100)->nullable()->after('national_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'insurance_number')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('insurance_number');
            });
        }
    }
};
