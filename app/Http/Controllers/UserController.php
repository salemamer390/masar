<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Company;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\CompanySubscription;

class UserController extends Controller
{
    public function index()
    {
        $query = User::with(['role', 'company', 'directPermissions']);
        if (!auth()->user()->isSuperAdmin()) $query->where('company_id', auth()->user()->company_id);
        $users = $query->paginate(20);
        $roles = $this->rolesForActor()->when(!auth()->user()->isSuperAdmin(), fn ($query) => $query->where('id', '!=', 1))->get();
        $companies = auth()->user()->isSuperAdmin() ? Company::where('status','active')->orderBy('name')->get() : collect([auth()->user()->company])->filter();
        $permissions = Permission::orderBy('module')->orderBy('display_name')->get()->groupBy('module');
        $moduleLabels = config('authorization.modules', []);
        $roleLabels = config('authorization.roles', []);
        $permissionGroups = $permissions->map(function ($items, $module) {
            return [
                'module' => $module,
                'items' => $items->map(fn (Permission $item) => [
                    'id' => $item->id,
                    'name' => config('authorization.modules.'.$item->module, $item->module).' - '.config('authorization.actions.'.$item->action, $item->display_name),
                ])->values()->all(),
            ];
        })->values()->all();
        $permissionGroups = collect($permissionGroups)->map(function (array $group) use ($moduleLabels) {
            $group['module'] = $moduleLabels[$group['module']] ?? $group['module'];
            return $group;
        })->all();
        $companyOptions = $companies->map(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
        ])->values()->all();
        $roleOptions = $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'name' => $roleLabels[$role->name] ?? $role->display_name,
        ])->values()->all();
        return view('users.index', compact('users', 'roles', 'companies', 'permissions', 'permissionGroups', 'companyOptions', 'roleOptions', 'roleLabels'));
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
        $this->rolesForActor()->whereKey($validated['role_id'])->firstOrFail();
        $this->authorizePermissionAssignment($validated);

        $companyId = auth()->user()->isSuperAdmin() ? ($validated['company_id'] ?? null) : auth()->user()->company_id;
        if (!$companyId) return back()->withErrors(['company_id' => 'يجب ربط المستخدم بشركة قبل إنشاء الحساب.'])->withInput();
        $this->ensureRoleBelongsToCompany((int) $validated['role_id'], (int) $companyId);
        if (!auth()->user()->isSuperAdmin()) $this->ensureHrCapacity($companyId);
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
        $this->rolesForActor()->whereKey($validated['role_id'])->firstOrFail();
        $this->authorizePermissionAssignment($validated);

        $user->name = $validated['name'];
        $this->ensureRoleBelongsToCompany((int) $validated['role_id'], (int) $user->company_id);
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

    private function ensureHrCapacity(int $companyId): void
    {
        $subscription = CompanySubscription::with('plan')->where('company_id', $companyId)->whereIn('status', ['active', 'trial'])->latest('id')->first();
        $limit = (int) ($subscription?->plan?->hr_user_limit ?? 5);
        $ownerId = User::where('company_id', $companyId)->oldest('id')->value('id');
        $count = User::where('company_id', $companyId)->when($ownerId, fn ($q) => $q->whereKeyNot($ownerId))->count();
        abort_if($count >= $limit, 422, "لا يمكن إنشاء حساب HR جديد: الباقة تسمح بـ {$limit} حسابات HR بالإضافة إلى حساب المالك.");
    }

    private function authorizePermissionAssignment(array $validated): void
    {
        $actor = auth()->user();
        if ($actor->isSuperAdmin()) return;
        $allowed = Permission::all()->filter(fn (Permission $permission) => $actor->hasPermission($permission->name))->pluck('id')->map(fn ($id) => (int) $id);
        $requested = collect($validated['permissions'] ?? [])->map(fn ($id) => (int) $id);
        abort_if($requested->diff($allowed)->isNotEmpty(), 403, 'لا يمكنك منح صلاحية لا تملكها.');
        $rolePermissions = $this->rolesForActor()->with('permissions')->findOrFail($validated['role_id'])->permissions->pluck('id')->map(fn ($id) => (int) $id);
        abort_if($rolePermissions->diff($allowed)->isNotEmpty(), 403, 'لا يمكنك تعيين دور يتضمن صلاحيات أعلى من صلاحياتك.');
    }

    private function ensureRoleBelongsToCompany(int $roleId, int $companyId): void
    {
        $roleCompanyId = Role::whereKey($roleId)->value('company_id');
        abort_if($roleCompanyId === null || (int) $roleCompanyId !== $companyId, 422,
            'لا يمكن تعيين دور تابع لشركة أخرى لهذا المستخدم.');
    }

    private function rolesForActor()
    {
        $actor = auth()->user();
        return Role::query()->when(!$actor->isSuperAdmin(), function ($query) use ($actor): void {
            $query->where('company_id', $actor->company_id);
        });
    }
}
