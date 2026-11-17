<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardAssetVersioningTest extends TestCase
{
    public function test_dashboard_reference_stylesheet_uses_filemtime_version(): void
    {
        $view = file_get_contents(resource_path('views/dashboard/index.blade.php'));

        $this->assertStringContainsString(
            "asset('dashboard-reference.css') }}?v={{ @filemtime(public_path('dashboard-reference.css')) }}",
            $view
        );
    }
}
