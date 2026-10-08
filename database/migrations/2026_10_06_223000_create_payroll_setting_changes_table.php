<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->index();
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        DB::table('permissions')->updateOrInsert(
            ['name' => 'payroll.settings'],
            ['display_name' => 'Manage Payroll Settings', 'module' => 'payroll', 'action' => 'settings']
        );
        $permissionId = DB::table('permissions')->where('name', 'payroll.settings')->value('id');
        foreach (DB::table('roles')->whereIn('name', ['super_admin', 'payroll'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'payroll.settings')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        Schema::dropIfExists('payroll_setting_changes');
    }
};
