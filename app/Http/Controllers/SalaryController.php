<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Department;
use App\Models\Salary;
use App\Services\PayrollCalculator;
use App\Services\PayrollSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SalaryController extends Controller
{
    public function __construct(private readonly PayrollSettingsService $settings)
    {
    }

    public function index(PayrollCalculator $calculator, Request $request)
    {
        $salariesQuery = Salary::with(['employee.department', 'employee.job', 'paymentMethod'])
            ->where('status', 'Active');
        if ($request->filled('employee_id')) {
            $salariesQuery->where('employee_id', (int) $request->input('employee_id'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $salariesQuery->whereHas('employee', fn ($q) => $q->where('full_name', 'like', "%{$search}%")->orWhere('employee_code', 'like', "%{$search}%"));
        }
        if ($request->filled('department_id')) {
            $salariesQuery->whereHas('employee', fn ($q) => $q->where('department_id', (int) $request->input('department_id')));
        }
        $salaries = $salariesQuery
            ->orderBy('effective_from', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $employees = Employee::with(['department', 'job'])->orderBy('full_name')->get();
        $departments = Department::where('is_active', 1)->orderBy('name')->get();
        $paymentMethods = PaymentMethod::orderBy('name')->get();
        $allowanceRate = $this->settings->allowanceRate();
        $payrollSettings = [
            'employee_insurance_rate' => $this->settings->employeeInsuranceRate() * 100,
            'employer_insurance_rate' => $this->settings->employerInsuranceRate() * 100,
            'martyrs_fund_rate' => $this->settings->martyrsFundRate() * 100,
            'insurance_minimum' => $this->settings->insuranceMinimum(),
            'insurance_maximum' => $this->settings->insuranceMaximum(),
            'personal_exemption' => $this->settings->personalExemption(),
            'hours_divisor' => $this->settings->hoursDivisor(),
            'days_divisor' => $this->settings->daysDivisor(),
            'day_multiplier' => $this->settings->dayOvertimeMultiplier(),
            'night_multiplier' => $this->settings->nightOvertimeMultiplier(),
        ];
        $allowanceDistribution = $this->settings->allowanceDistribution();
        $selectedEmployee = $request->filled('employee_id') ? $employees->firstWhere('id', (int) $request->input('employee_id')) : null;

        // العرض دائماً بحسب منطق الحساب الحالي (بدون حفظ). لحفظ النتائج استخدم زر «إعادة حساب الهياكل».
        foreach ($salaries as $salary) {
            $this->syncBreakdown($salary, $calculator, false);
        }

        return view('salaries.index', compact('salaries', 'employees', 'departments', 'paymentMethods', 'allowanceRate', 'payrollSettings', 'allowanceDistribution', 'selectedEmployee'));
    }

    public function store(Request $request, PayrollCalculator $calculator)
    {
        $validated = $this->validateSalary($request);
        $employee = Employee::findOrFail((int) $validated['employee_id']);
        if ((float) $employee->gross_salary <= 0) {
            throw ValidationException::withMessages(['employee_id' => 'لا يوجد راتب إجمالي محفوظ في ملف الموظف. أدخل الراتب من ملف الموظف أولًا.']);
        }
        $validated['salary_type'] = 'gross';
        $validated['salary_input'] = (float) $employee->gross_salary;
        $data = $this->prepareData($validated);

        Salary::where('employee_id', $data['employee_id'])
            ->where('effective_from', '<', $data['effective_from'])
            ->where('status', 'Active')
            ->update(['status' => 'Historical']);

        $structure = $calculator->structure($this->structureInput($data));
        $salary = Salary::updateOrCreate(
            ['employee_id' => $data['employee_id'], 'effective_from' => $data['effective_from']],
            array_merge($data, $calculator->persistFields($structure), ['status' => 'Active'])
        );
        Salary::syncInsuredSalary((int)$salary->employee_id);

        $emp = Employee::find($data['employee_id']);
        ActivityLog::record(
            'salary_update',
            "تم اعتماد هيكل راتب للموظف ({$emp?->full_name}) وفق إجمالي الراتب المعتمد.",
            $salary
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم حفظ هيكل الراتب وحساب التأمينات والضريبة وحصة صاحب العمل بنجاح.',
                'salary' => $salary->fresh(['employee.department', 'employee.job', 'paymentMethod']),
            ]);
        }

        return back()->with('toast', 'تم حفظ هيكل الراتب وحساب التأمينات والضريبة وحصة صاحب العمل بنجاح.');
    }

    public function update(Request $request, Salary $salary, PayrollCalculator $calculator)
    {
        $validated = $this->validateSalary($request);
        $employee = $salary->employee;
        if ((float) $employee?->gross_salary <= 0) {
            throw ValidationException::withMessages(['salary_input' => 'لا يوجد راتب إجمالي محفوظ في ملف الموظف.']);
        }
        $validated['employee_id'] = $employee->id;
        $validated['salary_type'] = 'gross';
        $validated['salary_input'] = (float) $employee->gross_salary;
        $data = $this->prepareData($validated, $salary);

        $structure = $calculator->structure($this->structureInput($data));
        $salary->update(array_merge($data, $calculator->persistFields($structure)));
        Salary::syncInsuredSalary((int)$salary->employee_id);

        ActivityLog::record('salary_update', "تم تعديل هيكل راتب الموظف ({$salary->employee?->full_name}).", $salary);
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الهيكل وإعادة حساب جميع بنود الراتب.',
                'salary' => $salary->fresh(['employee.department', 'employee.job', 'paymentMethod']),
            ]);
        }

        return back()->with('toast', 'تم تعديل الهيكل وإعادة حساب جميع بنود الراتب.');
    }

    public function destroy(Salary $salary, Request $request)
    {
        $employee = $salary->employee;
        $salary->update(['status' => 'Inactive']);
        Salary::syncInsuredSalary((int)$salary->employee_id);
        ActivityLog::record('salary_delete', "تم إيقاف هيكل راتب الموظف ({$employee?->full_name}).", $salary);
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'تم إيقاف هيكل الراتب.', 'salary_id' => $salary->id])
            : back()->with('toast', 'تم إيقاف هيكل الراتب.');
    }

    // يعيد حساب وحفظ كل الهياكل السارية بمنطق الضريبة الحالي (مفيد بعد أي تعديل على الشرائح أو النسب).
    public function recalculate(PayrollCalculator $calculator)
    {
        $count = 0;
        foreach (Salary::where('status', 'Active')->get() as $salary) {
            $this->syncBreakdown($salary, $calculator, true);
            Salary::syncInsuredSalary((int)$salary->employee_id);
            $count++;
        }
        ActivityLog::record('salary_recalculate', "تمت إعادة حساب {$count} هيكل راتب ساري.");
        return back()->with('toast', "تمت إعادة حساب {$count} هيكل راتب وحفظ النتائج. مسيرات الرواتب المعتمدة أو المصروفة لم تتغير.");
    }

    public function preview(Request $request, PayrollCalculator $calculator)
    {
        $data = $request->validate([
            'salary_type' => ['required', 'in:net,gross'],
            'salary_input' => ['required', 'numeric', 'min:0.01'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transportation_allowance' => ['nullable', 'numeric', 'min:0'],
            'meal_allowance' => ['nullable', 'numeric', 'min:0'],
            'clothing_allowance' => ['nullable', 'numeric', 'min:0'],
            'fixed_deductions' => ['nullable', 'numeric', 'min:0'],
            'insurance_wage' => ['nullable', 'numeric', 'min:0'],
            'no_insurance' => ['nullable', 'boolean'],
            'excluded_allowance_pct' => ['nullable', 'numeric'],
            'personal_exemption' => ['nullable', 'numeric'],
            'tax_treatment' => ['nullable', 'in:egyptian,other'],
        ]);

        $data['personal_exemption'] = $this->settings->personalExemption();
        $structure = $calculator->structure($data);

        return response()->json(array_merge($structure, [
            'employee_rate' => $this->settings->employeeInsuranceRate() * 100,
            'employer_rate' => $this->settings->employerInsuranceRate() * 100,
            'martyrs_rate' => $this->settings->martyrsFundRate() * 100,
        ]));
    }

    private function validateSalary(Request $request): array
    {
        return $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'effective_from' => ['required', 'date'],
            'salary_type' => ['nullable', 'in:net,gross'],
            'salary_input' => ['nullable', 'numeric', 'min:0.01'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transportation_allowance' => ['nullable', 'numeric', 'min:0'],
            'meal_allowance' => ['nullable', 'numeric', 'min:0'],
            'clothing_allowance' => ['nullable', 'numeric', 'min:0'],
            'fixed_deductions' => ['nullable', 'numeric', 'min:0'],
            'insurance_wage' => ['nullable', 'numeric', 'min:0'],
            'no_insurance' => ['nullable', 'boolean'],
            'excluded_allowance_pct' => ['nullable', 'numeric'],
            'personal_exemption' => ['nullable', 'numeric'],
            'tax_treatment' => ['nullable', 'in:egyptian,other'],
            'payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function prepareData(array $data, ?Salary $existing = null): array
    {
        $salaryInput = (float)($data['salary_input'] ?? $existing?->salary_input ?? 0);
        $salaryType = $data['salary_type'] ?? $existing?->salary_type ?? 'gross';

        return [
            'employee_id' => $data['employee_id'] ?? $existing?->employee_id,
            'effective_from' => $data['effective_from'] ?? $existing?->effective_from,
            'salary_type' => $salaryType,
            'salary_input' => $salaryInput,
            'basic_salary' => $existing?->basic_salary ?? 0,
            'allowances' => 0,
            'allowance_limit_pct' => $this->settings->allowanceRate(),
            'allowance_limit' => 0,
            'allowance_remaining' => 0,
            'housing_allowance' => 0,
            'transportation_allowance' => 0,
            'meal_allowance' => 0,
            'clothing_allowance' => 0,
            'other_allowances' => 0,
            'deductions' => $data['fixed_deductions'] ?? 0,
            'fixed_deductions' => $data['fixed_deductions'] ?? ($existing?->fixed_deductions ?? 0),
            'insurance_wage' => array_key_exists('insurance_wage', $data) ? (($data['insurance_wage'] ?? '') === '' ? null : $data['insurance_wage']) : $existing?->insurance_wage,
            'no_insurance' => array_key_exists('no_insurance', $data) ? (bool)$data['no_insurance'] : (bool)($existing?->no_insurance ?? false),
            'excluded_allowance_pct' => $this->settings->allowanceRate(),
            'personal_exemption' => $this->settings->personalExemption(),
            'tax_treatment' => $data['tax_treatment'] ?? ($existing?->tax_treatment ?? 'egyptian'),
            'payment_method_id' => array_key_exists('payment_method_id', $data) ? $data['payment_method_id'] : $existing?->payment_method_id,
            'notes' => $data['notes'] ?? ($existing?->notes ?? null),
        ];
    }

    private function structureInput(array $data): array
    {
        return [
            'salary_type' => $data['salary_type'],
            'salary_input' => $data['salary_input'],
            'insurance_wage' => $data['insurance_wage'],
            'no_insurance' => $data['no_insurance'],
            'excluded_allowance_pct' => $data['excluded_allowance_pct'],
            'personal_exemption' => $data['personal_exemption'],
            'tax_treatment' => $data['tax_treatment'],
            'housing_allowance' => $data['housing_allowance'],
            'transportation_allowance' => $data['transportation_allowance'],
            'meal_allowance' => $data['meal_allowance'],
            'clothing_allowance' => $data['clothing_allowance'],
            'fixed_deductions' => $data['fixed_deductions'],
        ];
    }

    private function syncBreakdown(Salary $salary, PayrollCalculator $calculator, bool $save = true): array
    {
        $structure = $calculator->structureForSalary($salary);
        $fields = $calculator->persistFields($structure);
        if ($save) $salary->update($fields);
        else $salary->setRawAttributes(array_merge($salary->getAttributes(), $fields), true);
        return $structure;
    }
}
