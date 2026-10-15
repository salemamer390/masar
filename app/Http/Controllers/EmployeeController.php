<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Bank;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Job;
use App\Models\PaymentMethod;
use App\Models\Salary;
use App\Models\MovementRecord;
use App\Models\MovementType;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CompanySubscription;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\PayrollSettingsService;
use App\Services\PayrollCalculator;
use App\Services\EmployeeProfileCompletionService;
use App\Notifications\WorkPermitDeadlineNotification;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly PayrollSettingsService $payrollSettings,
        private readonly PayrollCalculator $payrollCalculator,
        private readonly EmployeeProfileCompletionService $profileCompletion,
    )
    {
    }
    public function index(Request $request)
    {
        $query = Employee::with(['department', 'job', 'status'])->orderByDesc('id');

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                  ->orWhere('employee_code', 'like', "%{$s}%")
                  ->orWhere('national_id', 'like', "%{$s}%");
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        $employees = $query->paginate(25)->withQueryString();
        $departments = Department::where('is_active', 1)->get();
        $statuses = EmployeeStatus::all();
        $totalEmployees = Employee::count();
        $activeEmployees = Employee::whereHas('status', fn ($status) => $status->whereIn('name', ['Active', 'نشط']))->count();
        $employeeSummary = [
            'total' => $totalEmployees,
            'active' => $activeEmployees,
            'inactive' => max(0, $totalEmployees - $activeEmployees),
            'unassigned' => Employee::whereNull('department_id')->count(),
            'departments' => Employee::whereNotNull('department_id')->distinct('department_id')->count('department_id'),
            'contracts_due' => Employee::whereBetween('contract_end_date', [today(), today()->copy()->addDays(30)])->count(),
            'uninsured' => Employee::whereDoesntHave('insuranceRecord')->count(),
            'on_leave' => Employee::whereHas('leaves', fn ($leave) => $leave
                ->whereIn('status', ['Approved', 'معتمد'])
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today()))->count(),
        ];

        $employeeCardDetails = [
            'total' => Employee::with(['department', 'status'])->orderBy('full_name')->get(),
            'active' => Employee::with(['department'])->whereHas('status', fn ($q) => $q->whereIn('name', ['Active', 'Ù†Ø´Ø·']))->orderBy('full_name')->get(),
            'departments' => Department::whereHas('employees')->withCount('employees')->orderBy('name')->get(),
            'contracts_due' => Employee::with(['department'])->whereBetween('contract_end_date', [today(), today()->copy()->addDays(30)])->orderBy('contract_end_date')->get(),
            'uninsured' => Employee::with(['department'])->whereDoesntHave('insuranceRecord')->orderBy('full_name')->get(),
            'on_leave' => Employee::with(['department'])->whereHas('leaves', fn ($q) => $q->whereIn('status', ['Approved', 'Ù…Ø¹ØªÙ…Ø¯'])->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today()))->orderBy('full_name')->get(),
        ];

        return view('employees.index', compact('employees', 'departments', 'statuses', 'employeeSummary', 'employeeCardDetails'));
    }

    public function show(Employee $employee, Request $request)
    {
        $employee->load([
            'department',
            'job',
            'branch',
            'employmentType',
            'status',
            'manager',
            'profileDetail',
            'documents',
            'emergencyContacts',
            'bankAccounts.bank',
            'bankAccounts.paymentMethod',
            'leaves.leaveType',
            'attendances',
            'salaries.paymentMethod',
            'payrollRecords.salary',
            'insuranceRecord',
            'penalties.penaltyType',
            'movements.movementType',
            'movements.oldDepartment',
            'movements.newDepartment',
            'movements.oldJob',
            'movements.newJob',
            'hrRequests.requestType',
        ]);

        $activeTab = $request->query('tab', 'overview');

        // Leave mini stats: support the canonical DB workflow values and legacy Arabic values.
        $leaveBalance = $employee->manual_annual_balance;
        if ($leaveBalance === null && $employee->profileDetail?->annual_leave_days !== null) {
            $leaveBalance = (float) $employee->profileDetail->annual_leave_days;
        }
        $leaveUsed = $employee->leaves->whereIn('status', ['Approved', 'معتمدة'])->sum('days');
        $leavePending = $employee->leaves->whereIn('status', ['Pending', 'Manager Approved', 'قيد الاعتماد'])->count();
        $lastLeave = $employee->leaves->first()?->start_date?->format('d/m/Y') ?? '—';

        // Penalty mini stats
        $penaltyTotal = $employee->penalties->count();
        $penaltyClosed = $employee->penalties->whereIn('status', ['Approved', 'مغلقة', 'Closed'])->count();
        $penaltyOpen = $penaltyTotal - $penaltyClosed;
        $lastPenalty = $employee->penalties->first()?->penalty_date?->format('d/m/Y') ?? '—';

        $currentSalary = $employee->salaries->firstWhere('status', 'Active') ?? $employee->salaries->first();
        $banks = Bank::where('is_active', true)->orderBy('name')->get();
        $paymentMethods = PaymentMethod::orderBy('name')->get();
        $departments = Department::where('is_active', true)->orderBy('name')->get();
        $jobs = Job::where('is_active', true)->orderBy('title')->get();
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $employmentTypes = EmploymentType::orderBy('name')->get();
        $statuses = EmployeeStatus::orderBy('name')->get();
        $managers = Employee::whereKeyNot($employee->id)->orderBy('full_name')->get(['id', 'full_name', 'employee_code']);
        $payrollRates = [
            'employee' => $this->payrollSettings->employeeInsuranceRate() * 100,
            'employer' => $this->payrollSettings->employerInsuranceRate() * 100,
        ];
        $documentTypes = DocumentType::where('is_active', true)->orderBy('name')->get();
        $requiredDocumentTypes = $this->profileCompletion->applicableRequiredDocumentTypes($employee);
        $profileCompletion = $this->profileCompletion->evaluate($employee, $requiredDocumentTypes);

        return view('employees.profile', compact(
            'employee',
            'currentSalary',
            'activeTab',
            'leaveBalance',
            'leaveUsed',
            'leavePending',
            'lastLeave',
            'penaltyTotal',
            'penaltyClosed',
            'penaltyOpen',
            'lastPenalty'
            , 'banks', 'paymentMethods', 'departments', 'jobs', 'branches', 'employmentTypes', 'statuses', 'managers', 'payrollRates', 'documentTypes', 'requiredDocumentTypes', 'profileCompletion'
        ));
    }

    public function store(Request $request)
    {
        $employeeCode = trim((string) $request->input('employee_code', ''));
        $request->merge(['employee_code' => $employeeCode !== '' ? $employeeCode : null]);
        $companyId = auth()->user()->company_id ?: DB::table('companies')->value('id');

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_code')->where(fn ($query) => $query->where('company_id', $companyId))],
            'national_id' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'national_id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'profile_photo' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'gender' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'profile' => ['nullable', 'array'],
            'profile.nationality' => ['nullable', 'string', 'max:100'],
            'profile.currency' => ['nullable', 'string', 'max:10'],
            'gross_salary' => ['nullable', 'numeric', 'min:0.01'],
            'salary_source' => ['nullable', 'in:gross,net'],
            'salary_value' => ['nullable', 'numeric', 'min:0.01'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_id' => ['nullable', 'exists:jobs,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'hire_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'leave_entitlement_category' => ['nullable', 'in:normal,disabled,hazardous'],
        ], [
            'full_name.required' => 'اسم الموظف الكامل مطلوب.',
            'employee_code.unique' => 'كود الموظف مستخدم بالفعل.',
            'national_id.unique' => 'الرقم القومي مسجل لموظف آخر.',
        ]);

        $validated = $this->applyNationalIdData($validated);
        $this->ensureOwnedEmployeeReferences($validated);
        $validated['company_id'] = $companyId;
        $this->ensureEmployeeCapacity($companyId);
        $this->validateSalarySource($request, true);
        [$grossSalary, $calculatedNet] = $this->resolveEmployeeSalary($request);
        $validated['gross_salary'] = $grossSalary;
        unset($validated['salary_source'], $validated['salary_value']);

        $names = explode(' ', trim($validated['full_name']));
        $validated['first_name'] = $names[0] ?? '';
        $validated['last_name'] = end($names) ?: '';
        $validated['status_id'] = $request->input('status_id', 1);
        $hireDate = \Carbon\Carbon::parse($validated['hire_date'] ?? today())->toDateString();
        $validated['hire_date'] = $hireDate;
        $validated['contract_start_date'] = $hireDate;
        $validated['contract_end_date'] = $request->filled('contract_end_date')
            ? \Carbon\Carbon::parse($validated['contract_end_date'])->toDateString()
            : \Carbon\Carbon::parse($hireDate)->addYear()->toDateString();

        $employee = DB::transaction(function () use ($validated) {
            $enteredCode = trim((string) ($validated['employee_code'] ?? ''));
            if ($enteredCode === '') {
                // If no code is supplied, generate the next numeric code safely.
                $lastCode = Employee::query()
                    ->lockForUpdate()
                    ->where('company_id', $validated['company_id'])
                    ->whereRaw("employee_code REGEXP '^[0-9]+$'")
                    ->selectRaw('MAX(CAST(employee_code AS UNSIGNED)) as max_code')
                    ->value('max_code');

                $validated['employee_code'] = (string) max(101, ((int) $lastCode) + 1);
            } else {
                $validated['employee_code'] = $enteredCode;
            }

            $employee = Employee::create($validated);
            // Keep every employee compatible with the full profile screen,
            // even when optional profile fields have not been entered yet.
            $profile = $validated['profile'] ?? [];
            unset($validated['profile']);
            $employee->profileDetail()->create(['nationality' => $profile['nationality'] ?? 'مصري', 'currency' => $profile['currency'] ?? 'EGP']);
            $this->createSalaryStructureFromEmployee($employee);
            return $employee;
        });
        if ($request->hasFile('profile_photo')) {
            $employee->update(['profile_photo_path' => $request->file('profile_photo')->store("employees/{$employee->id}/profile", 'public')]);
        }
        ActivityLog::record('employee_create', "تمت إضافة موظف جديد: {$employee->full_name}", $employee);

        $recipients = User::with('role.permissions')->where('is_active', true)
            ->where('company_id', $employee->company_id)->get()
            ->filter(fn (User $user) => $user->hasPermission('employees.view') || (int) $user->employee_id === (int) $employee->id)
            ->unique('id');
        $recipients->each(fn (User $user) => $user->notify(new WorkPermitDeadlineNotification($employee)));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة الموظف بنجاح.',
                'employee' => $employee->load(['department', 'job', 'status']),
            ]);
        }

        return redirect()->route('employees.show', $employee)->with('toast', 'تمت إضافة الموظف بنجاح.');
    }

    public function update(Request $request, Employee $employee)
    {
        if (!auth()->user()->isSuperAdmin() && (int) $employee->company_id !== (int) auth()->user()->company_id) abort(403);
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'national_id')->where(fn ($query) => $query->where('company_id', $employee->company_id))->ignore($employee->id)],
            'employee_code' => ['nullable', 'string', 'max:50', Rule::unique('employees', 'employee_code')->where(fn ($query) => $query->where('company_id', $employee->company_id))->ignore($employee->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'profile_photo' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'gross_salary' => ['nullable', 'numeric', 'min:0.01'],
            'salary_source' => ['nullable', 'in:gross,net'],
            'salary_value' => ['nullable', 'numeric', 'min:0.01'],
            'gender' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:500'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'qualification' => ['nullable', 'string', 'max:150'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_id' => ['nullable', 'exists:jobs,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'employment_type_id' => ['nullable', 'exists:employment_types,id'],
            'status_id' => ['nullable', 'exists:employee_statuses,id'],
            'leave_entitlement_category' => ['nullable', 'in:normal,disabled,hazardous'],
            'manager_employee_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee->id])],
            'hire_date' => ['nullable', 'date'],
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'contract_duration' => ['nullable', 'string', 'max:100'],
            'termination_date' => ['nullable', 'date'],
            'termination_type' => ['nullable', Rule::in(['resignation', 'dismissal'])],
            'notes' => ['nullable', 'string'],
            'profile' => ['nullable', 'array'],
            'profile.nationality' => ['nullable', 'string', 'max:100'],
            'profile.personal_email' => ['nullable', 'email', 'max:190'],
            'profile.work_pattern' => ['nullable', 'string', 'max:50'],
            'profile.weekly_work_days' => ['nullable', 'string', 'max:100'],
            'profile.daily_work_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'profile.working_hours_text' => ['nullable', 'string', 'max:100'],
            'profile.contract_type' => ['nullable', 'string', 'max:100'],
            'profile.probation_period' => ['nullable', 'string', 'max:100'],
            'profile.annual_leave_days' => ['nullable', 'numeric', 'min:0'],
            'profile.contract_attachment_status' => ['nullable', 'string', 'max:50'],
            'profile.additional_conditions' => ['nullable', 'string'],
            'profile.currency' => ['nullable', 'string', 'max:10'],
            'profile.national_id_issue_date' => ['nullable', 'date'],
            'profile.national_id_expiry_date' => ['nullable', 'date', 'after_or_equal:profile.national_id_issue_date'],
            'profile.national_id_place' => ['nullable', 'string', 'max:150'],
            'profile.military_status' => ['nullable', 'string', 'max:100'],
        ]);

        $this->ensureOwnedEmployeeReferences($validated, $employee);
        $validated = $this->applyTerminationDetails($request, $validated, $employee);
        $salaryChanged = $request->filled('salary_source') && $request->filled('salary_value');
        if ($salaryChanged) {
            $validated = $this->applyNationalIdData($validated);
            $this->validateSalarySource($request, false, $employee);
            [$grossSalary, $calculatedNet] = $this->resolveEmployeeSalary($request);
            $validated['gross_salary'] = $grossSalary;
            unset($validated['salary_source'], $validated['salary_value']);
        }

        $profileData = $validated['profile'] ?? [];
        unset($validated['profile']);
        unset($validated['profile_photo']);
        if ($request->hasFile('profile_photo')) {
            if ($employee->profile_photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($employee->profile_photo_path);
            }
            $validated['profile_photo_path'] = $request->file('profile_photo')->store("employees/{$employee->id}/profile", 'public');
        }
        if ($request->filled('hire_date')) {
            $hireDate = \Carbon\Carbon::parse($validated['hire_date'])->toDateString();
            $validated['hire_date'] = $hireDate;
            $validated['contract_start_date'] = $hireDate;
            if (! $request->filled('contract_end_date')) {
                $validated['contract_end_date'] = \Carbon\Carbon::parse($hireDate)->addYear()->toDateString();
            }
        }
        if (blank($validated['first_name'] ?? null)) {
            $nameParts = preg_split('/\s+/u', trim($validated['full_name']));
            $validated['first_name'] = $nameParts[0] ?? null;
            $validated['middle_name'] = $nameParts[1] ?? null;
            $validated['last_name'] = count($nameParts) > 2 ? implode(' ', array_slice($nameParts, 2)) : ($nameParts[1] ?? null);
        }
        $oldValues = $employee->only(array_keys($validated));
        $oldProfile = $employee->profileDetail?->toArray();
        $oldStatusId = (int) $employee->status_id;
        $newStatusId = (int) ($validated['status_id'] ?? $employee->status_id);
        $statusChanged = $oldStatusId !== $newStatusId || $request->boolean('status_action');
        $trackedFields = ['department_id', 'job_id', 'branch_id', 'gross_salary'];
        $changedFields = [];
        foreach ($trackedFields as $field) {
            if (array_key_exists($field, $validated) && (string) $employee->{$field} !== (string) $validated[$field]) {
                $changedFields[$field] = ['old' => $employee->{$field}, 'new' => $validated[$field]];
            }
        }
        $movementChanged = $statusChanged || $changedFields !== [];
        DB::transaction(function () use ($employee, $validated, $profileData, $salaryChanged, $movementChanged, $statusChanged, $oldStatusId, $changedFields): void {
            $employee->update($validated);
            $employee->profileDetail()->updateOrCreate([], $profileData);
            if ($salaryChanged) {
                $this->syncSalaryStructureFromEmployee($employee->fresh());
            }
            if ($movementChanged) {
                $fresh = $employee->fresh(['status']);
                $movementTypeName = $statusChanged ? 'Status Change' : 'Profile Update';
                $movementType = MovementType::firstOrCreate(
                    ['name' => $movementTypeName],
                    ['company_id' => $fresh->company_id]
                );
                $statusLabel = match ($fresh->status?->name) {
                    'Terminated', 'منتهي الخدمة', 'منتهى الخدمة' => $fresh->termination_type === 'dismissal' ? 'فصل' : ($fresh->termination_type === 'resignation' ? 'استقالة' : 'انتهاء خدمة'),
                    'Suspended', 'موقوف' => 'إيقاف',
                    'Active', 'نشط' => 'عودة إلى العمل',
                    default => $fresh->status?->name ?? 'تغيير الحالة',
                };
                MovementRecord::create([
                    'employee_id' => $fresh->id,
                    'movement_type_id' => $movementType->id,
                    'movement_date' => $fresh->termination_date ?? today(),
                    'reason' => $statusChanged ? 'تغيير حالة الموظف: '.$statusLabel : 'تحديث بيانات الموظف الوظيفية',
                    'old_values' => ['status_id' => $oldStatusId, 'changes' => $changedFields],
                    'new_values' => ['status_id' => $fresh->status_id, 'termination_type' => $fresh->termination_type, 'termination_date' => optional($fresh->termination_date)->toDateString(), 'changes' => $changedFields],
                    'created_by' => auth()->id(),
                ]);
            }
        });
        $employee->refresh();
        ActivityLog::record(
            'employee_update_snapshot',
            'Employee change snapshot.',
            $employee,
            ['employee' => $oldValues, 'profile' => $oldProfile],
            ['employee' => $employee->only(array_keys($validated)), 'profile' => $employee->profileDetail?->toArray()],
            'employees',
        );
        ActivityLog::record('employee_update', "تم تحديث بيانات الموظف: {$employee->full_name}", $employee);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تحديث بيانات الموظف.',
                'employee' => $employee->load(['department', 'job', 'status']),
            ]);
        }

        return back()->with('toast', 'تم تحديث بيانات الموظف');
    }

    private function ensureEmployeeCapacity(?int $companyId): void
    {
        if (!$companyId) return;
        $subscription = CompanySubscription::with('plan')->where('company_id', $companyId)->whereIn('status', ['active','trial'])->latest('id')->first();
        if (!$subscription || !$subscription->plan) return;
        $count = Employee::where('company_id', $companyId)->whereHas('status', fn ($query) => $query->whereNotIn('name', ['منتهي الخدمة','موقوف','قيد التعيين','Terminated','Suspended','Pending']))->count();
        if ($count >= $subscription->plan->employee_limit) abort(422, "لا يمكن إضافة موظف جديد: باقتك تسمح حتى {$subscription->plan->employee_limit} موظفًا، والمستخدمون حاليًا {$count}.");
    }

    /**
     * Keep the termination classification and its effective date together.
     * Lightweight status-only actions in the employee list intentionally do
     * not overwrite existing termination data; the profile form owns it.
     */
    private function applyTerminationDetails(Request $request, array $validated, ?Employee $employee = null): array
    {
        if (! $request->has('termination_type') && ! $request->has('termination_date')) {
            return $validated;
        }

        $statusId = $validated['status_id'] ?? $employee?->status_id;
        $statusName = EmployeeStatus::find($statusId)?->name;
        $isResigned = in_array($statusName, ['Resigned', 'Resignation', 'مستقيل', 'استقالة'], true);
        $isTerminated = $isResigned || in_array($statusName, ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة'], true);
        $isSuspended = in_array($statusName, ['Suspended', 'موقوف'], true);

        if (! $isTerminated && ! $isSuspended) {
            unset($validated['termination_type'], $validated['termination_date']);
            return $validated;
        }

        if ($isSuspended) {
            if ($employee?->termination_type && blank($validated['termination_type'] ?? null)) {
                unset($validated['termination_type'], $validated['termination_date']);
                return $validated;
            }
            $validated['termination_date'] = blank($validated['termination_date'] ?? null) ? today()->toDateString() : $validated['termination_date'];
            $validated['termination_type'] = null;
            return $validated;
        }

        if ($isResigned && blank($validated['termination_type'] ?? null)) {
            $validated['termination_type'] = 'resignation';
        }

        if (blank($validated['termination_type'] ?? null)) {
            throw ValidationException::withMessages([
                'termination_type' => 'يرجى تحديد هل انتهاء الخدمة استقالة أم فصل.',
            ]);
        }

        $validated['termination_date'] = blank($validated['termination_date'] ?? null) ? today()->toDateString() : $validated['termination_date'];

        return $validated;
    }

    private function ensureOwnedEmployeeReferences(array $data, ?Employee $employee = null): void
    {
        foreach (['department_id' => Department::class, 'job_id' => Job::class, 'branch_id' => Branch::class, 'employment_type_id' => EmploymentType::class] as $key => $model) {
            if (!empty($data[$key])) $model::findOrFail($data[$key]);
        }
        if (!empty($data['manager_employee_id'])) {
            abort_if($employee && (int) $data['manager_employee_id'] === (int) $employee->id, 422, 'لا يمكن اختيار الموظف مديرًا لنفسه.');
            Employee::findOrFail($data['manager_employee_id']);
        }
    }

    public function updatePhoto(Request $request, Employee $employee)
    {
        $request->validate(['profile_photo' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp']]);
        if (!$request->hasFile('profile_photo')) {
            return back()->with('toast', 'لم يتم اختيار صورة جديدة.');
        }
        if ($employee->profile_photo_path) Storage::disk('public')->delete($employee->profile_photo_path);
        $employee->update(['profile_photo_path' => $request->file('profile_photo')->store("employees/{$employee->id}/profile", 'public')]);
        return back()->with('toast', 'تم تحديث صورة الموظف.');
    }

    public function deletePhoto(Employee $employee)
    {
        if ($employee->profile_photo_path) Storage::disk('public')->delete($employee->profile_photo_path);
        $employee->update(['profile_photo_path' => null]);
        return back()->with('toast', 'تم حذف صورة الموظف.');
    }

    private function applyNationalIdData(array $validated): array
    {
        $nationalId = preg_replace('/\D+/', '', (string) ($validated['national_id'] ?? ''));
        if ($nationalId === '') return $validated;
        if (strlen($nationalId) !== 14 || !in_array($nationalId[0], ['2', '3'], true)) {
            throw ValidationException::withMessages(['national_id' => 'الرقم القومي يجب أن يكون صحيحًا ومكونًا من 14 رقمًا.']);
        }
        $month = (int) substr($nationalId, 3, 2);
        $day = (int) substr($nationalId, 5, 2);
        $year = (int) substr($nationalId, 1, 2) + ($nationalId[0] === '3' ? 2000 : 1900);
        if (!checkdate($month, $day, $year)) {
            throw ValidationException::withMessages(['national_id' => 'تاريخ الميلاد داخل الرقم القومي غير صحيح.']);
        }
        $validated['national_id'] = $nationalId;
        $validated['birth_date'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $validated['gender'] = ((int) $nationalId[12]) % 2 === 1 ? 'ذكر' : 'أنثى';
        return $validated;
    }

    private function validateSalarySource(Request $request, bool $required, ?Employee $existing = null): void
    {
        $mode = $request->input('salary_source');
        $value = $request->input('salary_value');
        $gross = $request->input('gross_salary');

        $hasValue = $value !== null && $value !== '' && is_numeric($value) && (float) $value > 0;
        $hasGross = $gross !== null && $gross !== '' && is_numeric($gross) && (float) $gross > 0;

        // salary_source + salary_value are the authoritative form inputs.
        // Visible Net is preview-only; it is never persisted to employees.
        if (!$mode && $hasValue) {
            $mode = 'gross';
        }

        if ($required && !$hasValue && !$hasGross) {
            throw ValidationException::withMessages([
                'gross_salary' => 'يجب إدخال الراتب الإجمالي أو الراتب الصافي.',
            ]);
        }

        if (!$mode) {
            // Backward compatibility for older forms that only submitted gross_salary.
            if ($hasGross) {
                $mode = 'gross';
            } else {
                throw ValidationException::withMessages([
                    'gross_salary' => 'يجب إدخال الراتب الإجمالي أو الراتب الصافي.',
                ]);
            }
        }

        if (!in_array($mode, ['gross', 'net'], true)) {
            throw ValidationException::withMessages(['salary_source' => 'مصدر الراتب غير صالح.']);
        }

        if ($hasValue) {
            return;
        }

        if ($mode === 'gross' && $hasGross) {
            return;
        }

        throw ValidationException::withMessages([
            $mode === 'net' ? 'salary_value' : 'gross_salary' => 'قيمة الراتب المطلوبة غير موجودة.',
        ]);
    }

    private function resolveEmployeeSalary(Request $request): array
    {
        $mode = $request->input('salary_source');
        $value = $request->input('salary_value');

        if ($value === null || $value === '') {
            // Compatibility fallback for old requests.
            $value = $request->input($mode === 'net' ? 'net_salary' : 'gross_salary');
        }

        if ($mode === 'net') {
            $result = $this->payrollCalculator->calculate('net', (float) $value);
            return [(float) $result['gross'], (float) $result['net']];
        }

        $result = $this->payrollCalculator->calculate('gross', (float) $value);
        return [(float) $result['gross'], (float) $result['net']];
    }

    private function createSalaryStructureFromEmployee(Employee $employee): void
    {
        $gross = (float) $employee->gross_salary;
        if ($gross <= 0) return;

        $effectiveFrom = $employee->hire_date?->format('Y-m-d') ?? today()->format('Y-m-d');
        $structure = $this->payrollCalculator->structure([
            'salary_type' => 'gross',
            'salary_input' => $gross,
            'insurance_wage' => null,
            'no_insurance' => false,
            'fixed_deductions' => 0,
            'excluded_allowance_pct' => null,
            'personal_exemption' => null,
            'tax_treatment' => 'egyptian',
            'housing_allowance' => 0,
            'transportation_allowance' => 0,
            'meal_allowance' => 0,
            'clothing_allowance' => 0,
        ]);

        Salary::updateOrCreate(
            ['employee_id' => $employee->id, 'effective_from' => $effectiveFrom],
            array_merge([
                'salary_type' => 'gross',
                'salary_input' => $gross,
                'status' => 'Active',
                'fixed_deductions' => 0,
                'deductions' => 0,
                'notes' => 'تم إنشاؤه تلقائيًا من الراتب الإجمالي المسجل في ملف الموظف.',
            ], $this->payrollCalculator->persistFields($structure))
        );
        Salary::syncInsuredSalary((int) $employee->id);
    }

    private function syncSalaryStructureFromEmployee(Employee $employee): void
    {
        $salary = Salary::where('employee_id', $employee->id)
            ->where('status', 'Active')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        if (!$salary || (float) $employee->gross_salary <= 0) {
            if (!$salary) $this->createSalaryStructureFromEmployee($employee);
            return;
        }

        $structure = $this->payrollCalculator->structure([
            'salary_type' => 'gross',
            'salary_input' => (float) $employee->gross_salary,
            'insurance_wage' => $salary->insurance_wage,
            'no_insurance' => $salary->no_insurance,
            'fixed_deductions' => $salary->fixed_deductions,
            'excluded_allowance_pct' => $salary->excluded_allowance_pct,
            'personal_exemption' => $salary->personal_exemption,
            'tax_treatment' => $salary->tax_treatment ?: 'egyptian',
            'housing_allowance' => 0,
            'transportation_allowance' => 0,
            'meal_allowance' => 0,
            'clothing_allowance' => 0,
        ]);
        $salary->update(array_merge([
            'salary_type' => 'gross',
            'salary_input' => (float) $employee->gross_salary,
        ], $this->payrollCalculator->persistFields($structure)));
        Salary::syncInsuredSalary((int) $employee->id);
    }

    public function salaryPreview(Request $request)
    {
        $data = $request->validate([
            'salary_source' => ['required', 'in:gross,net'],
            'salary_value' => ['required', 'numeric', 'min:0.01'],
        ]);

        $result = $this->payrollCalculator->calculate(
            $data['salary_source'],
            (float) $data['salary_value']
        );

        return response()->json([
            'gross' => (float) ($result['gross'] ?? 0),
            'net' => (float) ($result['net'] ?? 0),
        ]);
    }

    public function print(Employee $employee)
    {
        $employee->load([
            'department',
            'job',
            'branch',
            'employmentType',
            'status',
            'salaries.paymentMethod',
            'insuranceRecord',
            'documents',
            'leaves.leaveType',
            'penalties',
            'movements.movementType',
        ]);

        return view('employees.print', compact('employee'));
    }
}
