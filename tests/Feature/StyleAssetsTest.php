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
