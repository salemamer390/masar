<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'users.delete')->value('id');

        if (!$permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name' => 'users.delete',
                'display_name' => 'Delete Users',
                'module' => 'users',
                'action' => 'delete',
            ]);
        }

        $roleIds = DB::table('roles')->where('name', 'company_manager')->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ], []);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'users.delete')->value('id');
        if (!$permissionId) return;

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('user_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
