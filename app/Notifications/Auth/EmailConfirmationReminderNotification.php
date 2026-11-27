<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailConfirmationReminderNotification extends Notification
{
    public function __construct(public int $daysLeft) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    private function dayText(): string
    {
        return match (true) {
            $this->daysLeft <= 1 => 'يوم واحد',
            $this->daysLeft === 2 => 'يومين',
            default => $this->daysLeft.' أيام',
        };
    }

    public function toDatabase($notifiable): array
    {
        return [
            'kind' => 'email_confirmation',
            'message' => 'أكّد بريدك الإلكتروني. سيتم إغلاق حسابك بعد '.$this->dayText().' إذا لم تؤكده. افحص مجلد الرسائل غير المرغوبة (Spam) إن لم تجد الرسالة.',
            'url' => route('dashboard'),
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = 'تذكير: أكّد بريدك الإلكتروني قبل إغلاق الحساب';
        $verify = new VerifyEmailNotification();

        return (new MailMessage)->subject($subject)->view('emails.auth', [
            'subject' => $subject,
            'heading' => 'بقي '.$this->dayText().' على إغلاق حسابك',
            'lines' => [
                'لم نستلم تأكيد بريدك الإلكتروني بعد. إذا لم تؤكده، سيتم إغلاق حسابك بعد '.$this->dayText().'.',
                'بياناتك آمنة ولا تُحذف، ويعود الحساب للعمل فور التأكيد.',
                'لم تجد رسالتنا الأولى؟ افحص مجلد الرسائل غير المرغوبة (Spam) أو الترويجات.',
            ],
            'actionText' => 'تأكيد البريد الإلكتروني',
            'actionUrl' => $verify->verificationUrl($notifiable),
            'footnote' => 'الرابط صالح لمدة 24 ساعة، ويمكنك طلب رابط جديد من داخل التطبيق.',
        ]);
    }
}
