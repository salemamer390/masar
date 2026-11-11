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
        $roles = Role::with('permissions')->withCount('users')
            ->when($actor->company_id, fn ($query) => $query->where('company_id', $actor->company_id))
            ->orderByRaw("CASE WHEN name = 'company_manager' THEN 0 ELSE 1 END")
            ->orderBy('display_name')
            ->get();
        $permissions = Permission::orderBy('module')->orderBy('display_name')->get()->groupBy('module');
        $roleLabels = config('authorization.roles', []);
        $roleDescriptions = config('authorization.role_descriptions', []);
        $moduleLabels = config('authorization.modules', []);
        $actionLabels = config('authorization.actions', []);

        $rolePayload = $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'key' => $role->name,
            'name' => $roleLabels[$role->name] ?? $role->display_name,
            'description' => $roleDescriptions[$role->name] ?? $role->description,
            'users' => $role->users_count ?? 0,
            'system' => $role->id === 1 || $role->company_id === null,
            'company_manager' => $role->name === 'company_manager',
            'permission_ids' => $role->permissions->pluck('id')->values()->all(),
        ])->values()->all();
        $permissionPayload = $permissions->map(fn ($items, $module) => [
            'key' => $module,
            'name' => $moduleLabels[$module] ?? $module,
            'items' => $items->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'key' => $permission->name,
                'name' => ($moduleLabels[$permission->module] ?? $permission->module).' - '.($actionLabels[$permission->action] ?? $permission->display_name),
            ])->values()->all(),
        ])->values()->all();

        return view('roles.index', compact('roles', 'permissions', 'roleLabels', 'moduleLabels', 'actionLabels', 'rolePayload', 'permissionPayload'));
    }

    public function store(Request $request)
    {
        abort_if(auth()->user()->isSuperAdmin() && !auth()->user()->company_id, 403, __('أنشئ أدوار الشركات من حساب الشركة التابعة لها.'));
        $data = $request->validate(['display_name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:255']]);
        $companyId = auth()->user()->company_id;
        abort_if(!$companyId, 403, __('لا يمكن إنشاء دور دون شركة.'));
        Role::create(['name' => 'custom_'.str()->slug($data['display_name']).'_'.str()->random(6), 'display_name' => $data['display_name'], 'description' => $data['description'] ?? null, 'company_id' => $companyId]);
        return redirect()->route('roles.index')->with('toast', __('تم إنشاء الدور بنجاح.'));
    }

    public function update(Request $request, Role $role)
    {
        if (auth()->user()->company_id && (int) $role->company_id !== (int) auth()->user()->company_id) abort(403);
        if ($role->id === 1) return $request->wantsJson() ? response()->json(['success' => false, 'message' => __('لا يمكن تعديل صلاحيات مدير النظام.')], 422) : back()->with('toast', __('لا يمكن تعديل صلاحيات مدير النظام.'));
        $data = $request->validate(['display_name' => ['nullable', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:255'], 'permissions' => ['nullable', 'array'], 'permissions.*' => ['integer', 'exists:permissions,id']]);
        $permissions = $data['permissions'] ?? [];
        if ($role->name === 'company_manager') $permissions = Permission::query()->pluck('id')->all();
        if (array_key_exists('display_name', $data)) $role->display_name = $data['display_name'];
        if (array_key_exists('description', $data)) $role->description = $data['description'];
        $role->save();
        $role->permissions()->sync($permissions);
        ActivityLog::record('role_update', "تم تحديث صلاحيات الدور: {$role->display_name}.", $role);
        return $request->wantsJson() ? response()->json(['success' => true, 'message' => __('تم تحديث صلاحيات الدور.'), 'role_id' => $role->id]) : back()->with('toast', __('تم تحديث صلاحيات الدور بنجاح.'));
    }

    public function destroy(Request $request, Role $role)
    {
        $actor = auth()->user();
        if ($actor->company_id && (int) $role->company_id !== (int) $actor->company_id) abort(403);
        if ($role->id === 1 || $role->company_id === null) return $this->deleteResponse($request, false, 'لا يمكن حذف أدوار النظام العامة.', 422);
        if ($role->users()->exists()) return $this->deleteResponse($request, false, 'لا يمكن حذف دور مرتبط بمستخدمين. غيّر أدوارهم أولًا ثم أعد المحاولة.', 409);
        $displayName = $role->display_name;
        $role->permissions()->detach();
        $role->delete();
        ActivityLog::record('role_delete', "تم حذف الدور: {$displayName}.");
        return $this->deleteResponse($request, true, 'تم حذف الدور بنجاح.');
    }

    private function deleteResponse(Request $request, bool $success, string $message, int $status = 200)
    {
        return $request->wantsJson() ? response()->json(['success' => $success, 'message' => $message], $status) : back()->with('toast', $message);
    }
}
