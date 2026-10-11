<?php

namespace App\Notifications;

use App\Models\SubscriptionRenewalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly SubscriptionRenewalRequest $renewal) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'subscription_renewal_requested',
            'renewal_id' => $this->renewal->id,
            'company_id' => $this->renewal->company_id,
            'message' => 'يوجد طلب تجديد أو ترقية اشتراك جديد يحتاج إلى المراجعة.',
            'url' => route('subscriptions.index'),
        ];
    }
}
