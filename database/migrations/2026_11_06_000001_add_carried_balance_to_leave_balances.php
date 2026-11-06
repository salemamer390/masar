<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_balances') && !Schema::hasColumn('leave_balances', 'carried_balance')) {
            Schema::table('leave_balances', function (Blueprint $table): void {
                // Balance carried into this cycle from earlier cycles, computed
                // at sync time; informational, the value is always recomputed.
                $table->decimal('carried_balance', 8, 2)->default(0)->after('annual_used');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_balances') && Schema::hasColumn('leave_balances', 'carried_balance')) {
            Schema::table('leave_balances', fn (Blueprint $table) => $table->dropColumn('carried_balance'));
        }
    }
};
