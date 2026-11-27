<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLinkNotification extends Notification
{
    public function __construct(public string $token) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function resetUrl($notifiable): string
    {
        return route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $subject = 'إعادة تعيين كلمة المرور';

        return (new MailMessage)->subject($subject)->view('emails.auth', [
            'subject' => $subject,
            'heading' => 'إعادة تعيين كلمة المرور',
            'lines' => ['وصلنا طلب لإعادة تعيين كلمة مرور حسابك في اودير HR. اضغط على الزر التالي لاختيار كلمة مرور جديدة.'],
            'actionText' => 'اختيار كلمة مرور جديدة',
            'actionUrl' => $this->resetUrl($notifiable),
            'footnote' => 'الرابط صالح لمدة '.$minutes.' دقيقة ويعمل مرة واحدة. إذا لم تطلب ذلك، تجاهل الرسالة وستبقى كلمة مرورك كما هي.',
        ]);
    }
}
