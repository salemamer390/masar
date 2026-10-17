<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\SpreadsheetReader;
use App\Services\Imports\XlsxWriter;
use PHPUnit\Framework\TestCase;

class XlsxWriterTest extends TestCase
{
    public function test_written_file_can_be_read_back_with_text_values_intact(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xw');

        (new XlsxWriter())->write(
            $path,
            ['الاسم الكامل', 'الرقم القومي', 'رقم الهاتف', 'سبب الرفض'],
            [
                ['أحمد & <علي>', '29801011234567', '01001234567', 'الرقم القومي غير صحيح | "خطأ"'],
                ['منى', '', '01001234568', 'سبب آخر'],
            ]
        );

        $sheets = (new SpreadsheetReader())->readAll($path, 'xlsx');
        @unlink($path);

        $this->assertCount(1, $sheets);
        $rows = $sheets[0]['rows'];
        $this->assertSame(['الاسم الكامل', 'الرقم القومي', 'رقم الهاتف', 'سبب الرفض'], array_values($rows[1]));
        $this->assertSame('أحمد & <علي>', $rows[2][0]);
        $this->assertSame('29801011234567', $rows[2][1]);
        $this->assertSame('01001234567', $rows[2][2]);
        $this->assertSame('الرقم القومي غير صحيح | "خطأ"', $rows[2][3]);
        $this->assertArrayNotHasKey(1, $rows[3]);
        $this->assertSame('01001234568', $rows[3][2]);
    }
}
