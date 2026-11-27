<?php

namespace Tests\Unit;

use App\Rules\NotDisposableEmail;
use PHPUnit\Framework\TestCase;

class NotDisposableEmailTest extends TestCase
{
    public function test_blocks_known_temp_mail_domains_and_their_subdomains(): void
    {
        foreach (['filohi9652@dsmspaces.com', 'a@mailinator.com', 'A@YOPMAIL.COM', 'x@sub.mailinator.com', 'x@10minutemail.com'] as $e) {
            $this->assertTrue(NotDisposableEmail::isDisposable($e), $e);
        }
    }

    public function test_allows_real_providers_and_company_domains(): void
    {
        foreach (['a@me.com', 'a@duck.com', 'a@gmail.com', 'a@yahoo.com', 'a@outlook.com', 'a@icloud.com', 'a@company.com.eg', 'bad', '', null] as $e) {
            $this->assertFalse(NotDisposableEmail::isDisposable($e), (string) $e);
        }
    }

    public function test_list_file_is_loaded_and_large(): void
    {
        $n = count(file(dirname(__DIR__, 2) . '/resources/data/disposable-email-domains.txt'));
        $this->assertGreaterThan(80000, $n);
    }
}
