<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\EmployeeImportService;
use App\Services\Imports\SpreadsheetReader;
use App\Services\PayrollCalculator;
use ReflectionMethod;
use Tests\TestCase;

class EmployeeImportMappingTest extends TestCase
{
    private EmployeeImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmployeeImportService(new SpreadsheetReader(), new PayrollCalculator());
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod($this->service, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->service, ...$arguments);
    }

    private function sheets(): array
    {
        return (new SpreadsheetReader())->readAll(__DIR__ . '/../../Fixtures/imports/multi-sheet.xlsx', 'xlsx');
    }

    public function test_cover_sheet_is_not_detected_as_data(): void
    {
        $aliases = $this->invoke('aliasMap');
        [$headerRow] = $this->invoke('detectHeader', $this->sheets()[0]['rows'], $aliases, []);

        $this->assertNull($headerRow);
    }

    public function test_recognised_headers_are_mapped_regardless_of_position(): void
    {
        $aliases = $this->invoke('aliasMap');
        [$headerRow, $map] = $this->invoke('detectHeader', $this->sheets()[2]['rows'], $aliases, []);

        $this->assertSame(1, $headerRow);
        $this->assertSame([0 => 'full_name', 1 => 'status'], $map);
    }

    public function test_common_personnel_aliases_are_mapped(): void
    {
        $aliases = $this->invoke('aliasMap');

        $this->assertSame('employee_code', $aliases[$this->service->key('الرقم')]);
        $this->assertSame('full_name', $aliases[$this->service->key('الاسم الرباعي')]);
        $this->assertSame('national_id', $aliases[$this->service->key('البطاقة')]);
    }

    public function test_unknown_header_needs_a_manual_mapping_until_the_user_confirms(): void
    {
        $aliases = $this->invoke('aliasMap');
        $rows = $this->sheets()[1]['rows'];

        [$headerRow] = $this->invoke('detectHeader', $rows, $aliases, []);
        $this->assertNull($headerRow, 'An unrecognised name column must not be applied automatically.');

        $manual = [$this->service->key('اسم الزميل') => 'full_name'];
        [$headerRow, $map, $entries] = $this->invoke('detectHeader', $rows, $aliases, $manual);

        $this->assertSame(1, $headerRow);
        $this->assertSame('full_name', $map[0]);
        $this->assertSame('national_id', $map[1]);
        $this->assertSame('gross_salary', $map[2]);
        $this->assertSame('full_name', $entries[0]['field']);
    }

    public function test_suggestions_and_sample_values_are_offered_for_unknown_columns(): void
    {
        $aliases = $this->invoke('aliasMap');
        $this->assertSame('phone', $this->invoke('suggestField', $this->service->key('رقم الموبايل'), $aliases, []));
        $this->assertSame('gross_salary', $this->invoke('suggestField', $this->service->key('المرتب الإجمالي'), $aliases, []));
        $this->assertNull($this->invoke('suggestField', $this->service->key('ملاحظات داخلية'), $aliases, ['notes' => 'auto']));

        $rows = [1 => [0 => 'العمود'], 2 => [0 => 'أ'], 3 => [0 => 'ب'], 4 => [0 => 'أ'], 5 => [0 => 'ج'], 6 => [0 => 'د']];
        $this->assertSame(['أ', 'ب', 'ج'], $this->invoke('sampleValues', $rows, 1, 0));
    }

    public function test_mappings_are_sanitised(): void
    {
        $clean = $this->invoke('sanitizeMappings', ['a' => 'full_name', 'b' => 'full_name', 'c' => 'not_a_field', 'd' => 'phone']);

        $this->assertSame(['a' => 'full_name', 'd' => 'phone'], $clean);
        $this->assertSame([], $this->invoke('sanitizeMappings', 'garbage'));
    }

    public function test_common_insurance_number_headers_are_mapped_automatically(): void
    {
        $aliases = $this->invoke('aliasMap');
        [$headerRow, $map] = $this->invoke('detectHeader', [
            1 => [0 => 'Full Name', 1 => 'Social Insurance Number'],
            2 => [0 => 'Ahmed Ali', 1 => '123456789'],
        ], $aliases, []);

        $this->assertSame(1, $headerRow);
        $this->assertSame('full_name', $map[0]);
        $this->assertSame('insurance_number', $map[1]);
    }

    public function test_template_headers_are_all_recognised(): void
    {
        $aliases = $this->invoke('aliasMap');
        $rows = (new SpreadsheetReader())->read(__DIR__ . '/../../../resources/import-templates/employees-import-template.xlsx', 'xlsx');
        [$headerRow, $map, $entries] = $this->invoke('detectHeader', $rows, $aliases, []);

        $this->assertSame(1, $headerRow);
        // The template intentionally omits optional fields such as manager name
        // and manual annual balance; every header that is present must map.
        $this->assertCount(count($rows[1]), $map);
        $this->assertSame([], $entries);
    }
    public function test_reviewed_mapping_overrides_recognised_field_and_can_ignore_it(): void
    {
        $rows = [1 => ['Full Name', 'Phone', 'Notes'], 2 => ['A Person', '01012345678', 'value']];
        [$row, $map, $entries] = $this->invoke('detectHeader', $rows, $this->invoke('aliasMap'), [], [1 => '', 2 => 'phone']);
        $this->assertSame(1, $row);
        $this->assertSame([0 => 'full_name', 2 => 'phone'], $map);
    }

    public function test_reviewed_mapping_preserves_detected_header_after_cover_rows(): void
    {
        $rows = [1 => ['Company cover'], 2 => ['Full Name', 'Phone'], 3 => ['A Person', '010']];
        [$row, $map] = $this->invoke('detectHeader', $rows, $this->invoke('aliasMap'), [], [1 => 'notes']);
        $this->assertSame(2, $row);
        $this->assertSame('notes', $map[1]);
    }

    public function test_reviewed_name_can_map_unknown_header_but_ignoring_name_is_not_importable(): void
    {
        $rows = [1 => ['Custom employee', 'Phone'], 2 => ['A Person', '010']];
        [$row, $map] = $this->invoke('detectHeader', $rows, $this->invoke('aliasMap'), [], [0 => 'full_name']);
        $this->assertSame(1, $row);
        $this->assertSame('full_name', $map[0]);
        [$row] = $this->invoke('detectHeader', $rows, $this->invoke('aliasMap'), [], [0 => '']);
        $this->assertNull($row);
    }

}
