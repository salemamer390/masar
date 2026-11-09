<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTopbarCssTest extends TestCase
{
    /**
     * The dashboard-only stylesheet must not re-layout the shared topbar;
     * the topbar belongs to resources/views/layouts/app.blade.php + app.css.
     */
    public function test_dashboard_stylesheet_keeps_no_topbar_layout_overrides(): void
    {
        $css = file_get_contents(public_path('dashboard-reference.css'));

        foreach (['.topbar-reference{', '.topbar-tools{', '.topbar-date{', '.topbar-search-area{', '.global-search-reference{', 'body:has(.dashboard-command-bar--reference) .topbar'] as $selector) {
            $this->assertStringNotContainsString($selector, $css, "dashboard stylesheet still overrides $selector");
        }
        // Shared notifications must stay above the sidebar on every page.
        $this->assertStringNotContainsString('.topbar .notification-menu', $css);
        $shared = file_get_contents(resource_path('styles/02-components.css'));
        $this->assertStringContainsString('.topbar .notification-menu', $shared);
        $this->assertStringContainsString('.notification-menu .notification-scroll', $shared);
    }
}
