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
        $actor = auth()->user();
        $roles = Role::with('permissions')
            ->when(!$actor->isSuperAdmin(), fn ($query) => $query->where('company_id', $actor->company_id))
            ->orderByRaw("CASE WHEN name = 'company_manager' THEN 0 ELSE 1 END")
            ->orderBy('display_name')
            ->get();
        $permissions = Permission::orderBy('module')->orderBy('display_name')->get()->groupBy('module');

        return view('roles.index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'roleLabels' => config('authorization.roles', []),
            'moduleLabels' => config('authorization.modules', []),
            'actionLabels' => config('authorization.actions', []),
        ]);
    }

    public function store(Request $request)
    {
        abort_if(auth()->user()->isSuperAdmin() && !auth()->user()->company_id, 403, 'أنشئ أدوار الشركات من حساب الشركة التابعة لها.');
        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $companyId = auth()->user()->company_id;
        abort_if(!$companyId, 403, 'لا يمكن إنشاء دور دون شركة.');
        $name = 'custom_'.str()->slug($data['display_name']).'_'.str()->random(6);
        Role::create([
            'name' => $name,
            'display_name' => $data['display_name'],
            'description' => $data['description'] ?? null,
            'company_id' => $companyId,
        ]);

        return redirect()->route('roles.index')->with('toast', 'تم إنشاء الدور بنجاح، ويمكنك تحديد صلاحياته الآن.');
    }

    public function update(Request $request, Role $role)
    {
        if (!auth()->user()->isSuperAdmin() && (int) $role->company_id !== (int) auth()->user()->company_id) abort(403);
        if ($role->id === 1) {
            return $request->wantsJson()
                ? response()->json(['message' => 'لا يمكن تعديل صلاحيات مدير النظام.'], 422)
                : back()->with('toast', 'لا يمكن تعديل صلاحيات مدير النظام.');
        }

        $permissions = $request->input('permissions', []);
        if ($role->name === 'company_manager') $permissions = Permission::query()->pluck('id')->all();
        $role->permissions()->sync($permissions);
        ActivityLog::record('role_update', "تم تحديث صلاحيات الدور: {$role->display_name}.", $role);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'تم تحديث صلاحيات الدور.', 'role_id' => $role->id])
            : back()->with('toast', 'تم تحديث صلاحيات الدور بنجاح.');
    }

    public function destroy(Role $role)
    {
        $actor = auth()->user();
        if (!$actor->isSuperAdmin() && (int) $role->company_id !== (int) $actor->company_id) abort(403);
        if ($role->id === 1 || $role->company_id === null) return back()->with('toast', 'لا يمكن حذف أدوار النظام العامة.');
        if ($role->users()->exists()) return back()->with('toast', 'لا يمكن حذف دور مرتبط بمستخدمين. غيّر أدوارهم أولًا ثم أعد المحاولة.');

        $displayName = $role->display_name;
        $role->permissions()->detach();
        $role->delete();
        ActivityLog::record('role_delete', "تم حذف الدور: {$displayName}.");

        return back()->with('toast', 'تم حذف الدور بنجاح.');
    }
}
