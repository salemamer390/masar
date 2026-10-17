<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['name' => 'employees.import'],
            ['display_name' => 'Import Employees', 'module' => 'employees', 'action' => 'import'],
        );
        $importId = DB::table('permissions')->where('name', 'employees.import')->value('id');
        if (!$importId) {
            return;
        }

        // Every role that can already create employees may import them; Super Admin always can.
        $createId = DB::table('permissions')->where('name', 'employees.create')->value('id');
        $roleIds = $createId
            ? DB::table('role_permissions')->where('permission_id', $createId)->pluck('role_id')->all()
            : [];
        $superAdminId = DB::table('roles')->where('name', 'super_admin')->value('id');
        if ($superAdminId) {
            $roleIds[] = $superAdminId;
        }

        foreach (array_unique($roleIds) as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $importId], []);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }
        $id = DB::table('permissions')->where('name', 'employees.import')->value('id');
        if (!$id) {
            return;
        }
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
        }
        DB::table('permissions')->where('id', $id)->delete();
    }
};
