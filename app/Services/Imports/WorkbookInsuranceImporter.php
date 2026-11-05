<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\InsuranceRecord;
use App\Models\Salary;
use Carbon\Carbon;

/**
 * Imports the insurance sheet found in an HR workbook: matches each row to an
 * employee (code, insurance number, then name) and upserts the employee's
 * InsuranceRecord. Rows that cannot be matched or parsed are never guessed -
 * they are reported (and parked for review by the caller).
 */
class WorkbookInsuranceImporter
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
    ) {}

    public const MAPPABLE_FIELDS = ['code' => 'كود الموظف', 'name' => 'اسم الموظف', 'insurance_number' => 'الرقم التأميني', 'insured_salary' => 'الأجر التأميني', 'office' => 'مكتب التأمينات', 'category' => 'الفئة التأمينية', 'job_code' => 'كود الوظيفة', 'start' => 'تاريخ التسجيل', 'end' => 'تاريخ انتهاء الاشتراك', 'status' => 'الحالة التأمينية', 'notes' => 'ملاحظات'];

    /** Detected header row + field map for one sheet, exposed for the review UI. */
    public function detectConfig(array $rows): ?array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if (str_contains($key, 'تأمين') || str_contains($key, 'insurance')) {
                return ['header_row' => (int) $number, 'headers' => array_map(static fn ($v) => (string) $v, $row), 'map' => $this->mapHeaders($row)];
            }
        }

        return null;
    }

    public function import(string $path, string $extension, ?array $onlySheets = null, ?array $configs = null): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []];
        foreach ($this->reader->readAll($path, $extension) as $sheet) {
            if ($onlySheets !== null && !in_array((string) ($sheet['name'] ?? ''), $onlySheets, true)) continue;
            $config = $configs[$sheet['name']] ?? null;
            if ($config !== null) {
                $headerRow = (int) ($config['header_row'] ?? -1);
                $headers = $sheet['rows'][$headerRow] ?? [];
                $rows = array_filter($sheet['rows'] ?? [], fn ($unused, $line) => $line > $headerRow, ARRAY_FILTER_USE_BOTH);
                $map = array_map('intval', $config['map'] ?? []);
            } else {
                [$headers, $rows] = $this->splitHeader($sheet['rows'] ?? []);
                $map = $this->mapHeaders($headers);
                if (!$this->isInsuranceSheet($map)) {
                    continue;
                }
            }
            foreach ($rows as $line => $row) {
                try {
                    $this->importRow($row, $map);
                    $result['imported']++;
                } catch (\Throwable $exception) {
                    $result['skipped']++;
                    if (count($result['errors']) < 25) {
                        $result['errors'][] = "{$sheet['name']} / الصف {$line}: {$exception->getMessage()}";
                    }
                    if (count($result['failures']) < 100) {
                        $result['failures'][] = [
                            'sheet' => $sheet['name'],
                            'line' => $line,
                            'headers' => array_map(static fn ($v) => (string) $v, $headers),
                            'values' => array_map(static fn ($v) => (string) $v, $row),
                            'errors' => [$exception->getMessage()],
                        ];
                    }
                }
            }
        }

        return $result;
    }

    private function importRow(array $row, array $map): void
    {
        $cell = static fn (string $key): string => trim((string) ($row[$map[$key] ?? -1] ?? ''));

        $employee = $this->employee($cell('code'), $cell('name'), $cell('insurance_number'));
        if (!$employee) {
            throw new \RuntimeException('الموظف غير موجود أو الاسم لا يطابق الكود.');
        }

        $data = [];
        if ($cell('insurance_number') !== '') {
            if (mb_strlen($cell('insurance_number')) > 100) {
                throw new \RuntimeException('الرقم التأميني أطول من 100 حرف.');
            }
            $data['insurance_number'] = $cell('insurance_number');
        }
        if ($cell('insured_salary') !== '') {
            $salary = $this->money($cell('insured_salary'));
            if ($salary === null || $salary <= 0 || $salary > 10000000) {
                throw new \RuntimeException('الأجر التأميني غير صالح «' . $cell('insured_salary') . '».');
            }
            $data['insured_salary'] = $salary;
        }
        foreach ([
            'insurance_office' => ['office', 150],
            'insurance_category' => ['category', 100],
            'job_code' => ['job_code', 50],
            'notes' => ['notes', 500],
        ] as $field => [$key, $max]) {
            if ($cell($key) !== '') {
                if (mb_strlen($cell($key)) > $max) {
                    throw new \RuntimeException("قيمة {$field} أطول من {$max} حرفًا.");
                }
                $data[$field] = $cell($key);
            }
        }
        foreach (['insurance_start_date' => 'start', 'insurance_end_date' => 'end'] as $field => $key) {
            if ($cell($key) !== '') {
                $date = $this->date($cell($key));
                if (!$date) {
                    throw new \RuntimeException('تاريخ غير صالح «' . $cell($key) . '».');
                }
                $data[$field] = $date;
            }
        }
        if ($cell('category') !== '') {
            $data['insurance_category'] = $this->category($cell('category'));
        }
        if ($cell('status') !== '') {
            $status = $this->status($cell('status'));
            if ($status === null) {
                throw new \RuntimeException('الحالة التأمينية غير معروفة «' . $cell('status') . '» (مسجل / غير مسجل).');
            }
            $data['status'] = $status;
        }

        if ($data === []) {
            throw new \RuntimeException('لا توجد بيانات تأمينية قابلة للاستيراد في هذا الصف.');
        }

        // Same defaults as InsuranceController: unregistered ends today, the
        // registration deadline follows the hire date plus the grace period,
        // and a blank office falls back to the company's insurance office.
        $organization = \Illuminate\Support\Facades\DB::table('insurance_organization_settings')->where('company_id', $employee->company_id)->first();
        if (($data['status'] ?? null) === 'unregistered' && empty($data['insurance_end_date'])) {
            $data['insurance_end_date'] = Carbon::today()->toDateString();
        }
        if (empty($data['insurance_office']) && !empty($organization?->office_name)) {
            $data['insurance_office'] = $organization->office_name;
        }
        if (empty($data['registration_deadline']) && $employee->hire_date) {
            $data['registration_deadline'] = Carbon::parse($employee->hire_date)
                ->addDays((int) ($organization->grace_days ?? 30))
                ->toDateString();
        }

        $record = InsuranceRecord::updateOrCreate(['employee_id' => $employee->id], $data);
        if (!empty($data['insurance_number'])) {
            Employee::whereKey($employee->id)->update(['insurance_number' => $data['insurance_number']]);
        }
        if (empty($data['insured_salary'])) {
            Salary::syncInsuredSalary((int) $record->employee_id);
        }
    }

    /** An insurance sheet carries an insurance-number column plus at least one insurance-specific field. */
    private function isInsuranceSheet(array $map): bool
    {
        if (!isset($map['insurance_number'])) {
            return false;
        }
        foreach (['insured_salary', 'office', 'category', 'status', 'job_code'] as $marker) {
            if (isset($map[$marker])) {
                return true;
            }
        }

        return false;
    }

    private function splitHeader(array $rows): array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if (str_contains($key, 'تأمين') || str_contains($key, 'insurance')) {
                return [$row, array_filter($rows, fn ($unused, $line) => $line > $number, ARRAY_FILTER_USE_BOTH)];
            }
        }

        return [[], []];
    }

    private function mapHeaders(array $headers): array
    {
        $aliases = [
            'code' => ['كود الموظف', 'الكود', 'رقم الموظف', 'employee code', 'code'],
            'name' => ['اسم الموظف', 'الاسم', 'employee name', 'name'],
            'insurance_number' => ['الرقم التأميني', 'رقم التأمين', 'رقم التأمينات', 'رقم التأمين الاجتماعي', 'insurance number', 'insurance no', 'social insurance number'],
            'insured_salary' => ['الأجر التأميني', 'أجر الاشتراك', 'مبلغ الاشتراك', 'اشتراك التأمينات', 'الأجر التأميني الشهري', 'insurance wage', 'insured salary', 'insurance subscription'],
            'office' => ['مكتب التأمينات', 'مكتب التأمين', 'جهة التأمين', 'insurance office'],
            'category' => ['الفئة التأمينية', 'فئة الاشتراك', 'الفئة', 'insurance category'],
            'job_code' => ['كود الوظيفة التأميني', 'كود الوظيفة', 'job code'],
            'start' => ['تاريخ التسجيل', 'تاريخ الاشتراك', 'تاريخ بداية الاشتراك', 'insurance start date', 'registration date'],
            'end' => ['تاريخ انتهاء الاشتراك', 'تاريخ نهاية الاشتراك', 'insurance end date'],
            'status' => ['الحالة التأمينية', 'حالة التسجيل', 'حالة التأمين', 'insurance status'],
            'notes' => ['ملاحظات', 'notes'],
        ];
        $map = [];
        foreach ($headers as $index => $header) {
            $key = $this->normalise($header);
            foreach ($aliases as $field => $values) {
                if (in_array($key, array_map(fn ($value) => $this->normalise($value), $values), true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Company-scoped matching (the Employee global scope enforces the tenant):
     * a supplied code must exist in the company (an unknown code is rejected,
     * never swapped for another employee), a code match must agree with the
     * name, the insurance number must not belong to a different employee, and
     * a bare name or insurance number only matches when it identifies exactly
     * one employee - duplicates and conflicts are rejected, never silently
     * picked.
     */
    private function employee(string $code, string $name, string $insuranceNumber): ?Employee
    {
        $byCode = $code !== '' ? Employee::where('employee_code', $code)->first() : null;
        // A supplied code is a load-bearing identifier: an unknown code is
        // rejected with an explicit reason, never swapped for a name or
        // insurance-number match on another employee.
        if ($code !== '' && !$byCode) {
            throw new \RuntimeException('كود الموظف «' . $code . '» غير موجود في الشركة.');
        }
        if ($byCode && $name !== '' && $this->person($byCode->full_name) !== $this->person($name)) {
            throw new \RuntimeException('كود الموظف لا يطابق الاسم.');
        }

        $byInsurance = collect();
        if ($insuranceNumber !== '') {
            $byInsurance = Employee::where('insurance_number', $insuranceNumber)->get();
            if ($byInsurance->count() > 1) {
                throw new \RuntimeException('الرقم التأميني يطابق أكثر من موظف؛ حدّد الكود بدلًا من التخمين.');
            }
        }
        $byNumber = $byInsurance->first();

        if ($byCode && $byNumber && $byCode->id !== $byNumber->id) {
            throw new \RuntimeException('الكود والرقم التأميني مسجّلان لموظفَين مختلفين.');
        }
        if ($byCode) {
            return $byCode;
        }
        if ($byNumber) {
            if ($name !== '' && $this->person($byNumber->full_name) !== $this->person($name)) {
                throw new \RuntimeException('الرقم التأميني لا يطابق الاسم.');
            }

            return $byNumber;
        }
        if ($name !== '') {
            $matches = Employee::where('full_name', $name)->get();
            if ($matches->count() > 1) {
                throw new \RuntimeException('الاسم يطابق أكثر من موظف؛ حدّد الكود أو الرقم التأميني.');
            }

            return $matches->first();
        }

        return null;
    }

    /** Same normalization as InsuranceController (workers / owners / bakeries). */
    private function category(string $value): string
    {
        if (in_array($value, ['workers', 'owners', 'bakeries'], true)) {
            return $value;
        }
        if (str_contains($value, 'المخابز')) return 'bakeries';
        if (str_contains($value, 'أصحاب أعمال')) return 'owners';

        return 'workers';
    }

    /** Same normalization as InsuranceController (registered / unregistered). */
    private function status(string $value): ?string
    {
        $key = $this->normalise($value);
        if (in_array($key, ['مسجل', 'registered', 'active'], true)) return 'registered';
        if (in_array($key, ['غيرمسجل', 'unregistered'], true)) return 'unregistered';

        return null;
    }

    private function normalise(mixed $value): string { return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))); }
    private function person(mixed $value): string { return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value))); }
    private function date(mixed $value): ?string { try { return filled($value) ? Carbon::parse($value)->toDateString() : null; } catch (\Throwable) { return null; } }
    private function money(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $value = str_replace([',', '٬', ' '], '', $value);
        $value = strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']);
        return is_numeric($value) ? (float) $value : null;
    }
}
