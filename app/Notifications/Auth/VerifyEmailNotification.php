<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification
{
    public const VALID_HOURS = 24;

    public function __construct(public bool $welcome = false) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addHours(self::VALID_HOURS),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())]
        );
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = $this->welcome ? 'مرحبًا بك في اودير HR، أكّد بريدك الإلكتروني' : 'تأكيد البريد الإلكتروني';
        $lines = $this->welcome
            ? ['تم إنشاء حساب شركتك بنجاح. لتأمين حسابك واستلام تنبيهات النظام، أكّد أن هذا البريد يخصّك بالضغط على الزر التالي.']
            : ['اضغط على الزر التالي لتأكيد بريدك الإلكتروني وتأمين حسابك.'];

        return (new MailMessage)->subject($subject)->view('emails.auth', [
            'subject' => $subject,
            'heading' => $this->welcome ? 'أهلاً '.$notifiable->name : 'تأكيد البريد الإلكتروني',
            'lines' => array_merge($lines, ['لم تجد هذه الرسالة؟ افحص مجلد الرسائل غير المرغوبة (Spam) أو الترويجات.']),
            'actionText' => 'تأكيد البريد الإلكتروني',
            'actionUrl' => $this->verificationUrl($notifiable),
            'footnote' => ($notifiable->emailConfirmationPending() ? 'مهم: سيتم إغلاق الحساب إذا لم يتم تأكيد البريد خلال '.\App\Support\EmailConfirmation::GRACE_DAYS.' أيام. بياناتك لا تُحذف، ويعود الحساب للعمل فور التأكيد. ' : '').'الرابط صالح لمدة '.self::VALID_HOURS.' ساعة، ويمكنك طلب رابط جديد من داخل التطبيق. إذا لم تنشئ هذا الحساب، تجاهل الرسالة.',
        ]);
    }
}
