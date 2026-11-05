<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRow;
use App\Models\EmployeeImportRowRevision;
use App\Models\MovementRecord;
use App\Services\Imports\EmployeeImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * In-app review queue for rejected personnel-import rows: inspect the reason,
 * fix the values, revalidate with the real import rules, save with a mandatory
 * reason, or dismiss - every step audited (who/when/why).
 */
class EmployeeImportReviewController extends Controller
{
    public function index(Request $request)
    {
        $rows = EmployeeImportRow::with(['batch', 'employee'])
            ->where('status', 'pending')
            ->orderBy('id')
            ->paginate(30);

        return view('employees.import-review.index', [
            'rows' => $rows,
            'count' => EmployeeImportRow::where('status', 'pending')->count(),
            'batches' => EmployeeImportBatch::with('user')->latest()->limit(20)->get(),
        ]);
    }

    public function show(EmployeeImportRow $row)
    {
        $row->load(['batch', 'employee', 'revisions.user']);

        return view('employees.import-review.show', [
            'row' => $row,
            'payload' => $row->payload ?? [],
            'fields' => EmployeeImportService::COLUMNS,
        ]);
    }

    public function update(Request $request, EmployeeImportRow $row, EmployeeImportService $service)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'string', 'max:5000'],
        ]);

        // Only known import fields may be corrected.
        $values = [];
        foreach (EmployeeImportService::COLUMNS as $key => [$label]) {
            if (array_key_exists($key, $data['values'])) {
                $values[$key] = trim((string) $data['values'][$key]);
            }
        }

        return DB::transaction(function () use ($request, $row, $service, $data, $values) {
            $row = EmployeeImportRow::lockForUpdate()->findOrFail($row->id);
            abort_if((int) $data['version'] !== $row->revisions()->count(), 409, 'اتغير الصف بعد فتحه؛ أعد تحميل الصفحة.');
            abort_if($row->status !== 'pending', 422, 'هذا الصف تمت مراجعته بالفعل.');
            abort_if($row->kind !== 'employees', 422, 'هذا النوع من الصفوف يُصحَّح من شاشته الأصلية ثم يُستبعد هنا.');

            // Re-validate against live data with the same rules as a fresh import.
            $analyzed = $service->analyzeSingleRow($values, (int) $row->company_id);
            if ($analyzed['action'] === 'error') {
                return back()->withInput()->withErrors(['values' => implode(' | ', $analyzed['errors'])]);
            }
            if ($analyzed['action'] === 'skip') {
                $analyzed['action'] = 'update';
            }

            $result = $service->commit(['rows' => [$analyzed], 'summary' => ['skip' => 0]], (int) $row->company_id);
            $employeeId = $result['created_ids'][0] ?? $result['updated_ids'][0] ?? null;
            if ($employeeId === null) {
                $messages = collect($result['failed'] ?? [])->flatMap(fn ($f) => (array) ($f['messages'] ?? []))->implode(' | ');

                return back()->withInput()->withErrors(['values' => $messages !== '' ? $messages : 'تعذر حفظ الصف بعد التصحيح.']);
            }

            $before = ['payload' => $row->payload, 'errors' => $row->errors, 'status' => $row->status];
            Employee::withoutGlobalScopes()->whereKey($employeeId)->update(['import_batch_id' => $row->batch_id]);
            if (!empty($result['movement_ids'])) {
                MovementRecord::withoutGlobalScopes()->whereIn('id', $result['movement_ids'])->update(['import_batch_id' => $row->batch_id]);
            }

            $row->update([
                'status' => 'fixed',
                'employee_id' => $employeeId,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);
            EmployeeImportRowRevision::create([
                'company_id' => $row->company_id,
                'import_row_id' => $row->id,
                'user_id' => $request->user()->id,
                'action' => 'fix',
                'reason' => $data['reason'],
                'before_data' => $before,
                'after_data' => ['values' => $values, 'status' => 'fixed', 'employee_id' => $employeeId],
                'created_at' => now(),
            ]);

            return redirect()->route('employees.import-rows.show', $row)->with('toast', 'تم حفظ الصف بعد التصحيح وربطه بالموظف.');
        });
    }

    public function destroy(Request $request, EmployeeImportRow $row)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $row, $data): void {
            $row = EmployeeImportRow::lockForUpdate()->findOrFail($row->id);
            abort_if((int) $data['version'] !== $row->revisions()->count(), 409, 'اتغير الصف بعد فتحه؛ أعد تحميل الصفحة.');
            abort_if($row->status !== 'pending', 422, 'هذا الصف تمت مراجعته بالفعل.');

            $before = ['payload' => $row->payload, 'errors' => $row->errors, 'status' => $row->status];
            $row->update([
                'status' => 'dismissed',
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);
            EmployeeImportRowRevision::create([
                'company_id' => $row->company_id,
                'import_row_id' => $row->id,
                'user_id' => $request->user()->id,
                'action' => 'dismiss',
                'reason' => $data['reason'],
                'before_data' => $before,
                'after_data' => ['status' => 'dismissed'],
                'created_at' => now(),
            ]);
        });

        return redirect()->route('employees.import-batches.index')->with('toast', 'تم استبعاد الصف مع الاحتفاظ بالأصل وسجل السبب.');
    }
}
