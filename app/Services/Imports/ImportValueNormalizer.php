<?php

namespace App\Services\Imports;

/** Shared pure normalization rules, also used by the employee import parsers. */
final class ImportValueNormalizer
{
    use ParsesImportValues {
        latinDigits as public digits;
        numericText as public identifier;
        parseTime as private parsedTime;
    }

    public function time(mixed $value): ?string
    {
        $raw = trim($this->digits((string) $value));
        if ($raw === '') return null;
        if ($raw === '0') $raw = '0.0'; // Excel midnight fraction.
        try {
            return substr($this->parsedTime($raw), 0, 5);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Unknown nonempty values are never persisted as new attendance states. */
    public function attendanceStatus(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') return '';
        $aliases = [
            'Present' => ['حاضر', 'حضور', 'present'],
            'Late' => ['متأخر', 'تأخير', 'late'],
            'Absent' => ['غائب', 'غياب', 'absent'],
            'Leave' => ['إجازة', 'اجازه', 'leave'],
        ];
        foreach ($aliases as $status => $names) {
            if (in_array($this->key($raw), array_map($this->key(...), $names), true)) return $status;
        }
        return null;
    }

    public function nameWarning(mixed $stored, mixed $provided): ?string
    {
        $plain = static fn ($value) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
        return filled($provided) && $plain($stored) !== $plain($provided) && $this->key((string) $stored) === $this->key((string) $provided)
            ? 'تمت مطابقة الاسم بعد توحيد الحروف والتشكيل؛ لم يتغير اسم الموظف المسجل.' : null;
    }
}
