<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\WorkbookLeavePenaltyImporter;
use Tests\TestCase;

class WorkbookPenaltyMappingTest extends TestCase
{
    public function test_screenshot_penalty_headers_are_mapped_without_guessing_unsupported_fields(): void
    {
        $headers = ['كود الموظف', 'اسم الموظف', 'التاريخ', 'نوع المخالفة', 'الوصف', 'عدد الأيام/الوحدات', 'قيمة الوحدة(ج.م)', 'نوع الجزاء'];
        $config = app(WorkbookLeavePenaltyImporter::class)->detectConfig([1 => $headers, 2 => ['104', 'موظف تجريبي', '2026-10-20', 'غياب بدون عذر', 'يوم غياب', 1, 666.67, 'خصم من الراتب']]);
        $this->assertSame(1, $config['header_row']);
        $this->assertSame(['code' => 0, 'name' => 1, 'date' => 2, 'penalty_type' => 7, 'reason' => 4, 'deduction' => 5], $config['map']);
        $this->assertArrayNotHasKey('status', $config['map']);
        $this->assertNotContains(3, $config['map']);
        $this->assertNotContains(6, $config['map']);
    }

    public function test_day_unit_variants_are_deduction_only_in_penalty_context(): void
    {
        foreach (['عدد الأيام/الوحدات', 'عدد الايام / الوحدات', 'عدد الأيام والوحدات', 'عدد الأيام أو الوحدات', 'عدد الايام او الوحدات', 'عدد الأيام', 'عدد الايام', 'عدد الوحدات', 'ايام الخصم', 'عدد ايام الخصم', 'Days / Units', 'Number of units'] as $header) {
            $config = app(WorkbookLeavePenaltyImporter::class)->detectConfig([1 => ['الكود', 'نوع الجزاء', 'التاريخ', $header]]);
            $this->assertSame(3, $config['map']['deduction'] ?? null, $header);
        }
        $leave = app(WorkbookLeavePenaltyImporter::class)->detectConfig([1 => ['الكود', 'نوع الإجازة', 'من تاريخ', 'إلى تاريخ', 'عدد الأيام', 'الوصف']]);
        $this->assertSame(4, $leave['map']['days']);
        $this->assertArrayNotHasKey('deduction', $leave['map']);
        $this->assertArrayNotHasKey('reason', $leave['map']);
    }

    public function test_explicit_penalty_fields_win_over_generic_columns(): void
    {
        $config = app(WorkbookLeavePenaltyImporter::class)->detectConfig([1 => ['الكود', 'نوع الجزاء', 'التاريخ', 'عدد الأيام/الوحدات', 'أيام الخصم', 'الوصف', 'ملاحظات']]);
        $this->assertSame(4, $config['map']['deduction']);
        $this->assertSame(6, $config['map']['reason']);
    }
}
