<?php

namespace Tests\Unit;

use App\Support\SignupDeviceLimit;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class SignupDeviceLimitTest extends TestCase
{
    public function test_client_ip_prefers_forwarded_headers_and_ignores_garbage(): void
    {
        $r = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame('10.0.0.1', SignupDeviceLimit::clientIp($r));

        $r->headers->set('X-Forwarded-For', '203.0.113.9, 10.0.0.1');
        $this->assertSame('203.0.113.9', SignupDeviceLimit::clientIp($r));

        $r->headers->set('CF-Connecting-IP', '198.51.100.7');
        $this->assertSame('198.51.100.7', SignupDeviceLimit::clientIp($r));

        $r->headers->set('CF-Connecting-IP', 'not-an-ip');
        $this->assertSame('203.0.113.9', SignupDeviceLimit::clientIp($r));
    }

    public function test_caps(): void
    {
        $this->assertSame(3, SignupDeviceLimit::MAX_ACCOUNTS);
        $this->assertGreaterThan(3, SignupDeviceLimit::MAX_PER_IP);
    }
}
