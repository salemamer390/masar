<?php

namespace App\Notifications;

use App\Models\SubscriptionRenewalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionRenewalProcessedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly SubscriptionRenewalRequest $renewal) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toDatabase(object $notifiable): array
    {
        $approved = $this->renewal->status === 'approved';
        return [
            'kind' => 'subscription_renewal_processed',
            'renewal_id' => $this->renewal->id,
            'company_id' => $this->renewal->company_id,
            'status' => $this->renewal->status,
            'message' => $approved ? 'تم اعتماد طلب الاشتراك وتفعيل الباقة.' : 'تم رفض طلب الاشتراك. يرجى مراجعة مدير المنصة.',
            'url' => route('company.account'),
        ];
    }
}
