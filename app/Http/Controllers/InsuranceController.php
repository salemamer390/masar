<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\InsuranceRecord;
use App\Models\Salary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InsuranceController extends Controller
{
    public function index(Request $request)
    {
        $insuranceRecords = InsuranceRecord::with(['employee.department', 'employee.job', 'employee.currentSalary'])
            ->when($request->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')->get();
        $employees = Employee::whereDoesntHave('insuranceRecord')->orderBy('full_name')->get();
        $departments = \App\Models\Department::where('is_active', 1)->orderBy('name')->get();
        return view('insurance.index', compact('insuranceRecords', 'employees', 'departments'));
    }

    public function store(Request $request)
    {
        $data = $this->validateRecord($request, true);
        $record = DB::transaction(function () use ($data) {
            $old = InsuranceRecord::where('employee_id', $data['employee_id'])->first()?->toArray();
            $record = InsuranceRecord::updateOrCreate(['employee_id' => $data['employee_id']], $data);
            $this->recordHistory($record, $old);
            return $record;
        });
        Salary::syncInsuredSalary((int) $record->employee_id);
        ActivityLog::record('insurance_update', 'Insurance record created or updated.', $record, null, $record->toArray(), 'insurance');
        return back()->with('toast', 'تم حفظ بيانات التأمين بنجاح.');
    }

    public function update(Request $request, InsuranceRecord $insuranceRecord)
    {
        $data = $this->validateRecord($request, false);
        $old = $insuranceRecord->toArray();
        DB::transaction(function () use ($insuranceRecord, $data, $old) {
            $insuranceRecord->update($data);
            $this->recordHistory($insuranceRecord, $old);
        });
        Salary::syncInsuredSalary((int) $insuranceRecord->employee_id);
        ActivityLog::record('insurance_update', 'Insurance record updated.', $insuranceRecord, $old, $insuranceRecord->fresh()->toArray(), 'insurance');
        return back()->with('toast', 'تم تحديث بيانات التأمين بنجاح.');
    }

    private function validateRecord(Request $request, bool $creating): array
    {
        return $request->validate(array_merge($creating ? ['employee_id' => ['required', 'exists:employees,id']] : [], [
            'insurance_number' => ['nullable', 'string', 'max:100'],
            'job_code' => ['nullable', 'string', 'max:50'],
            'insurance_start_date' => ['nullable', 'date'],
            'insurance_end_date' => ['nullable', 'date', 'after_or_equal:insurance_start_date'],
            'insured_salary' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]));
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
