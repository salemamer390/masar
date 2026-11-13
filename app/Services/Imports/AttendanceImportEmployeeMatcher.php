<?php

namespace App\Services\Imports;

use App\Models\Employee;

/** One tenant-scoped index per parse, never shared across requests/companies. */
final class AttendanceImportEmployeeMatcher
{
    private ?array $byCode = null;

    public function byCode(mixed $code): ?Employee
    {
        $normalizer = app(ImportValueNormalizer::class);
        $key = mb_strtolower($normalizer->identifier((string) $code));
        if ($key === '') return null;
        if ($this->byCode === null) {
            $this->byCode = [];
            foreach (Employee::query()->get() as $employee) {
                $this->byCode[mb_strtolower($normalizer->identifier((string) $employee->employee_code))][] = $employee;
            }
        }
        $matches = $this->byCode[$key] ?? [];
        if (count($matches) > 1) {
            throw new \RuntimeException('كود الموظف يطابق أكثر من سجل بعد توحيد الأرقام؛ راجع الأكواد بدلًا من التخمين.');
        }
        return $matches[0] ?? null;
    }
}
