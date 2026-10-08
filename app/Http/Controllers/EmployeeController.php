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

class EmployeeController extends Controller
{
    public function __construct(private readonly PayrollSettingsService $payrollSettings, private readonly PayrollCalculator $payrollCalculator)
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
        $employeeSummary = [
            'total' => Employee::count(),
            'active' => Employee::whereHas('status', fn ($status) => $status->whereIn('name', ['Active', 'نشط']))->count(),
            'departments' => Department::where('is_active', true)->count(),
            'contracts_due' => Employee::whereBetween('contract_end_date', [today(), today()->copy()->addDays(30)])->count(),
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
            , 'banks', 'paymentMethods', 'departments', 'jobs', 'branches', 'employmentTypes', 'statuses', 'managers', 'payrollRates', 'documentTypes'
        ));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50', 'unique:employees,national_id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:190'],
            'gross_salary' => ['nullable', 'numeric', 'min:0.01'],
            'net_salary' => ['nullable', 'numeric', 'min:0.01'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'job_id' => ['nullable', 'exists:jobs,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'hire_date' => ['nullable', 'date'],
        ], [
            'full_name.required' => 'اسم الموظف الكامل مطلوب.',
            'employee_code.unique' => 'كود الموظف مستخدم بالفعل.',
            'national_id.unique' => 'الرقم القومي مسجل لموظف آخر.',
        ]);

        $this->validateSalarySource($request, true);
        [$grossSalary, $calculatedNet] = $this->resolveEmployeeSalary($request);
        $validated['gross_salary'] = $grossSalary;
        unset($validated['net_salary']);

        $names = explode(' ', trim($validated['full_name']));
        $validated['first_name'] = $names[0] ?? '';
        $validated['last_name'] = end($names) ?: '';
        $validated['status_id'] = $request->input('status_id', 1);

        $employee = DB::transaction(function () use ($validated) {
            // Codes are generated server-side, beginning at 101, and never change afterwards.
            $lastCode = Employee::query()
                ->lockForUpdate()
                ->whereRaw("employee_code REGEXP '^[0-9]+$'")
                ->selectRaw('MAX(CAST(employee_code AS UNSIGNED)) as max_code')
                ->value('max_code');

            $validated['employee_code'] = (string) max(101, ((int) $lastCode) + 1);

            $employee = Employee::create($validated);
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
            'net_salary' => ['nullable', 'numeric', 'min:0.01'],
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

        $salaryChanged = array_key_exists('gross_salary', $validated) || array_key_exists('net_salary', $validated);
        if ($salaryChanged) {
            $this->validateSalarySource($request, false, $employee);
            [$grossSalary, $calculatedNet] = $this->resolveEmployeeSalary($request);
            $validated['gross_salary'] = $grossSalary;
            unset($validated['net_salary']);
        }

        $profileData = $validated['profile'] ?? [];
        unset($validated['profile']);
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
        $gross = $request->input('gross_salary');
        $net = $request->input('net_salary');
        $mode = $request->input('salary_source');
        $hasGross = $gross !== null && $gross !== '';
        $hasNet = $net !== null && $net !== '';

        if ($required && !$hasGross && !$hasNet) {
            throw ValidationException::withMessages([
                'gross_salary' => 'يجب إدخال الراتب الإجمالي أو الراتب الصافي.',
                'net_salary' => 'يجب إدخال الراتب الإجمالي أو الراتب الصافي.',
            ]);
        }

        if (!$mode && $hasGross && !$hasNet) $mode = 'gross';
        if (!$mode && $hasNet && !$hasGross) $mode = 'net';
        if (!$mode && $hasGross && $hasNet) {
            throw ValidationException::withMessages([
                'gross_salary' => 'حدد الراتب الذي أدخلته؛ الحقل الآخر يُحسب تلقائيًا.',
                'net_salary' => 'حدد الراتب الذي أدخلته؛ الحقل الآخر يُحسب تلقائيًا.',
            ]);
        }
        if ($mode && !in_array($mode, ['gross', 'net'], true)) {
            throw ValidationException::withMessages(['salary_source' => 'مصدر الراتب غير صالح.']);
        }
        if ($mode === 'gross' && !$hasGross) {
            throw ValidationException::withMessages(['gross_salary' => 'الراتب الإجمالي المدخل مطلوب.']);
        }
        if ($mode === 'net' && !$hasNet) {
            throw ValidationException::withMessages(['net_salary' => 'الراتب الصافي المدخل مطلوب.']);
        }

        if ($mode === 'gross' && $hasGross && $hasNet) {
            $expected = $this->payrollCalculator->calculate('gross', (float)$gross)['net'];
            if (abs((float)$net - (float)$expected) > 0.01) {
                throw ValidationException::withMessages(['net_salary' => 'الراتب الصافي لا يطابق الإجمالي المحسوب. اترك النظام يحسبه تلقائيًا.']);
            }
        }
        if ($mode === 'net' && $hasGross && $hasNet) {
            $expected = $this->payrollCalculator->calculate('net', (float)$net)['gross'];
            if (abs((float)$gross - (float)$expected) > 0.01) {
                throw ValidationException::withMessages(['gross_salary' => 'الراتب الإجمالي لا يطابق الـGross-Up المحسوب. اترك النظام يحسبه تلقائيًا.']);
            }
        }
    }

    private function resolveEmployeeSalary(Request $request): array
    {
        $mode = $request->input('salary_source');
        $gross = $request->input('gross_salary');
        $net = $request->input('net_salary');

        if ($mode === 'net' && $net !== null && $net !== '') {
            $result = $this->payrollCalculator->calculate('net', (float)$net);
            return [(float)$result['gross'], (float)$result['net']];
        }

        if ($gross !== null && $gross !== '') {
            $result = $this->payrollCalculator->calculate('gross', (float)$gross);
            return [(float)$result['gross'], (float)$result['net']];
        }

        $result = $this->payrollCalculator->calculate('net', (float)$net);
        return [(float)$result['gross'], (float)$result['net']];
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
