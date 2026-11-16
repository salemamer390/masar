<?php

namespace Tests\Feature;

use App\Services\Imports\AttendanceHeaderDictionary;
use App\Services\Imports\AttendanceSheetAnalyzer;
use App\Services\Imports\ImportValueNormalizer;
use App\Services\Imports\WorkbookAttendanceImporter;
use Tests\TestCase;

class AttendanceHeaderDictionaryTest extends TestCase
{
    public function test_every_shared_alias_has_the_same_meaning_in_both_entry_points(): void
    {
        $analyzer = app(AttendanceSheetAnalyzer::class);
        $workbook = app(WorkbookAttendanceImporter::class);
        $method = new \ReflectionMethod($workbook, 'mapHeaders');
        $names = ['employee_code' => 'code', 'employee_name' => 'name', 'date' => 'date', 'check_in' => 'in', 'check_out' => 'out'];
        foreach (AttendanceHeaderDictionary::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $this->assertSame([$field => 0], $analyzer->mapHeaders([$alias]), $alias);
                $this->assertSame(isset($names[$field]) ? [$names[$field] => 0] : [], $method->invoke($workbook, [$alias]), $alias);
            }
        }
    }

    public function test_no_normalized_alias_has_conflicting_meanings(): void
    {
        $meanings = [];
        $normalizer = app(ImportValueNormalizer::class);
        foreach (AttendanceHeaderDictionary::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $key = $normalizer->key($alias);
                $this->assertSame($field, $meanings[$key] ?? $field, $alias);
                $meanings[$key] = $field;
            }
        }
    }

    public function test_each_parser_keeps_its_existing_duplicate_column_precedence(): void
    {
        $headers = ['كود الموظف', 'employee code', 'التاريخ', 'date'];
        $this->assertSame(['employee_code' => 0, 'date' => 2], app(AttendanceSheetAnalyzer::class)->mapHeaders($headers));
        $workbook = app(WorkbookAttendanceImporter::class);
        $method = new \ReflectionMethod($workbook, 'mapHeaders');
        $this->assertSame(['code' => 1, 'date' => 3], $method->invoke($workbook, $headers));
    }

    public function test_shared_coverage_does_not_enable_raw_punches_in_employee_workbooks(): void
    {
        $rows = [1 => ['badge no', 'وقت البصمة'], 2 => ['101', '2026-11-16 09:00']];
        $analysis = app(AttendanceSheetAnalyzer::class)->analyze([['name' => 'punches', 'rows' => $rows]]);
        $this->assertSame(AttendanceSheetAnalyzer::LAYOUT_PUNCHES, $analysis[0]['layout']);
        $config = app(WorkbookAttendanceImporter::class)->detectConfig($rows);
        $this->assertSame(['code' => 0], $config['map']);
        $this->assertSame(['code', 'date', 'in', 'out'], \App\Services\Imports\EmployeeImportService::requiredDomainFields('attendance'));
    }
    public function test_weekday_header_never_overrides_an_explicit_date_but_stays_a_fallback(): void
    {
        $workbook = app(WorkbookAttendanceImporter::class);
        $method = new \ReflectionMethod($workbook, 'mapHeaders');
        foreach ([['التاريخ', 'اليوم'], ['اليوم', 'التاريخ']] as $headers) {
            $index = array_search('التاريخ', $headers);
            $this->assertSame(['date' => $index], app(AttendanceSheetAnalyzer::class)->mapHeaders($headers));
            $this->assertSame(['date' => $index], $method->invoke($workbook, $headers));
        }
        $this->assertSame(['date' => 0], app(AttendanceSheetAnalyzer::class)->mapHeaders(['اليوم']));
        $this->assertSame(['date' => 0], $method->invoke($workbook, ['اليوم']));
    }
}
