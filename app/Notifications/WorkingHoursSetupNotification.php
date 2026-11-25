<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Sent once when a user finishes or skips the guided tour: the working-hours
 * settings must be configured before any data is entered.
 */
class WorkingHoursSetupNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'setup',
            'message' => 'مطلوب منك الآن: اضبط الدوام اليومي ودورة المسير وتصحيح الحضور وأيام الراحة الأسبوعية في الإعدادات (تبويب أوقات الدوام) قبل إدخال أي بيانات.',
            'url' => route('payroll.settings.index', ['setup' => 1]),
        ];
    }
}
