<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\Auth\EmailConfirmationReminderNotification;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordLinkNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EmailConfirmationTest extends TestCase
{
    private function user(?Carbon $deadline, ?Carbon $verified = null): User
    {
        $u = new User(['name' => 'Test', 'email' => 'a@example.test']);
        $u->id = 5;
        $u->email_confirm_deadline_at = $deadline;
        $u->email_verified_at = $verified;

        return $u;
    }

    public function test_only_accounts_with_a_deadline_can_be_locked(): void
    {
        $this->assertFalse($this->user(null)->emailConfirmationPending());
        $this->assertFalse($this->user(null)->emailConfirmationLocked());
        $this->assertTrue($this->user(now()->addDays(3))->emailConfirmationPending());
        $this->assertFalse($this->user(now()->addDays(3))->emailConfirmationLocked());
        $this->assertTrue($this->user(now()->subMinute())->emailConfirmationLocked());
    }

    public function test_verified_accounts_are_never_locked(): void
    {
        $u = $this->user(now()->subDays(30), now()->subDays(20));
        $this->assertFalse($u->emailConfirmationPending());
        $this->assertFalse($u->emailConfirmationLocked());
    }

    public function test_days_left_counts_down_to_zero(): void
    {
        $this->assertSame(7, $this->user(now()->addDays(7)->subSecond())->emailConfirmationDaysLeft());
        $this->assertSame(1, $this->user(now()->addHours(5))->emailConfirmationDaysLeft());
        $this->assertSame(0, $this->user(now()->subDay())->emailConfirmationDaysLeft());
    }

    public function test_emails_are_arabic_and_mention_spam_and_the_deadline(): void
    {
        $u = $this->user(now()->addDays(7));
        $welcome = (new VerifyEmailNotification(true))->toMail($u);
        $html = view($welcome->view, $welcome->viewData)->render();
        $this->assertStringContainsString('Spam', $html);
        $this->assertStringContainsString('7 أيام', $html);
        $this->assertStringContainsString('/email/verify/5/'.sha1('a@example.test'), $html);

        $reminder = (new EmailConfirmationReminderNotification(2))->toMail($u);
        $this->assertStringContainsString('يومين', view($reminder->view, $reminder->viewData)->render());

        $reset = (new ResetPasswordLinkNotification('tok'))->toMail($u);
        $this->assertStringContainsString('reset-password/tok', view($reset->view, $reset->viewData)->render());

        $changed = (new PasswordChangedNotification)->toMail($u);
        $this->assertStringContainsString('تم تغيير كلمة المرور', view($changed->view, $changed->viewData)->render());
    }

    public function test_the_verification_link_is_signed_and_expires_in_24_hours(): void
    {
        $url = (new VerifyEmailNotification)->verificationUrl($this->user(null));
        $this->assertStringContainsString('signature=', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, (int) $q['expires'], 5);
    }
}
