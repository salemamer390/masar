<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\WorkbookLeavePenaltyImporter;
use App\Services\Imports\WorkbookRequestImporter;
use ReflectionClass;
use Tests\TestCase;

/** «الكود»/«كود» as the employee-code header of a typed domain sheet auto-maps like «كود الموظف». */
class WorkbookDomainCodeAliasTest extends TestCase
{
    public function test_leave_and_penalty_sheets_accept_bare_code_header(): void
    {
        $importer = (new ReflectionClass(WorkbookLeavePenaltyImporter::class))->newInstanceWithoutConstructor();

        $leave = $importer->detectConfig([
            1 => ['الكود', 'نوع الإجازة', 'من تاريخ', 'إلى تاريخ'],
            2 => ['101', 'سنوية', '2026-11-01', '2026-11-03'],
        ]);
        $this->assertSame(1, $leave['header_row']);
        $this->assertSame(0, $leave['map']['code']);
        $this->assertSame(1, $leave['map']['leave_type']);

        $penalty = $importer->detectConfig([
            1 => ['الكود', 'نوع الجزاء', 'التاريخ', 'أيام الخصم'],
            2 => ['101', 'تأخير', '2026-11-01', '1'],
        ]);
        $this->assertSame(1, $penalty['header_row']);
        $this->assertSame(0, $penalty['map']['code']);
        $this->assertSame(1, $penalty['map']['penalty_type']);
        $this->assertSame(2, $penalty['map']['date']);
    }

    public function test_request_sheet_accepts_bare_code_header(): void
    {
        $importer = new WorkbookRequestImporter();

        $config = $importer->detectConfig([
            1 => ['الكود', 'مبلغ السلفة', 'تاريخ طلب السلفة'],
            2 => ['101', '500', '2026-11-01'],
        ]);

        $this->assertSame(1, $config['header_row']);
        $this->assertSame(0, $config['map']['code']);
        $this->assertSame(1, $config['map']['amount']);
        $this->assertSame(2, $config['map']['date']);
    }
}
