<?php

namespace App\Services\Imports;

/**
 * Content-based detection of attendance worksheets inside a workbook.
 *
 * Sheet names are never authoritative: every visible sheet is scanned for a
 * header row whose cells match a wide alias dictionary, and the layout is
 * derived from the columns actually present:
 *
 *  - daily:        one row per employee-day (date + employee + in/out)
 *  - punches:      one row per raw punch (datetime + employee)
 *  - per_employee: a daily table with no employee column; the employee comes
 *                  from the sheet's metadata block (grounded by code) or,
 *                  failing that, its title - always confirmed in the preview.
 *
 * Sheets that match nothing are reported with the reason, never skipped
 * silently.
 */
class AttendanceSheetAnalyzer
{
    public const LAYOUT_DAILY = 'daily';
    public const LAYOUT_PUNCHES = 'punches';
    public const LAYOUT_PER_EMPLOYEE = 'per_employee';

    /** Fields the user may map in the preview, in display order. */
    public const MAPPABLE_FIELDS = ['employee_code', 'employee_name', 'date', 'punch_at', 'check_in', 'check_out'];

    private const HEADER_SCAN_ROWS = 10;

    /**
     * @param array<int, array{name: string, rows: array<int, array<int, string>>}> $sheets
     * @return array<int, array<string, mixed>>
     */
    public function analyze(array $sheets): array
    {
        $result = [];
        foreach ($sheets as $index => $sheet) {
            $result[] = $this->analyzeSheet($index, $sheet['name'], $sheet['rows'] ?? []);
        }
        return $result;
    }

    private function analyzeSheet(int $index, string $name, array $rows): array
    {
        $base = ['index' => $index, 'name' => $name, 'layout' => null, 'header_row' => null, 'map' => [], 'headers' => [], 'row_count' => 0, 'skip_reason' => null, 'hint' => ['code' => null, 'name' => null]];
        if (!$rows) {
            return [...$base, 'skip_reason' => 'ورقة فارغة.'];
        }
        ksort($rows);
        $scan = array_slice($rows, 0, self::HEADER_SCAN_ROWS, true);
        foreach ($scan as $rowNumber => $row) {
            $map = $this->mapHeaders($row);
            $hasEmployee = isset($map['employee_code']) || isset($map['employee_name']);
            $hasInOut = isset($map['check_in']) || isset($map['check_out']);
            $layout = null;
            if ($hasEmployee && $hasInOut) {
                $layout = self::LAYOUT_DAILY;
            } elseif (isset($map['punch_at']) && $hasEmployee) {
                $layout = self::LAYOUT_PUNCHES;
            } elseif (isset($map['date']) && $hasInOut && !$hasEmployee) {
                $layout = self::LAYOUT_PER_EMPLOYEE;
            }
            if ($layout === null) continue;

            $dataRows = array_filter($rows, fn ($r, $n) => $n > $rowNumber && count(array_filter($r, fn ($v) => trim((string) $v) !== '')) >= 2, ARRAY_FILTER_USE_BOTH);
            $analysis = [...$base, 'layout' => $layout, 'header_row' => $rowNumber, 'map' => $map, 'headers' => array_values($row), 'row_count' => count($dataRows)];
            if ($layout === self::LAYOUT_PER_EMPLOYEE) {
                $analysis['hint'] = $this->employeeHint($name, $scan, $rowNumber);
            }
            return $analysis;
        }
        return [...$base, 'skip_reason' => 'لم يُعثر فيها على صف عناوين حضور (تاريخ + موظف + حضور/انصراف).'];
    }

    /** Map header cells to fields; first match wins per field. */
    public function mapHeaders(array $headers): array
    {
        $map = [];
        $aliasesByField = AttendanceHeaderDictionary::forHeaders($headers);
        foreach ($headers as $index => $header) {
            $key = $this->normalise($header);
            if ($key === '') continue;
            foreach ($aliasesByField as $field => $aliases) {
                if (isset($map[$field])) continue;
                foreach ($aliases as $alias) {
                    if ($key === $this->normalise($alias)) { $map[$field] = $index; continue 3; }
                }
            }
        }
        return $map;
    }

    /**
     * Identity evidence for a per-employee sheet: a «الكود»/«الموظف» label in
     * the metadata block above the table, then the sheet title as a name hint.
     */
    private function employeeHint(string $sheetName, array $scan, int $headerRow): array
    {
        $hint = ['code' => null, 'name' => null];
        foreach ($scan as $rowNumber => $row) {
            if ($rowNumber >= $headerRow) break;
            $cells = array_values($row);
            foreach ($cells as $i => $cell) {
                $key = $this->normalise($cell);
                $next = trim((string) ($cells[$i + 1] ?? ''));
                if ($next === '') continue;
                if (in_array($key, array_map(fn ($v) => $this->normalise($v), ['الكود', 'كود', 'رقمالموظف', 'كودالموظف']), true) && $hint['code'] === null) $hint['code'] = $next;
                if (in_array($key, ['الموظف', 'اسمالموظف', 'الاسم'], true) && $hint['name'] === null) $hint['name'] = $next;
            }
        }
        if ($hint['name'] === null && trim($sheetName) !== '') $hint['name'] = trim($sheetName);
        return $hint;
    }

    /** A stable signature so per-employee sheets with identical tables group together. */
    public function headerSignature(array $headers): string
    {
        return implode('|', array_map(fn ($h) => $this->normalise($h), $headers));
    }

    private function normalise(mixed $value): string
    {
        return app(ImportValueNormalizer::class)->key((string) $value);
    }
}
