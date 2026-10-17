<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SpreadsheetReaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return __DIR__ . '/../../Fixtures/imports/' . $name;
    }

    public function test_multi_sheet_workbook_returns_visible_sheets_only(): void
    {
        $sheets = (new SpreadsheetReader())->readAll($this->fixture('multi-sheet.xlsx'), 'xlsx');

        $this->assertSame(['غلاف', 'فرع القاهرة', 'فرع الإسكندرية'], array_column($sheets, 'name'));
        $this->assertSame([1 => [0 => 'تقرير الموظفين 2026']], $sheets[0]['rows']);
        $this->assertSame('اسم الزميل', $sheets[1]['rows'][1][0]);
        $this->assertSame('29801011234567', $sheets[1]['rows'][2][1]);
        $this->assertSame('15000', $sheets[1]['rows'][2][2]);
        $this->assertSame('استقالة', $sheets[2]['rows'][2][1]);
    }

    public function test_read_returns_the_first_visible_sheet(): void
    {
        $rows = (new SpreadsheetReader())->read($this->fixture('multi-sheet.xlsx'), 'xlsx');

        $this->assertSame('تقرير الموظفين 2026', $rows[1][0]);
    }

    public function test_dates_times_numbers_and_text_are_converted(): void
    {
        $rows = (new SpreadsheetReader())->read($this->fixture('values.xlsx'), 'xlsx');

        $this->assertSame('الاسم الكامل', $rows[1][0]);
        $this->assertSame(['أحمد', '29801011234567', '1001234567', '2026-01-31', '09:00:00', '15000.5', 'A-101'], array_values($rows[2]));

        // Empty rows are skipped and keep the real Excel row numbers.
        $this->assertArrayNotHasKey(3, $rows);
        $this->assertSame('منى', $rows[4][0]);
        $this->assertSame('29801011234568', $rows[4][1]);
        $this->assertSame('01001234568', $rows[4][2]);
        $this->assertSame('2026-02-01 08:30:00', $rows[4][3]);
        $this->assertArrayNotHasKey(4, $rows[4]);
        $this->assertSame('20,000', $rows[4][5]);
        $this->assertSame('102', $rows[4][6]);
    }

    public function test_csv_with_bom_and_semicolon_delimiter(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "\xEF\xBB\xBF" . "الاسم الكامل;الرقم القومي\r\nأحمد;29801011234567\r\n\r\nمنى;\r\n");

        $rows = (new SpreadsheetReader())->read($path, 'csv');
        @unlink($path);

        $this->assertSame([1 => [0 => 'الاسم الكامل', 1 => 'الرقم القومي'], 2 => [0 => 'أحمد', 1 => '29801011234567'], 4 => [0 => 'منى']], $rows);
    }

    public function test_csv_saved_as_windows_1256_is_converted_to_utf8(): void
    {
        if (!function_exists('iconv')) {
            $this->markTestSkipped('iconv is not available.');
        }
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, iconv('UTF-8', 'Windows-1256', "الاسم الكامل,الهاتف\r\nأحمد,0100\r\n"));

        $rows = (new SpreadsheetReader())->read($path, 'csv');
        @unlink($path);

        $this->assertSame('الاسم الكامل', $rows[1][0]);
        $this->assertSame('أحمد', $rows[2][0]);
    }

    public function test_unsupported_extension_and_corrupt_xlsx_raise_friendly_errors(): void
    {
        $reader = new SpreadsheetReader();

        $this->expectException(RuntimeException::class);
        $reader->readAll($this->fixture('values.xlsx'), 'xls');
    }

    public function test_corrupt_xlsx_is_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'this is not a zip file');

        try {
            $this->expectException(RuntimeException::class);
            (new SpreadsheetReader())->readAll($path, 'xlsx');
        } finally {
            @unlink($path);
        }
    }
}
