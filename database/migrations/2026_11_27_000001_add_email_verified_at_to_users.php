<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (!Schema::hasColumn('users', 'email_confirm_deadline_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('email_confirm_deadline_at')->nullable();
                $table->unsignedTinyInteger('email_confirm_reminders')->default(0);
            });
        }

        // Accounts that exist today keep working without a confirmation step.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn(array_values(array_filter(
                    ['email_verified_at', 'email_confirm_deadline_at', 'email_confirm_reminders'],
                    fn ($c) => Schema::hasColumn('users', $c)
                )));
            });
        }
    }
};
