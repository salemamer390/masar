<?php

namespace Tests\Feature;

use Tests\TestCase;

class StyleAssetsTest extends TestCase
{
    public function test_published_css_matches_ordered_sources(): void
    {
        $css = '';
        foreach (['app-header.txt', '01-foundation.css', '02-components.css', '03-design-system.css', '04-tour.css'] as $name) {
            $css .= file_get_contents(resource_path('styles/'.$name));
        }
        $this->assertSame($css, file_get_contents(public_path('app.css')));
    }

    public function test_all_page_kpi_strips_opt_into_the_shared_boundary_policy(): void
    {
        foreach (['employees', 'insurance', 'attendance', 'dashboard', 'payroll', 'leaves', 'requests'] as $page) {
            $view = file_get_contents(resource_path('views/'.$page.'/index.blade.php'));
            $this->assertSame(1, substr_count($view, 'kpi-summary-strip'), $page);
        }

        $css = file_get_contents(resource_path('styles/03-design-system.css'));
        $this->assertStringContainsString('.kpi-summary-strip.kpi-summary-strip', $css);
        $this->assertStringContainsString('border-inline-start: 1px solid #dbe2ec !important', $css);
        $this->assertStringContainsString(':nth-child(even)', $css);
        $this->assertStringContainsString('-webkit-text-fill-color: currentColor', $css);
        $dashboard = file_get_contents(public_path('dashboard-reference.css'));
        $this->assertStringNotContainsString('border-inline-start:1px solid #dbe2ec', $dashboard);
    }

    public function test_approved_cards_use_one_lead_and_a_group_without_concept_palette(): void
    {
        foreach (['employees', 'insurance', 'attendance', 'dashboard', 'payroll', 'leaves', 'requests'] as $page) {
            $view = file_get_contents(resource_path('views/'.$page.'/index.blade.php'));
            $this->assertSame(1, substr_count($view, 'approved-summary-cards'), $page);
            $this->assertSame(1, substr_count($view, 'class="summary-card-group"'), $page);
            $this->assertStringContainsString('$loop->index === 1', $view, $page);
            $this->assertStringContainsString('$loop->last && !$loop->first', $view, $page);
        }
        $salarySummary = file_get_contents(resource_path('views/payroll/partials/employee-salaries-body.blade.php'));
        $this->assertStringContainsString('stats kpi-summary-strip approved-summary-cards', $salarySummary);
        $this->assertStringContainsString('class="summary-card-group"', $salarySummary);
        $this->assertStringContainsString('stat employee-kpi', $salarySummary);
        $this->assertStringContainsString('$summaryStats[$summaryStat[1]]', $salarySummary);
        $css = file_get_contents(resource_path('styles/03-design-system.css'));
        $this->assertStringContainsString('--summary-lead: #1769e0', $css);
        $this->assertStringContainsString('repeat(var(--summary-columns), minmax(0, 1fr))', $css);
        $this->assertStringContainsString('body.preview-dark .kpi-summary-strip.approved-summary-cards', $css);
        foreach (['#c15846', '#aba6a3', '#49dde4'] as $conceptColor) {
            $this->assertStringNotContainsString($conceptColor, strtolower($css));
        }
    }

    public function test_summary_metric_rows_align_across_wrapped_titles(): void
    {
        $css = file_get_contents(resource_path('styles/03-design-system.css'));
        $this->assertStringContainsString('grid-template-rows: subgrid', $css);
        $this->assertStringContainsString('grid-row: span 3', $css);
        $this->assertStringContainsString('font-variant-numeric: tabular-nums', $css);
        $this->assertStringContainsString('grid-template-rows: minmax(41px, auto) auto minmax(36px, 1fr)', $css);
    }

    public function test_extracted_page_styles_exist_and_are_static_css(): void
    {
        $pages = ['attendance/index'=>7, 'requests/index'=>2, 'payroll/settings'=>3,
            'company/account'=>1, 'jobs/index'=>1, 'roles/index'=>1];
        foreach ($pages as $page=>$count) {
            $view = file_get_contents(resource_path('views/'.$page.'.blade.php'));
            if ($page === 'requests/index') $view .= file_get_contents(resource_path('views/requests/partials/create-behavior.blade.php'));
            $this->assertStringNotContainsString('<style', $view, $page);
            $prefix = 'css/pages/'.str_replace('/', '-', $page).'-';
            for ($i=1; $i<=$count; $i++) {
                $asset = $prefix.sprintf('%02d', $i).'.css';
                $this->assertSame(1, substr_count($view, "asset('".$asset."')"));
                $this->assertFileExists(public_path($asset));
                $css = file_get_contents(public_path($asset));
                $this->assertNotSame('', trim($css));
                $this->assertStringNotContainsString('{{', $css);
                $this->assertStringNotContainsString('{!!', $css);
            }
        }
    }

    public function test_main_layout_keeps_bootstrap_once_and_dark_layer_last(): void
    {
        $view = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertSame(1, substr_count($view, 'bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css'));
        $this->assertSame(1, substr_count($view, 'bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'));
        $app = strpos($view, "asset('app.css')");
        $page = strpos($view, "@stack('styles')");
        $dark = strpos($view, "asset('dark-mode.css')");
        $this->assertLessThan($page, $app);
        $this->assertLessThan($dark, $page);
    }
}
