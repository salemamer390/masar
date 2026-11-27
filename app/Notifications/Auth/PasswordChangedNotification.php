<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $subject = 'تم تغيير كلمة مرور حسابك';

        return (new MailMessage)->subject($subject)->view('emails.auth', [
            'subject' => $subject,
            'heading' => 'تم تغيير كلمة المرور',
            'lines' => [
                'تم تغيير كلمة مرور حسابك في اودير HR بتاريخ '.now()->format('d/m/Y').' الساعة '.now()->format('H:i').'.',
                'إذا كنت أنت من غيّرها، فلا حاجة لأي إجراء.',
            ],
            'actionText' => 'إعادة تعيين كلمة المرور',
            'actionUrl' => route('password.request'),
            'footnote' => 'إذا لم تقم بهذا التغيير، اضغط على الزر لاختيار كلمة مرور جديدة فورًا، وراجع مدير النظام.',
        ]);
    }
}
