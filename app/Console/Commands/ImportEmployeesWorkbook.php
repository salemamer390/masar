<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\Job;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ImportEmployeesWorkbook extends Command
{
    protected $signature = 'tazamun:import-employees {file : Path to the XLSX workbook} {--dry-run : Validate and report without writing}';
    protected $description = 'Import the Employees Data worksheet from the Tazamun HR workbook.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("Workbook not found: {$file}");
            return self::FAILURE;
        }

        try {
            $rows = $this->employeeRows($file);
        } catch (\Throwable $exception) {
            $this->error('Unable to read workbook: '.$exception->getMessage());
            return self::FAILURE;
        }

        if ($rows === []) {
            $this->warn('No employee rows found in worksheet 4.');
            return self::SUCCESS;
        }

        $this->table(['Code', 'Name', 'Department', 'Job', 'Hire date', 'Status'], array_map(fn (array $row) => [
            $row['employee_code'], $row['full_name'], $row['department_name'] ?: '—', $row['job_title'] ?: '—', $row['hire_date'] ?: '—', $row['status_name'],
        ], $rows));

        if ($this->option('dry-run')) {
            $this->info(count($rows).' employee row(s) validated. No database changes were made.');
            return self::SUCCESS;
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($rows, &$created, &$updated): void {
            foreach ($rows as $row) {
                $departmentId = $row['department_name'] ? Department::firstOrCreate(['name' => $row['department_name']], ['is_active' => true])->id : null;
                $jobId = $row['job_title'] ? Job::firstOrCreate(['title' => $row['job_title']], ['is_active' => true])->id : null;
                $statusId = EmployeeStatus::firstOrCreate(['name' => $row['status_name']])->id;

                $employee = Employee::where('employee_code', $row['employee_code'])->first();
                if ($employee && $row['national_id'] && $employee->national_id !== $row['national_id']) {
                    $owner = Employee::where('national_id', $row['national_id'])->whereKeyNot($employee->id)->exists();
                    if ($owner) {
                        throw new \RuntimeException("National ID in workbook is already assigned to another employee: {$row['employee_code']}");
                    }
                }
                if (!$employee && $row['national_id']) {
                    $employee = Employee::where('national_id', $row['national_id'])->first();
                }

                $payload = [
                    'employee_code' => $row['employee_code'], 'first_name' => $row['first_name'], 'middle_name' => $row['middle_name'],
                    'last_name' => $row['last_name'], 'full_name' => $row['full_name'], 'national_id' => $row['national_id'], 'phone' => $row['phone'],
                    'department_id' => $departmentId, 'job_id' => $jobId, 'status_id' => $statusId, 'hire_date' => $row['hire_date'],
                    'notes' => $row['notes'],
                ];
                if ($employee) {
                    $before = $employee->toArray();
                    $employee->update($payload);
                    $updated++;
                    ActivityLog::record('employee_import_update', 'Employee updated from workbook import.', $employee, $before, $employee->fresh()->toArray(), 'employees');
                } else {
                    $employee = Employee::create($payload);
                    $created++;
                    ActivityLog::record('employee_import_create', 'Employee created from workbook import.', $employee, null, $employee->toArray(), 'employees');
                }
            }
        });

        $this->info("Import complete: {$created} created, {$updated} updated.");
        return self::SUCCESS;
    }

    private function employeeRows(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new \RuntimeException('File is not a readable XLSX archive.');
        }
        try {
            $shared = $this->sharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
            $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet4.xml'));
            if (!$xml) throw new \RuntimeException('Employee worksheet is missing.');
            $rows = [];
            $sheetData = $xml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->sheetData;
            foreach ($sheetData->row as $row) {
                if ((int) $row['r'] < 6) continue;
                $cells = $this->cells($row, $shared);
                $code = trim((string) ($cells['A'] ?? ''));
                $name = trim((string) ($cells['B'] ?? ''));
                if ($code === '' || $name === '') continue;
                $parts = preg_split('/\s+/u', $name) ?: [];
                $notes = array_filter([
                    isset($cells['I']) && $cells['I'] !== '' ? 'Excel leave date: '.$this->excelDate($cells['I']) : null,
                    isset($cells['J']) && $cells['J'] !== '' ? 'Excel custom shift start: '.$cells['J'] : null,
                    isset($cells['K']) && $cells['K'] !== '' ? 'Excel custom shift end: '.$cells['K'] : null,
                    isset($cells['L']) && $cells['L'] !== '' ? 'Excel annual leave balance: '.$cells['L'] : null,
                ]);
                $rows[] = [
                    'employee_code' => $code, 'full_name' => $name, 'first_name' => $parts[0],
                    'middle_name' => $parts[1] ?? null, 'last_name' => count($parts) > 2 ? implode(' ', array_slice($parts, 2)) : null,
                    'department_name' => trim((string) ($cells['C'] ?? '')), 'job_title' => trim((string) ($cells['D'] ?? '')),
                    'hire_date' => $this->excelDate($cells['E'] ?? null), 'national_id' => $this->nullable($cells['F'] ?? null),
                    'phone' => $this->nullable($cells['G'] ?? null), 'status_name' => $this->normaliseStatus($cells['H'] ?? null),
                    'notes' => $notes ? implode("\n", $notes) : null,
                ];
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function sharedStrings(string $xml): array
    {
        if ($xml === '') return [];
        $document = simplexml_load_string($xml);
        $document->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        return array_map(fn ($node) => (string) $node, $document->xpath('//x:si') ?: []);
    }

    private function cells(\SimpleXMLElement $row, array $shared): array
    {
        $result = [];
        foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
            $column = preg_replace('/\d+/', '', (string) $cell['r']);
            $children = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $value = (string) ($children->v ?? '');
            if ((string) $cell['t'] === 's') $value = $shared[(int) $value] ?? '';
            if ((string) $cell['t'] === 'inlineStr') $value = (string) $children->is->t;
            $result[$column] = trim($value);
        }
        return $result;
    }

    private function excelDate(?string $value): ?string
    {
        if (!$value || !is_numeric($value)) return null;
        return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $value))->toDateString();
    }

    private function normaliseStatus(?string $status): string
    {
        return match (trim((string) $status)) {
            'نشط', 'Active' => 'Active', 'إجازة', 'Leave' => 'Leave', 'موقوف', 'Suspended' => 'Suspended',
            'منتهي', 'Terminated' => 'Terminated', default => 'Pending',
        };
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
