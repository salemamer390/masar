<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\HrRequest;
use App\Models\Leave;
use App\Models\Penalty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lists and removes only operational records that an employee-workbook import
 * created (import_batch_id) and that belong to a payroll period before the
 * company's current one. Manually entered rows and payroll snapshots are never
 * in scope. The listing is always the exact set a later confirmation deletes.
 */
class ImportedOutOfPeriodCleanupService
{
    public function __construct(private readonly CurrentPayrollPeriodImportGuard $guard) {}

    public function available(): bool
    {
        return Schema::hasTable('employee_import_batches')
            && Schema::hasColumn('leaves', 'import_batch_id')
            && Schema::hasColumn('penalties', 'import_batch_id')
            && Schema::hasColumn('hr_requests', 'import_batch_id');
    }

    public function currentPeriodStart(): string
    {
        return $this->guard->currentStart()->toDateString();
    }

    /** @return array<string, Collection> keyed by domain, each row a plain array for display/delete */
    public function candidates(): array
    {
        if (!$this->available()) return ['leaves' => collect(), 'penalties' => collect(), 'requests' => collect()];
        $cutoff = $this->currentPeriodStart();

        return [
            'leaves' => Leave::withoutGlobalScopes()->with(['employee', 'leaveType'])
                ->whereNotNull('import_batch_id')->whereDate('start_date', '<', $cutoff)->orderBy('start_date')->get()
                ->map(fn (Leave $row) => ['id' => $row->id, 'employee' => $row->employee?->full_name ?? '—', 'kind' => $row->leaveType?->name ?? '—', 'date' => $row->start_date?->format('Y-m-d'), 'end' => $row->end_date?->format('Y-m-d'), 'batch' => $row->import_batch_id]),
            'penalties' => Penalty::withoutGlobalScopes()->with(['employee', 'penaltyType'])
                ->whereNotNull('import_batch_id')->whereDate('penalty_date', '<', $cutoff)->orderBy('penalty_date')->get()
                ->map(fn (Penalty $row) => ['id' => $row->id, 'employee' => $row->employee?->full_name ?? '—', 'kind' => $row->penaltyType?->name ?? ($row->title ?? '—'), 'date' => $row->penalty_date?->format('Y-m-d'), 'end' => null, 'batch' => $row->import_batch_id]),
            'requests' => HrRequest::withoutGlobalScopes()->with(['employee', 'requestType'])
                ->whereNotNull('import_batch_id')
                ->where(fn ($query) => $query->whereDate(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.request_date'))"), '<', $cutoff)
                    ->orWhere(fn ($fallback) => $fallback->whereNull(DB::raw("JSON_EXTRACT(payload, '$.request_date')"))->whereDate('created_at', '<', $cutoff)))
                ->orderBy('id')->get()
                ->map(fn (HrRequest $row) => ['id' => $row->id, 'employee' => $row->employee?->full_name ?? '—', 'kind' => $row->requestType?->display_name ?? $row->title ?? '—', 'date' => data_get($row->payload, 'request_date') ?: $row->created_at?->toDateString(), 'end' => data_get($row->payload, 'end_date'), 'batch' => $row->import_batch_id]),
        ];
    }

    /** Delete exactly the listed candidate IDs, never anything else. */
    public function deleteConfirmed(array $ids, ?int $userId = null): array
    {
        abort_unless($this->available(), 503, __('قاعدة البيانات تحتاج تشغيل الترحيلات قبل تنظيف سجلات الاستيراد.'));
        $candidates = $this->candidates();
        $deleted = [];

        DB::transaction(function () use ($ids, $candidates, &$deleted, $userId): void {
            foreach (['leaves' => Leave::class, 'penalties' => Penalty::class, 'requests' => HrRequest::class] as $domain => $model) {
                $allowed = $candidates[$domain]->pluck('id')->map(fn ($id) => (int) $id)->all();
                $wanted = array_values(array_intersect(array_map('intval', (array) ($ids[$domain] ?? [])), $allowed));
                if ($wanted === []) continue;
                $rows = $model::withoutGlobalScopes()->whereIn('id', $wanted)->get();
                foreach ($rows as $row) {
                    $snapshot = $row->toArray();
                    $row->delete();
                    ActivityLog::record('imported_out_of_period_delete', 'حُذف سجل مستورد بتاريخ قبل فترة المرتب الحالية.', null, $snapshot, null, 'imports');
                    $deleted[$domain][] = $snapshot['id'] ?? null;
                }
            }
        });

        return $deleted;
    }
}
