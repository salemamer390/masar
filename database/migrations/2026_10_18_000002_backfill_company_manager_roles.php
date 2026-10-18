<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionIds = DB::table('permissions')->pluck('id');
        foreach (DB::table('companies')->orderBy('id')->get() as $company) {
            $roleId = DB::table('roles')
                ->where('company_id', $company->id)
                ->where('name', 'company_manager')
                ->value('id');
            if (!$roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'company_id' => $company->id,
                    'name' => 'company_manager',
                    'display_name' => 'Company manager',
                    'description' => 'مدير الشركة وإدارة مستخدميها وأدوارها.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
            $ownerId = DB::table('users')
                ->where('company_id', $company->id)
                ->whereNotIn('role_id', DB::table('roles')->where('name', 'super_admin')->pluck('id'))
                ->orderBy('id')
                ->value('id');
            if ($ownerId) {
                DB::table('users')->where('id', $ownerId)->update([
                    'role_id' => $roleId,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Company manager roles intentionally remain if this migration is rolled back,
        // because existing users may already rely on them.
    }
};
