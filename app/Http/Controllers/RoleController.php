<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->get();
        $permissions = Permission::all()->groupBy('module');
        return view('roles.index', compact('roles', 'permissions'));
    }

    public function update(Request $request, Role $role)
    {
        if ($role->id === 1) {
            return $request->wantsJson()
                ? response()->json(['message' => 'لا يمكن تعديل صلاحيات المدير العام.'], 422)
                : back()->with('toast', 'لا يمكن تعديل صلاحيات المدير العام');
        }

        $permissions = $request->input('permissions', []);
        $role->permissions()->sync($permissions);

        ActivityLog::record('role_update', "تم تحديث صلاحيات الدور: {$role->display_name}.", $role);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Role permissions updated.', 'role_id' => $role->id])
            : back()->with('toast', 'تم تحديث صلاحيات الدور بنجاح');
    }
}
