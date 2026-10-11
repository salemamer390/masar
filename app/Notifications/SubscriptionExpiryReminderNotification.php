<?php

namespace App\Notifications;

use App\Models\CompanySubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly CompanySubscription $subscription,
        private readonly int $daysRemaining,
    ) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        $company = $this->subscription->company?->name ?? 'الشركة';
        $message = $this->daysRemaining === 0
            ? "ينتهي اشتراك {$company} اليوم. يرجى تجديده لتجنب توقف الخدمة."
            : "يتبقى {$this->daysRemaining} يومًا على انتهاء اشتراك {$company}.";

        return [
            'kind' => 'subscription_expiry_reminder',
            'company_id' => $this->subscription->company_id,
            'subscription_id' => $this->subscription->id,
            'days_remaining' => $this->daysRemaining,
            'message' => $message,
            'url' => $notifiable->isSuperAdmin() ? route('subscriptions.index') : route('company.account'),
        ];
    }
}
