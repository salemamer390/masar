<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmployeeImportWizardViewTest extends TestCase
{
    public function test_mapping_reuses_sheet_state_and_finite_attention_sequence(): void
    {
        $view = file_get_contents(resource_path('views/employees/partials/import-modal.blade.php'));
        $css = file_get_contents(public_path('css/employee-import-reference.css'));
        $this->assertStringContainsString('employee-mapping-grid', $view);
        $this->assertStringContainsString('data-employee-missing-field', $view);
        $this->assertStringContainsString('state.employeeMappings[name] = map', $view);
        $this->assertStringContainsString('body.inert = !expanded', $view);
        $start = strpos($view, 'async function drawAttentionToMissingSheet');
        $flow = substr($view, $start, strpos($view, 'function renderSheetsStep', $start) - $start);
        $closed = strpos($flow, 'setSheetExpanded(body, false)');
        $red = strpos($flow, "header.classList.add('sheet-header-attention')");
        $off = strpos($flow, "header.classList.remove('sheet-header-attention')");
        $open = strpos($flow, 'setSheetExpanded(body, true)');
        $scroll = strpos($flow, 'target.scrollIntoView');
        $this->assertTrue($closed < $red && $red < $off && $off < $open && $open < $scroll);
        $this->assertStringContainsString('header.scrollIntoView', $flow);
        $this->assertStringContainsString("querySelector('.sheet-card-head')", $flow);
        $this->assertStringContainsString('.sheet-card-head.sheet-header-attention', $css);
        $this->assertStringContainsString('prefers-reduced-motion', $flow);
        $this->assertStringContainsString('version === attentionVersion', $flow);
        $this->assertStringContainsString('sheetMissingFlash 900ms ease-in-out 2', $css);
        $this->assertStringNotContainsString('infinite', substr($view, strpos($view, '.mapping-field-card'), strpos($view, '</style>') - strpos($view, '.mapping-field-card')));
    }
    public function test_sheet_progress_waits_for_completion_and_skips_finished_or_ignored_sheets(): void
    {
        $view = file_get_contents(resource_path('views/employees/partials/import-modal.blade.php'));
        $this->assertStringContainsString('function restoreSheetProgress(openSheets, correctedSheet, wasMissing, data)', $view);
        $this->assertStringContainsString('const completed = wasMissing && correctedBody', $view);
        $this->assertStringContainsString('if (!completed) return;', $view);
        $this->assertStringContainsString('setSheetExpanded(correctedBody, false)', $view);
        $this->assertStringContainsString('...bodies.slice(index + 1), ...bodies.slice(0, index)', $view);
        $this->assertStringContainsString("body.closest('.sheet-card-missing:not(.sheet-card-ignored)')", $view);
        $this->assertStringContainsString('if (next) drawAttentionToMissingSheet(next)', $view);
        $this->assertStringContainsString('restoreSheetProgress(openSheets, correctedSheet, wasMissing, data)', $view);
        $this->assertStringContainsString("runPreview(null, '', false, 'reload-sheets', sheetName)", $view);
        $this->assertEquals(2, substr_count($view, "runPreview(null, '', false, 'reload-sheets', name)"));
    }
}
