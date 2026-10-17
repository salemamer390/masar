<?php

namespace App\Http\Controllers;

use App\Services\Imports\EmployeeImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeImportController extends Controller
{
    public function __construct(private readonly EmployeeImportService $service)
    {
    }

    /** Download the ready-made Excel template. */
    public function template()
    {
        $path = resource_path('import-templates/employees-import-template.xlsx');
        abort_unless(is_file($path), 404, 'ملف النموذج غير موجود.');

        return response()->download($path, 'employees-import-template.xlsx', ['Cache-Control' => 'no-store']);
    }

    /** Step 1: validate the file and report what would happen. Nothing is written. */
    public function preview(Request $request): JsonResponse
    {
        return $this->process($request, false);
    }

    /** Step 2: import the valid rows. */
    public function store(Request $request): JsonResponse
    {
        return $this->process($request, true);
    }

    private function process(Request $request, bool $commit): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'duplicates' => ['nullable', 'in:update,skip'],
        ], [
            'file.required' => 'اختر ملف Excel أو CSV أولًا.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.max' => 'حجم الملف يجب ألا يتجاوز 5 ميجابايت.',
            'file.uploaded' => 'تعذر رفع الملف. تحقق من حجمه (الحد 5 ميجابايت) وحاول مرة أخرى.',
        ]);

        $file = $request->file('file');
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'csv'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'يجب أن يكون الملف بصيغة xlsx أو csv. لتحويل ملف xls قديم: افتحه في Excel ثم احفظه باسم «Excel Workbook (.xlsx)».',
            ], 422);
        }

        $companyId = (int) (auth()->user()->company_id ?: DB::table('companies')->value('id'));
        if ($companyId <= 0) {
            return response()->json(['success' => false, 'message' => 'لا توجد شركة مرتبطة بالحساب لاستيراد الموظفين إليها.'], 422);
        }

        $analysis = $this->service->analyze($file->getRealPath(), $extension, $companyId, (string) $request->input('duplicates', 'update'));

        if ($analysis['file_errors'] !== []) {
            return response()->json(['success' => false, 'message' => $analysis['file_errors'][0]], 422);
        }

        $payload = $this->payload($analysis);
        if (!$commit) {
            return response()->json(['success' => true] + $payload);
        }

        if (!empty($analysis['capacity']['exceeded'])) {
            return response()->json(['success' => false, 'message' => 'عدد الموظفين بعد الاستيراد يتجاوز حد باقتك. عدّل الملف أو رقّ الباقة.'] + $payload, 422);
        }
        if (($analysis['summary']['create'] + $analysis['summary']['update']) === 0) {
            return response()->json(['success' => false, 'message' => 'لا توجد صفوف صالحة للاستيراد.'] + $payload, 422);
        }

        $result = $this->service->commit($analysis, $companyId);

        return response()->json([
            'success' => true,
            'message' => "تم الاستيراد: {$result['created']} موظف جديد، {$result['updated']} تم تحديثهم.",
            'result' => $result,
        ] + $payload);
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array<string, mixed>
     */
    private function payload(array $analysis): array
    {
        $rows = $analysis['rows'];
        $issues = [];
        foreach ($rows as $row) {
            if ($row['errors'] !== [] || $row['warnings'] !== []) {
                $issues[] = [
                    'line' => $row['line'],
                    'name' => $row['name'],
                    'type' => $row['errors'] !== [] ? 'error' : 'warning',
                    'messages' => array_merge($row['errors'], $row['warnings']),
                ];
            }
        }

        return [
            'summary' => $analysis['summary'],
            'capacity' => $analysis['capacity'],
            'new_lookups' => $analysis['new_lookups'],
            'unknown_columns' => $analysis['unknown_columns'],
            'rows' => array_map(static fn (array $row): array => [
                'line' => $row['line'],
                'name' => $row['name'],
                'code' => $row['code'],
                'action' => $row['action'],
                'messages' => array_merge($row['errors'], $row['warnings']),
            ], array_slice($rows, 0, 150)),
            'issues' => array_slice($issues, 0, 1500),
        ];
    }
}
