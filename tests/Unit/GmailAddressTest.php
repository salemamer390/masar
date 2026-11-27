<?php

namespace Tests\Unit;

use App\Rules\GmailAddress;
use PHPUnit\Framework\TestCase;

class GmailAddressTest extends TestCase
{
    public function test_accepts_gmail_and_googlemail(): void
    {
        foreach (['a@gmail.com', 'A.B+x@GMAIL.com', 'a@googlemail.com', ' a@gmail.com'] as $e) {
            $this->assertTrue(GmailAddress::passes(trim($e)), $e);
        }
    }

    public function test_rejects_other_domains_and_lookalikes(): void
    {
        foreach (['a@yahoo.com', 'a@gmail.com.evil.com', 'a@notgmail.com', 'a@gmail.co', 'a@@gmail.com', 'gmail.com', '', null, 5] as $e) {
            $this->assertFalse(GmailAddress::passes($e), (string) $e);
        }
    }
}
