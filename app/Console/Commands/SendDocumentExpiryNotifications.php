<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentExpiryDigestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDocumentExpiryNotifications extends Command
{
    protected $signature = 'documents:notify-expiring';
    protected $description = 'Send one digest per recipient for document expiry thresholds.';

    public function handle(): int
    {
        $thresholds = [0, 1, 3, 7, 14, 30];
        $today = today();
        $documents = Document::with('employee')->whereNotNull('expiry_date')->whereBetween('expiry_date', [$today, $today->copy()->addDays(30)])->get();
        $pending = [];
        foreach ($documents as $document) {
            $days = max(0, $today->diffInDays($document->expiry_date, false));
            $threshold = collect($thresholds)->first(fn ($value) => $days <= $value);
            if ($threshold === null) continue;
            $already = DB::table('document_expiry_notifications')->where('document_id', $document->id)->where('threshold_days', $threshold)->exists();
            if (!$already) {
                DB::table('document_expiry_notifications')->insert(['document_id' => $document->id, 'threshold_days' => $threshold, 'sent_at' => now()]);
                $pending[] = ['document_id' => $document->id, 'employee_id' => $document->employee_id, 'employee_name' => $document->employee?->full_name, 'document_name' => $document->document_name, 'expiry_date' => $document->expiry_date?->toDateString(), 'days_remaining' => $days];
            }
        }
        if (!$pending) { $this->info('No new document expiry notifications.'); return self::SUCCESS; }
        $recipientCount = 0;
        foreach (collect($pending)->groupBy(fn (array $item) => $documents->firstWhere('id', $item['document_id'])?->company_id) as $companyId => $items) {
            $recipients = User::with('role.permissions')->where('is_active', true)->where('company_id', $companyId)->get()->filter(function (User $user) {
                return in_array($user->role?->name, ['hr_manager', 'hr_specialist', 'manager'], true) || $user->hasPermission('documents.view');
            });
            foreach ($recipients as $recipient) { $recipient->notify(new DocumentExpiryDigestNotification($items->values()->all())); $recipientCount++; }
        }
        $this->info('Document expiry digests sent: '.$recipientCount.' recipient(s), '.count($pending).' item(s).');
        return self::SUCCESS;
    }
}
