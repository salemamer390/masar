<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\AttendanceSheetAnalyzer;
use Tests\TestCase;

class AttendanceSheetAnalyzerTest extends TestCase
{
    private AttendanceSheetAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new AttendanceSheetAnalyzer();
    }

    public function test_common_day_and_appointment_headers_are_recognised(): void
    {
        $map = $this->analyzer->mapHeaders(['الرقم', 'الاسم', 'اليوم', 'ميعاد الحضور', 'ميعاد الانصراف']);

        $this->assertSame(['employee_code' => 0, 'employee_name' => 1, 'date' => 2, 'check_in' => 3, 'check_out' => 4], $map);
    }

    public function test_punch_headers_spelled_with_ha_are_recognised(): void
    {
        $map = $this->analyzer->mapHeaders(['الكود', 'الاسم', 'التاريخ', 'أول بصمه', 'أخر بصمه']);

        $this->assertSame(3, $map['check_in']);
        $this->assertSame(4, $map['check_out']);
    }

    public function test_daily_layout_detected_from_common_headers(): void
    {
        $sheets = [[
            'name' => 'الدوام',
            'rows' => [
                1 => ['الرقم', 'الاسم', 'اليوم', 'ميعاد الحضور', 'ميعاد الانصراف'],
                2 => ['101', 'أحمد محمد علي', '2026-11-06', '09:00', '17:00'],
            ],
        ]];

        [$sheet] = $this->analyzer->analyze($sheets);

        $this->assertSame(AttendanceSheetAnalyzer::LAYOUT_DAILY, $sheet['layout']);
        $this->assertNull($sheet['skip_reason']);
        $this->assertSame(1, $sheet['row_count']);
    }
}
