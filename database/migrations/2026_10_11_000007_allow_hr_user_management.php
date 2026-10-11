<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->whereIn('name', ['hr_manager', 'hr_specialist'])->pluck('id');
        $permissionIds = DB::table('permissions')->whereIn('name', ['users.view', 'users.create', 'users.edit'])->pluck('id');
        foreach ($roleId as $rid) foreach ($permissionIds as $pid) DB::table('role_permissions')->insertOrIgnore(['role_id'=>$rid, 'permission_id'=>$pid]);
    }
    public function down(): void
    {
        $roles = DB::table('roles')->whereIn('name', ['hr_manager', 'hr_specialist'])->pluck('id');
        $permissions = DB::table('permissions')->whereIn('name', ['users.view', 'users.create', 'users.edit'])->pluck('id');
        DB::table('role_permissions')->whereIn('role_id',$roles)->whereIn('permission_id',$permissions)->delete();
    }
};
