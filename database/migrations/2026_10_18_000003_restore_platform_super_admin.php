<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restore the seeded platform administrator after the company-role backfill.
     *
     * The platform administrator is intentionally not attached to a company;
     * company managers remain company-scoped through their company_id.
     */
    public function up(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->whereNull('company_id')
            ->value('id');

        if (!$roleId) {
            return;
        }

        DB::table('users')
            ->whereRaw('LOWER(email) = ?', ['amer.salem@tazamun.local'])
            ->update([
                'role_id' => $roleId,
                'company_id' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Deliberately irreversible: restoring the platform account is a data correction.
    }
};
