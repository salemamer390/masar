<?php

namespace Tests\Unit;

use App\Http\Controllers\PayrollController;
use PHPUnit\Framework\TestCase;

class DraftRefreshNoticeTest extends TestCase
{
    public function test_the_open_refresh_has_no_five_minute_throttle(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/PayrollController.php');
        $this->assertStringNotContainsString('subMinutes(5)', $source);
        $this->assertStringContainsString("return ['error',", $source);
        $this->assertTrue(method_exists(PayrollController::class, 'employeeSalaries'));
    }
}
