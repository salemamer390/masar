<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-employee calculation switches for the salary structure. Both default to
     * off (false), so every existing structure keeps calculating income tax and
     * the martyrs fund exactly as before.
     */
    public function up(): void
    {
        Schema::table('salaries', function (Blueprint $table) {
            if (! Schema::hasColumn('salaries', 'no_tax')) {
                $table->boolean('no_tax')->default(false)->after('no_insurance');
            }
            if (! Schema::hasColumn('salaries', 'no_martyrs_fund')) {
                $table->boolean('no_martyrs_fund')->default(false)->after('no_tax');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salaries', function (Blueprint $table) {
            foreach (['no_martyrs_fund', 'no_tax'] as $column) {
                if (Schema::hasColumn('salaries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
