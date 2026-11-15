<?php

namespace App\Http\Controllers;

use App\Services\ImportedOutOfPeriodCleanupService;
use Illuminate\Http\Request;

/**
 * Self-service review of operational rows an Excel import created with dates
 * before the current payroll period. The page is the dry run: it lists exactly
 * the import-created candidates and counts. Only checking listed rows and
 * confirming deletes those IDs; manual records and payroll snapshots never appear.
 */
class ImportedOutOfPeriodRecordController extends Controller
{
    public function index(Request $request, ImportedOutOfPeriodCleanupService $cleanup)
    {
        abort_unless($request->user()?->hasPermission('employees.import'), 403, __('لا توجد صلاحية لمراجعة سجلات الاستيراد.'));
        abort_unless($cleanup->available(), 503, __('قاعدة البيانات تحتاج تشغيل الترحيلات (php artisan migrate) قبل مراجعة سجلات الاستيراد.'));

        return view('employees.import-outofperiod', [
            'cutoff' => $cleanup->currentPeriodStart(),
            'groups' => $cleanup->candidates(),
            'labels' => ['leaves' => __('إجازات'), 'penalties' => __('جزاءات'), 'requests' => __('طلبات')],
        ]);
    }

    public function destroy(Request $request, ImportedOutOfPeriodCleanupService $cleanup)
    {
        abort_unless($request->user()?->hasPermission('employees.import'), 403, __('لا توجد صلاحية لتنظيف سجلات الاستيراد.'));
        abort_unless($cleanup->available(), 503, __('قاعدة البيانات تحتاج تشغيل الترحيلات (php artisan migrate) قبل تنظيف سجلات الاستيراد.'));
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.leaves' => ['nullable', 'array'], 'ids.leaves.*' => ['integer'],
            'ids.penalties' => ['nullable', 'array'], 'ids.penalties.*' => ['integer'],
            'ids.requests' => ['nullable', 'array'], 'ids.requests.*' => ['integer'],
        ]);
        $deleted = $cleanup->deleteConfirmed($data['ids'], $request->user()?->id);
        $count = array_sum(array_map('count', $deleted));

        return back()->with('toast', __('تم حذف :count سجل مستورد بتاريخ قديم.', ['count' => $count]));
    }
}
