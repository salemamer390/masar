<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\OwnerDatabaseUpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OwnerDatabaseUpdateController extends Controller
{
    public function run(Request $request, OwnerDatabaseUpdateService $updates)
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'current_password:owner'],
            'confirm_backup' => ['accepted'],
            'confirm_partial_risk' => ['accepted'],
        ], [
            'password.required' => __('أدخل كلمة مرور حساب المالك الحالية.'),
            'password.current_password' => __('كلمة مرور حساب المالك الحالية غير صحيحة.'),
            'confirm_backup.accepted' => __('أكد أن لديك نسخة احتياطية حديثة قبل التنفيذ.'),
            'confirm_partial_risk.accepted' => __('أكد فهمك أن تحديث MySQL قد يتوقف وهو مكتمل جزئيًا.'),
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->except('password'))
                ->with('open_owner_tab', 'database-updates');
        }

        $result = $updates->runNext();
        $this->recordActivity($request, $result);

        if ($result['ok']) {
            return redirect()
                ->route('owner.index')
                ->with('open_owner_tab', 'database-updates')
                ->with('toast', $result['message']);
        }

        return redirect()
            ->route('owner.index')
            ->with('open_owner_tab', 'database-updates')
            ->withErrors(['database_update' => $result['message']]);
    }

    private function recordActivity(Request $request, array $result): void
    {
        $migration = $result['migration'] ?? __('بدون تحديث');
        $needsReview = in_array($result['code'] ?? null, [
            'manual_review_required',
            'failed',
            'exception',
            'marker_cleanup_failed',
        ], true);
        $description = match (true) {
            $result['ok'] => __('تم تنفيذ تحديث قاعدة البيانات :migration.', ['migration' => $migration]),
            $needsReview => __('توقف تنفيذ تحديث قاعدة البيانات :migration ويحتاج مراجعة يدوية قبل أي محاولة أخرى.', ['migration' => $migration]),
            default => __('فشل تنفيذ تحديث قاعدة البيانات :migration.', ['migration' => $migration]),
        };
        $action = match (true) {
            $result['ok'] => 'database_update_completed',
            $needsReview => 'database_update_attention_required',
            default => 'database_update_failed',
        };

        ActivityLog::create([
            'company_id' => null,
            'user_id' => $request->user('owner')?->id,
            'action' => $action,
            'description' => $description,
            'module' => 'database',
            'new_values' => array_filter([
                'result' => $result['code'] ?? null,
                'migration' => $result['migration'] ?? null,
                'pending_before' => $result['pending_before'] ?? null,
                'pending_after' => $result['pending_after'] ?? null,
                'exit_code' => $result['exit_code'] ?? null,
                'output' => filled($result['output'] ?? null) ? Str::limit($result['output'], 500) : null,
            ], fn ($value) => ! is_null($value)),
            'ip_address' => $request->ip(),
        ]);
    }
}
