<?php

namespace Tests\Unit;

use App\Models\Employee;
use PHPUnit\Framework\TestCase;

class InactiveEmployeeStatusTest extends TestCase
{
    public function test_inactive_status_names_cover_inactive_suspended_and_pending(): void
    {
        foreach (['Inactive', 'غير نشط', 'Suspended', 'موقوف', 'Pending', 'قيد التعيين'] as $name) {
            $this->assertContains($name, Employee::INACTIVE_STATUS_NAMES);
        }
        $this->assertNotContains('Active', Employee::INACTIVE_STATUS_NAMES);
        $this->assertNotContains('نشط', Employee::INACTIVE_STATUS_NAMES);
    }
}
