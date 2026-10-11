<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\InsuranceRecord;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InsuranceController extends Controller
{
    public function index(Request $request)
    {
        $insuranceRecords = InsuranceRecord::with(['employee.department', 'employee.job', 'employee.currentSalary'])
            ->when($request->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $this->normalizeInsuranceStatus($request->status)))
            ->when($request->filled('office'), fn ($q) => $q->where('insurance_office', 'like', '%'.$request->office.'%'))
            ->orderByDesc('id')->get();
        $insuranceRecords->each(function (InsuranceRecord $record): void {
            $record->insurance_category = $this->normalizeInsuranceCategory($record->insurance_category);
            $record->status = $this->normalizeInsuranceStatus($record->status);
        });
        // Keep employees without a record visible as "غير مسجل" in the main
        // screen as well as in the registration modal. This is important for
        // a newly added employee: absence of an InsuranceRecord must not hide
        // the employee from the insurance workflow.
        $employees = Employee::whereDoesntHave('insuranceRecord')
            ->when($request->filled('search'), fn ($q) => $q->where(function ($employee) use ($request) {
                $employee->where('full_name', 'like', '%'.$request->search.'%')
                    ->orWhere('employee_code', 'like', '%'.$request->search.'%');
            }))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('status') && $this->normalizeInsuranceStatus($request->status) !== 'unregistered', fn ($q) => $q->whereRaw('1 = 0'))
            ->orderBy('full_name')->get();
        $departments = \App\Models\Department::where('is_active', 1)->orderBy('name')->get();
        $organization = DB::table('insurance_organization_settings')->where('company_id', auth()->user()->company_id)->first();
        $totalEmployees = Employee::count();
        $registered = InsuranceRecord::where('status', 'مسجل')->count();
        $unregistered = Employee::whereDoesntHave('insuranceRecord', fn ($q) => $q->where('status', 'مسجل'))->count();
        $pending = InsuranceRecord::whereIn('status', ['قيد التسجيل', 'Pending'])->count();
        $overdue = Employee::whereDoesntHave('insuranceRecord', fn ($q) => $q->where('status', 'مسجل'))
            ->whereDate('hire_date', '<', today()->subDays((int) ($organization->grace_days ?? 30)))
            ->count();
        return view('insurance.index', compact('insuranceRecords', 'employees', 'departments', 'organization', 'totalEmployees', 'registered', 'unregistered', 'pending', 'overdue'));
    }

    public function store(Request $request)
    {
        $data = $this->validateRecord($request, true);
        $data['insurance_category'] = $this->normalizeInsuranceCategory($data['insurance_category'] ?? null);
        $data['status'] = $this->normalizeInsuranceStatus($data['status'] ?? null);
        $organization = DB::table('insurance_organization_settings')->where('company_id', auth()->user()->company_id)->first();
        $employee = Employee::findOrFail($data['employee_id']);

        // Keep the deadline automatic unless the user deliberately chooses another date.
        if (empty($data['registration_deadline']) && $employee->hire_date) {
            $data['registration_deadline'] = Carbon::parse($employee->hire_date)
                ->addDays((int) ($organization->grace_days ?? 30))
                ->toDateString();
        }
        if (empty($data['insurance_office']) && !empty($organization?->office_name)) {
            $data['insurance_office'] = $organization->office_name;
        }
        $record = DB::transaction(function () use ($data) {
            $old = InsuranceRecord::where('employee_id', $data['employee_id'])->first()?->toArray();
            $record = InsuranceRecord::updateOrCreate(['employee_id' => $data['employee_id']], $data);
            $this->recordHistory($record, $old);
            return $record;
        });
        Salary::syncInsuredSalary((int) $record->employee_id);
        ActivityLog::record('insurance_update', 'Insurance record created or updated.', $record, null, $record->toArray(), 'insurance');
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Insurance record saved.', 'record' => $record->fresh(['employee.department', 'employee.currentSalary'])]);
        }
        return back()->with('toast', 'تم حفظ بيانات التأمين بنجاح.');
    }

    public function update(Request $request, InsuranceRecord $insuranceRecord)
    {
        $data = $this->validateRecord($request, false);
        $data['insurance_category'] = $this->normalizeInsuranceCategory($data['insurance_category'] ?? null);
        $data['status'] = $this->normalizeInsuranceStatus($data['status'] ?? null);
        $old = $insuranceRecord->toArray();
        DB::transaction(function () use ($insuranceRecord, $data, $old) {
            $insuranceRecord->update($data);
            $this->recordHistory($insuranceRecord, $old);
        });
        Salary::syncInsuredSalary((int) $insuranceRecord->employee_id);
        ActivityLog::record('insurance_update', 'Insurance record updated.', $insuranceRecord, $old, $insuranceRecord->fresh()->toArray(), 'insurance');
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Insurance record updated.', 'record' => $insuranceRecord->fresh(['employee.department', 'employee.currentSalary'])]);
        }
        return back()->with('toast', 'تم تحديث بيانات التأمين بنجاح.');
    }

    private function validateRecord(Request $request, bool $creating): array
    {
        return $request->validate(array_merge($creating ? ['employee_id' => ['required', 'exists:employees,id']] : [], [
            'insurance_number' => ['nullable', 'string', 'max:100'],
            'job_code' => ['nullable', 'string', 'max:50'],
            'insurance_office' => ['nullable', 'string', 'max:150'],
            'insurance_category' => ['nullable', 'string', 'max:100'],
            'insurance_start_date' => ['nullable', 'date'],
            'registration_deadline' => ['nullable', 'date', 'after_or_equal:insurance_start_date'],
            'insurance_end_date' => ['nullable', 'date', 'after_or_equal:insurance_start_date'],
            'insured_salary' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:50'],
        ]));
    }

    private function normalizeInsuranceCategory(?string $category): string
    {
        $category = trim((string) $category);
        if (in_array($category, ['workers', 'owners', 'bakeries'], true)) {
            return $category;
        }

        $bakery = json_decode('"\\u0627\\u0644\\u0639\\u0627\\u0645\\u0644\\u064a\\u0646 \\u0628\\u0627\\u0644\\u0645\\u062e\\u0627\\u0628\\u0632"', true);
        $owner = json_decode('"\\u0623\\u0635\\u062d\\u0627\\u0628 \\u0623\\u0639\\u0645\\u0627\\u0644 \\u0644\\u0647\\u0645 \\u0645\\u0646\\u0634\\u0622\\u062a"', true);
        if ($bakery && str_contains($category, $bakery)) return 'bakeries';
        if ($owner && str_contains($category, $owner)) return 'owners';
        return 'workers';
    }

    private function normalizeInsuranceStatus(?string $status): string
    {
        $status = trim((string) $status);
        if (in_array($status, ['registered', 'unregistered'], true)) {
            return $status;
        }

        $unregistered = json_decode('"\\u063a\\u064a\\u0631 \\u0645\\u0633\\u062c\\u0644"', true);
        return $unregistered && $status === $unregistered ? 'unregistered' : 'registered';
    }

    public function updateOrganization(Request $request)
    {
        $data = $request->validate([
            'office_name' => ['nullable', 'string', 'max:150'], 'establishment_number' => ['nullable', 'string', 'max:100'],
            'organization_name' => ['nullable', 'string', 'max:190'], 'address' => ['nullable', 'string', 'max:255'],
            'applicant_name' => ['nullable', 'string', 'max:150'], 'applicant_title' => ['nullable', 'string', 'max:150'],
            'applicant_insurance_number' => ['nullable', 'string', 'max:100'], 'applicant_national_id' => ['nullable', 'string', 'max:14'],
            'phone' => ['nullable', 'string', 'max:50'],
            'sector' => ['nullable', 'string', 'max:150'],
            'tax_registration_number' => ['nullable', 'string', 'max:100'],
            'commercial_registration_number' => ['nullable', 'string', 'max:100'],
            'unified_commercial_registration_number' => ['nullable', 'string', 'max:100'],
            'grace_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);
        DB::table('insurance_organization_settings')->updateOrInsert(['company_id' => auth()->user()->company_id], $data + ['updated_at' => now(), 'created_at' => now()]);
        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Organization insurance settings saved.']);
        }

        return back()->with('toast', 'تم حفظ بيانات المنشأة والتأمينات.');
    }

    public function form(InsuranceRecord $insuranceRecord)
    {
        // Keep the old route as a compatibility alias; the official editable
        // Legal-sized S1 template is now the canonical print page.
        return redirect()->route('employees.forms.s1.editable', $insuranceRecord->employee_id);
    }

    private function recordHistory(InsuranceRecord $record, ?array $old): void
    {
        DB::table('insurance_record_history')->insert([
            'insurance_record_id' => $record->id, 'employee_id' => $record->employee_id,
            'effective_from' => $record->insurance_start_date ?? now()->toDateString(),
            'snapshot' => json_encode(['before' => $old, 'after' => $record->fresh()->toArray()], JSON_THROW_ON_ERROR),
            'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
