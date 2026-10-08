<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Bank;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Job;
use App\Models\PaymentMethod;
use App\Models\Salary;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\PayrollSettingsService;
use App\Services\PayrollCalculator;
use App\Services\EmployeeProfileCompletionService;

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
        $query = Employee::with(['department', 'job', 'status'])->orderBy('id', 'asc');

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

        return view('employees.index', compact('employees', 'departments', 'statuses', 'employeeSummary'));
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

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'employee_code' => ['nullable', 'string', 'max:50', 'unique:employees,employee_code'],
            'national_id' => ['nullable', 'string', 'max:50', 'unique:employees,national_id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'gross_salary' => ['nullable', 'numeric', 'min:0.01'],
            'salary_source' => ['nullable', 'in:gross,net'],
            'salary_value' => ['nullable', 'numeric', 'min:0.01'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_id' => ['nullable', 'exists:jobs,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'hire_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
        ], [
            'full_name.required' => 'اسم الموظف الكامل مطلوب.',
            'employee_code.unique' => 'كود الموظف مستخدم بالفعل.',
            'national_id.unique' => 'الرقم القومي مسجل لموظف آخر.',
        ]);

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
            $employee->profileDetail()->create([]);
            $this->createSalaryStructureFromEmployee($employee);
            return $employee;
        });
        ActivityLog::record('employee_create', "تمت إضافة موظف جديد: {$employee->full_name}", $employee);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة الموظف بنجاح.',
                'employee' => $employee,
            ]);
        }

        return redirect()->route('employees.show', $employee)->with('toast', 'تمت إضافة الموظف بنجاح.');
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:50', 'unique:employees,national_id,'.$employee->id],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
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
            'manager_employee_id' => ['nullable', 'exists:employees,id', Rule::notIn([$employee->id])],
            'hire_date' => ['nullable', 'date'],
            'contract_start_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'contract_duration' => ['nullable', 'string', 'max:100'],
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

        $salaryChanged = $request->filled('salary_source') && $request->filled('salary_value');
        if ($salaryChanged) {
            $this->validateSalarySource($request, false, $employee);
            [$grossSalary, $calculatedNet] = $this->resolveEmployeeSalary($request);
            $validated['gross_salary'] = $grossSalary;
            unset($validated['salary_source'], $validated['salary_value']);
        }

        $profileData = $validated['profile'] ?? [];
        unset($validated['profile']);
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
        DB::transaction(function () use ($employee, $validated, $profileData, $salaryChanged): void {
            $employee->update($validated);
            $employee->profileDetail()->updateOrCreate([], $profileData);
            if ($salaryChanged) {
                $this->syncSalaryStructureFromEmployee($employee->fresh());
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
                'employee' => $employee,
            ]);
        }

        return back()->with('toast', 'تم تحديث بيانات الموظف');
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
