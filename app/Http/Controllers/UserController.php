<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Company;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $query = User::with(['role', 'company', 'directPermissions']);
        if (!auth()->user()->isSuperAdmin()) $query->where('company_id', auth()->user()->company_id);
        $users = $query->paginate(20);
        $roles = auth()->user()->isSuperAdmin() ? Role::all() : Role::where('id', '!=', 1)->get();
        $companies = auth()->user()->isSuperAdmin() ? Company::where('status','active')->orderBy('name')->get() : collect([auth()->user()->company])->filter();
        $permissions = Permission::orderBy('module')->orderBy('display_name')->get()->groupBy('module');
        $permissionGroups = $permissions->map(function ($items, $module) {
            return [
                'module' => $module,
                'items' => $items->map(fn (Permission $item) => [
                    'id' => $item->id,
                    'name' => $item->display_name,
                ])->values()->all(),
            ];
        })->values()->all();
        $companyOptions = $companies->map(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
        ])->values()->all();
        $roleOptions = $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->display_name,
        ])->values()->all();
        return view('users.index', compact('users', 'roles', 'companies', 'permissions', 'permissionGroups', 'companyOptions', 'roleOptions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role_id' => ['required', 'exists:roles,id'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ], [
            'name.required' => 'الاسم مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.unique' => 'البريد الإلكتروني مسجل بالفعل.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألا تقل عن 6 أحرف.',
            'role_id.required' => 'الدور مطلوب.',
        ]);
        if (!auth()->user()->isSuperAdmin() && (int) $validated['role_id'] === 1) abort(403, 'لا يمكن منح صلاحية مدير المنصة.');
        $this->authorizePermissionAssignment($validated);

        $companyId = auth()->user()->isSuperAdmin() ? ($validated['company_id'] ?? null) : auth()->user()->company_id;
        if (!$companyId) return back()->withErrors(['company_id' => 'يجب ربط المستخدم بشركة قبل إنشاء الحساب.'])->withInput();
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'is_active' => true,
            'company_id' => $companyId,
        ]);
        if (array_key_exists('permissions', $validated)) $user->directPermissions()->sync($validated['permissions'] ?? []);

        ActivityLog::record('user_create', "تم إنشاء مستخدم جديد: {$user->name}.", $user);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'User created.', 'user' => $user->fresh()->load(['role', 'directPermissions'])])
            : back()->with('toast', 'تمت إضافة المستخدم بنجاح');
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'role_id' => ['required', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:6'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['exists:permissions,id'],
        ]);
        if (!auth()->user()->isSuperAdmin() && (int) $validated['role_id'] === 1) abort(403, 'لا يمكن منح صلاحية مدير المنصة.');
        $this->authorizePermissionAssignment($validated);

        $user->name = $validated['name'];
        $user->role_id = $validated['role_id'];
        if (!auth()->user()->isSuperAdmin() && (int) $user->company_id !== (int) auth()->user()->company_id) abort(403);
        $user->is_active = $request->has('is_active');

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();
        if (array_key_exists('permissions', $validated)) $user->directPermissions()->sync($validated['permissions'] ?? []);
        ActivityLog::record('user_update', "تم تحديث بيانات المستخدم: {$user->name}.", $user);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'User updated.', 'user' => $user->fresh()->load(['role', 'directPermissions'])])
            : back()->with('toast', 'تم تحديث بيانات المستخدم بنجاح');
    }

    private function authorizePermissionAssignment(array $validated): void
    {
        $actor = auth()->user();
        if ($actor->isSuperAdmin()) return;
        $allowed = Permission::all()->filter(fn (Permission $permission) => $actor->hasPermission($permission->name))->pluck('id')->map(fn ($id) => (int) $id);
        $requested = collect($validated['permissions'] ?? [])->map(fn ($id) => (int) $id);
        abort_if($requested->diff($allowed)->isNotEmpty(), 403, 'لا يمكنك منح صلاحية لا تملكها.');
        $rolePermissions = Role::with('permissions')->findOrFail($validated['role_id'])->permissions->pluck('id')->map(fn ($id) => (int) $id);
        abort_if($rolePermissions->diff($allowed)->isNotEmpty(), 403, 'لا يمكنك تعيين دور يتضمن صلاحيات أعلى من صلاحياتك.');
    }
}
