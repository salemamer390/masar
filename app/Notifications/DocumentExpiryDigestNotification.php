<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DocumentExpiryDigestNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly array $documents) {}
    public function via(object $notifiable): array { return ['database']; }
    public function toDatabase(object $notifiable): array
    {
        $first = $this->documents[0] ?? [];
        return [
            'kind' => 'document_expiry',
            'count' => count($this->documents),
            'documents' => $this->documents,
            'message' => 'يوجد '.count($this->documents).' مستند يحتاج إلى مراجعة قبل انتهاء صلاحيته.',
            'url' => route('documents.index'),
            'employee_id' => $first['employee_id'] ?? null,
        ];
    }
}
