<?php

namespace Tests\Unit;

use App\Services\CompanyErasureService;
use PHPUnit\Framework\TestCase;

class ErasureClassifiesSignupDevicesTest extends TestCase
{
    public function test_signup_devices_is_a_shared_table_so_company_erasure_is_not_refused(): void
    {
        $shared = (new \ReflectionClass(CompanyErasureService::class))->getReflectionConstant('SHARED')->getValue();
        $this->assertContains('signup_devices', $shared);
    }
}
