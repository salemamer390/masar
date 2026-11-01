<?php

namespace App\Console\Commands;

use App\Models\HrRequest;
use Illuminate\Console\Command;

/**
 * Scoped cleanup for legacy overtime requests that carry no time window.
 *
 * Amer confirmed (2026-11-01) that these requests are test data and may be
 * deleted - ONLY overtime requests without from_time/to_time; every other
 * request type and all timed overtime requests stay untouched.
 *
 * Safe by default: with no --yes it only reports. Deletion requires both an
 * explicit --company scope and --yes, so it can never wipe all companies by
 * accident. Nothing schedules or runs this automatically.
 */
class PurgeUntimedOvertimeRequests extends Command
{
    protected $signature = 'requests:purge-untimed-overtime
                            {--company= : Company ID to scope the cleanup (required for deletion)}
                            {--yes : Actually delete; without it the command only reports}';

    protected $description = 'حذف طلبات الإضافي القديمة اللي من غير توقيت (من الساعة/إلى الساعة) بشكل آمن ومحدد النطاق';

    public function handle(): int
    {
        $companyId = $this->option('company');
        $delete = (bool) $this->option('yes');

        if ($delete && !$companyId) {
            $this->error('الحذف يتطلب تحديد الشركة: --company=ID مع --yes. مفيش حذف عام لكل الشركات.');
            return self::FAILURE;
        }

        $candidates = HrRequest::withoutGlobalScopes()->with('requestType')
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->where(function ($query) {
                $query->where('request_type', 'overtime')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'overtime'));
            })
            ->get(['id', 'company_id', 'employee_id', 'request_type', 'payload', 'created_at'])
            ->filter(function (HrRequest $request): bool {
                $payload = is_array($request->payload) ? $request->payload : [];
                return empty($payload['from_time']) || empty($payload['to_time']);
            });

        $byCompany = $candidates->groupBy('company_id')->map->count();
        if ($byCompany->isEmpty()) {
            $this->info('لا توجد طلبات إضافي بدون توقيت' . ($companyId ? " للشركة {$companyId}." : '.'));
            return self::SUCCESS;
        }

        $this->info('طلبات إضافي بدون توقيت' . ($companyId ? " في الشركة {$companyId}" : ' (كل الشركات - عرض فقط)') . ':');
        foreach ($byCompany as $company => $count) {
            $this->line("  شركة {$company}: {$count} طلب");
        }
        $this->line("  الإجمالي: {$candidates->count()}");

        if (!$delete) {
            $this->warn('وضع العرض فقط (dry-run). للحذف الفعلي: --company=ID --yes');
            return self::SUCCESS;
        }

        $ids = $candidates->pluck('id');
        $deleted = HrRequest::withoutGlobalScopes()->whereIn('id', $ids)->delete();
        $this->info("تم حذف {$deleted} طلب إضافي بدون توقيت من الشركة {$companyId}.");
        return self::SUCCESS;
    }
}
