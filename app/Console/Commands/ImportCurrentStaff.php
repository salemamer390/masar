<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmployeeProfileDetail;
use App\Models\EmploymentType;
use App\Models\Job;
use App\Models\Salary;
use App\Models\Company;
use App\Services\PayrollCalculator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCurrentStaff extends Command
{
    protected $signature = 'tazamun:import-current-staff {file} {--company= : Target company ID} {--dry-run}';
    protected $description = 'Import approved current-staff JSON including employee and salary structures.';

    public function handle(PayrollCalculator $calculator): int
    {
        $file = (string) $this->argument('file');
        $companyId = (int) $this->option('company');
        if (!$companyId || !Company::whereKey($companyId)->exists()) { $this->error('A valid --company ID is required.'); return self::FAILURE; }
        $staff = json_decode((string) @file_get_contents($file), true);
        if (!is_array($staff)) { $this->error('A valid JSON array is required.'); return self::FAILURE; }
        $this->table(['Code', 'Name', 'Hire date', 'Net', 'Action'], array_map(function ($row) {
            $existing = Employee::where('company_id', $companyId)->where(function ($q) use ($row) { $q->where('national_id', $row['national_id'])->orWhere('email', $row['email'] ?: '__none__'); })->first();
            return [$existing?->employee_code ?? 'Auto', $row['full_name'], $row['hire_date'], $row['net_salary'], $existing ? 'update' : 'create'];
        }, $staff));
        if ($this->option('dry-run')) { $this->info('Validated '.count($staff).' employee records; no changes made.'); return self::SUCCESS; }

        DB::transaction(function () use ($staff, $calculator, $companyId) {
            foreach ($staff as $row) $this->import($row, $calculator, $companyId);
        });
        $this->info('Imported '.count($staff).' employee records and their salary structures.');
        return self::SUCCESS;
    }

    private function import(array $row, PayrollCalculator $calculator, int $companyId): void
    {
        $department = Department::firstOrCreate(['name' => $row['department'], 'company_id' => $companyId], ['is_active' => true]);
        $job = Job::firstOrCreate(['title' => $row['job_title'], 'company_id' => $companyId], ['is_active' => true]);
        $branch = Branch::firstOrCreate(['name' => $row['work_location'], 'company_id' => $companyId], ['is_active' => true]);
        $type = EmploymentType::firstOrCreate(['name' => 'Full Time']);
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        $parts = preg_split('/\s+/u', trim($row['full_name']));
        $companyPayload = ['company_id' => $companyId];
        $notes = ["Imported source: current staff dataset.", "Direct manager: {$row['manager']}.", "Work mode: {$row['work_mode']}; schedule: {$row['schedule']}; {$row['daily_hours']} hours/day.", "Annual leave entitlement: {$row['annual_leave_days']} days.", "Contract attachment reported: {$row['contract_attachment']}."];
        if ($row['contract_end_date'] && Carbon::parse($row['contract_end_date'])->lte(Carbon::parse($row['contract_start_date']))) {
            $notes[] = "Contract end date in source is invalid and was not imported: {$row['contract_end_date']}.";
        }
        if (Carbon::parse($row['hire_date'])->isFuture()) $notes[] = "Review source: hire date is in the future ({$row['hire_date']}).";
        $employee = Employee::where('company_id', $companyId)->where(function ($q) use ($row) { $q->where('national_id', $row['national_id'])->orWhere('email', $row['email'] ?: '__none__'); })->first();
        $payload = ['first_name' => $parts[0], 'middle_name' => $parts[1] ?? null, 'last_name' => count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null, 'full_name' => $row['full_name'], 'national_id' => $row['national_id'], 'phone' => $row['phone'], 'email' => $row['email'] ?: null, 'department_id' => $department->id, 'job_id' => $job->id, 'branch_id' => $branch->id, 'employment_type_id' => $type->id, 'status_id' => $status->id, 'hire_date' => $row['hire_date'], 'contract_start_date' => $row['contract_start_date'], 'contract_end_date' => $row['contract_end_date'] && Carbon::parse($row['contract_end_date'])->gt(Carbon::parse($row['contract_start_date'])) ? $row['contract_end_date'] : null, 'contract_duration' => $row['contract_duration'], 'notes' => implode("\n", $notes)];
        if (!$employee) {
            $payload['employee_code'] = $this->nextEmployeeCode();
        }
        $before = $employee?->toArray();
        $payload = $companyPayload + $payload;
        $employee ? $employee->update($payload) : $employee = Employee::create($payload);
        EmployeeProfileDetail::updateOrCreate(['employee_id' => $employee->id], [
            'nationality' => $row['nationality'] ?? 'مصري',
            'work_pattern' => $row['work_mode'] ?? null,
            'weekly_work_days' => $row['weekly_work_days'] ?? null,
            'daily_work_hours' => $row['daily_hours'] ?? null,
            'working_hours_text' => $row['schedule'] ?? null,
            'contract_type' => $row['contract_type'] ?? null,
            'probation_period' => $row['probation_period'] ?? null,
            'annual_leave_days' => $row['annual_leave_days'] ?? null,
            'contract_attachment_status' => $row['contract_attachment'] ?? null,
            'currency' => $row['currency'] ?? 'EGP',
        ]);
        $salaryInput = (float) $row['net_salary']; $allowances = (float) $row['allowances'];
        $structure = $calculator->structure(['salary_type' => 'net', 'salary_input' => $salaryInput, 'housing_allowance' => $allowances]);
        Salary::where('employee_id', $employee->id)->where('effective_from', '<', $row['hire_date'])->where('status', 'Active')->update(['status' => 'Historical']);
        $salary = Salary::updateOrCreate(['employee_id' => $employee->id, 'effective_from' => $row['hire_date']], array_merge(['salary_type' => 'net', 'salary_input' => $salaryInput, 'housing_allowance' => $allowances, 'transportation_allowance' => 0, 'meal_allowance' => 0, 'clothing_allowance' => 0, 'other_allowances' => 0, 'deductions' => 0, 'fixed_deductions' => 0, 'status' => 'Active', 'notes' => "Imported total allowance {$allowances} EGP. Distribution needs HR review."], $calculator->persistFields($structure)));
        ActivityLog::record($before ? 'employee_import_update' : 'employee_import_create', 'Employee and salary imported from approved staff data.', $employee, $before, $employee->fresh()->toArray(), 'employees');
        ActivityLog::record('salary_import', 'Salary structure imported from approved staff data.', $salary, null, $salary->toArray(), 'salary');
    }

    private function nextEmployeeCode(): string
    {
        $lastCode = Employee::query()
            ->lockForUpdate()
            ->whereRaw("employee_code REGEXP '^[0-9]+$'")
            ->selectRaw('MAX(CAST(employee_code AS UNSIGNED)) as max_code')
            ->value('max_code');

        return (string) max(101, ((int) $lastCode) + 1);
    }
}
