<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Job;
use App\Models\LeaveType;
use App\Models\MovementType;
use App\Models\PaymentMethod;
use App\Models\PenaltyType;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class MasterDataController extends Controller
{
    private function getMeta(): array
    {
        return [
            'departments' => [
                'title' => 'الأقسام',
                'desc' => 'الأقسام التنظيمية المستخدمة في بيانات الموظفين',
                'model' => Department::class,
                'name_col' => 'name',
                'code_col' => 'id',
            ],
            'jobs' => [
                'title' => 'الوظائف',
                'desc' => 'المسميات الوظيفية والأكواد',
                'model' => Job::class,
                'name_col' => 'title',
                'code_col' => 'job_code',
            ],
            'branches' => [
                'title' => 'الفروع',
                'desc' => 'فروع ومنشآت الشركة',
                'model' => Branch::class,
                'name_col' => 'name',
                'code_col' => 'address',
            ],
            'employment' => [
                'title' => 'أنواع التوظيف',
                'desc' => 'أنواع وعقود العمل',
                'model' => EmploymentType::class,
                'name_col' => 'name',
                'code_col' => 'id',
            ],
            'leavetypes' => [
                'title' => 'أنواع الإجازات',
                'desc' => 'أنواع الإجازات المتاحة',
                'model' => LeaveType::class,
                'name_col' => 'name',
                'code_col' => 'default_days',
            ],
            'statuses' => [
                'title' => 'حالات الموظفين',
                'desc' => 'الحالات التشغيلية للموظف',
                'model' => EmployeeStatus::class,
                'name_col' => 'name',
                'code_col' => 'id',
            ],
            'penalties' => [
                'title' => 'أنواع الجزاءات',
                'desc' => 'تصنيفات الجزاءات والمخالفات',
                'model' => PenaltyType::class,
                'name_col' => 'name',
                'code_col' => 'id',
            ],
            'movements' => [
                'title' => 'أنواع الحركات',
                'desc' => 'الحركات الوظيفية المتاحة',
                'model' => MovementType::class,
                'name_col' => 'name',
                'code_col' => 'id',
            ],
            'payment' => [
                'title' => 'طرق صرف الراتب',
                'desc' => 'طرق صرف الرواتب للموظفين',
                'model' => PaymentMethod::class,
                'name_col' => 'name',
                'code_col' => 'id',
            ],
            'documents' => [
                'title' => 'أنواع المستندات',
                'desc' => 'مستندات أساسية، ومستندات خاصة بالوظيفة، ومستندات خاصة بمكتب العمل.',
                'model' => DocumentType::class,
                'name_col' => 'name',
                'code_col' => 'is_required',
            ],
        ];
    }

    public function index(Request $request)
    {
        $type = $request->query('type', 'departments');
        $meta = $this->getMeta();
        if (!isset($meta[$type])) {
            $type = 'departments';
        }

        $counts = [
            'departments' => Department::count(),
            'jobs' => Job::count(),
            'branches' => Branch::count(),
            'employment' => EmploymentType::count(),
            'leavetypes' => LeaveType::count(),
            'statuses' => EmployeeStatus::count(),
            'penalties' => PenaltyType::count(),
            'movements' => MovementType::count(),
            'payment' => PaymentMethod::count(),
            'documents' => DocumentType::count(),
        ];

        $currentMeta = $meta[$type];
        $modelClass = $currentMeta['model'];
        $items = $modelClass::all();

        return view('master.index', compact('type', 'meta', 'currentMeta', 'counts', 'items'));
    }

    public function showType(string $type)
    {
        $meta = $this->getMeta();
        if (!isset($meta[$type])) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $currentMeta = $meta[$type];
        $modelClass = $currentMeta['model'];
        $items = $modelClass::all();

        return response()->json([
            'title' => $currentMeta['title'],
            'desc' => $currentMeta['desc'],
            'items' => $items,
        ]);
    }

    public function store(Request $request, string $type)
    {
        $meta = $this->getMeta();
        if (!isset($meta[$type])) {
            abort(404);
        }

        $request->validate([
            'name' => array_merge(
                ['required', 'string', 'max:150'],
                $type === 'documents'
                    ? [Rule::unique('document_types', 'name')->where(fn ($query) => $query->where('company_id', auth()->user()->company_id))]
                    : []
            ),
            'code' => ['nullable', 'string', 'max:50'],
            'category' => $type === 'documents' ? ['required', 'in:basic,job,workplace'] : ['nullable'],
        ], [
            'name.unique' => 'نوع المستند بهذا الاسم موجود بالفعل. استخدم اسمًا مختلفًا أو عدّل النوع الموجود.',
        ]);

        $currentMeta = $meta[$type];
        $modelClass = $currentMeta['model'];

        abort_if(!auth()->user()->isSuperAdmin() && !Schema::hasColumn((new $modelClass)->getTable(), 'company_id'), 403, 'هذه قائمة عامة لا يمكن تعديلها من حساب الشركة.');
        $nameCol = $currentMeta['name_col'];
        $data = [$nameCol => $request->name];

        if ($type === 'jobs' && $request->filled('code')) {
            $data['job_code'] = $request->code;
        } elseif ($type === 'branches' && $request->filled('code')) {
            $data['address'] = $request->code;
        } elseif ($type === 'leavetypes' && $request->filled('code')) {
            $data['default_days'] = (float) $request->code;
        } elseif ($type === 'documents') {
            $data['is_required'] = $request->boolean('required');
            $data['category'] = $request->input('category', 'basic');
            $data['is_active'] = true;
        }

        $model = $modelClass::create($data);
        ActivityLog::record('master_create', "تمت إضافة عنصر ({$request->name}) إلى قائمة {$currentMeta['title']}.", $model);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تمت إضافة العنصر بنجاح',
                'item' => $model,
            ]);
        }

        return redirect()->route('master.index', ['type' => $type])->with('toast', 'تمت إضافة العنصر بنجاح');
    }

    public function updateDocument(Request $request, DocumentType $documentType)
    {
        abort_if(!$documentType->company_id && !auth()->user()->isSuperAdmin(), 403, 'لا يمكن تعديل العنصر العام من حساب الشركة.');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('document_types', 'name')->where(fn ($query) => $query->where('company_id', $documentType->company_id))->ignore($documentType->id)],
            'category' => ['required', 'in:basic,job,workplace'],
            'required' => ['nullable', 'boolean'],
        ], [
            'name.unique' => 'نوع المستند بهذا الاسم موجود بالفعل. استخدم اسمًا مختلفًا.',
        ]);

        $documentType->update([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'is_required' => $request->boolean('required'),
        ]);

        ActivityLog::record('document_type_update', "تم تعديل نوع المستند ({$documentType->name}).", $documentType);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تعديل نوع المستند بنجاح.',
                'item' => $documentType->fresh(),
            ]);
        }

        return redirect()->route('master.index', ['type' => 'documents'])->with('toast', 'تم تعديل نوع المستند بنجاح');
    }

    public function update(Request $request, string $type, int $item)
    {
        $meta = $this->getMeta();
        abort_if(!isset($meta[$type]) || $type === 'documents', 404);
        $currentMeta = $meta[$type];
        $model = $currentMeta['model']::query()->findOrFail($item);
        abort_if(!auth()->user()->isSuperAdmin() && !$model->company_id, 403, 'لا يمكن تعديل أو حذف العنصر العام من حساب الشركة.');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:100'],
        ]);
        $data = [$currentMeta['name_col'] => $validated['name']];
        if ($type === 'jobs') $data['job_code'] = $validated['code'] ?: null;
        if ($type === 'branches') $data['address'] = $validated['code'] ?: null;
        if ($type === 'leavetypes') $data['default_days'] = $validated['code'] === '' ? null : (float) $validated['code'];
        $model->update($data);
        ActivityLog::record('master_update', "تم تعديل عنصر من قائمة {$currentMeta['title']}: {$validated['name']}", $model);
        return redirect()->route('master.index', ['type' => $type])->with('toast', 'تم حفظ التعديل بنجاح');
    }

    public function destroy(Request $request, string $type, int $item)
    {
        $meta = $this->getMeta();
        abort_if(!isset($meta[$type]) || $type === 'documents', 404);
        $currentMeta = $meta[$type];
        $model = $currentMeta['model']::query()->findOrFail($item);
        abort_if(!auth()->user()->isSuperAdmin() && !$model->company_id, 403, 'لا يمكن تعديل أو حذف العنصر العام من حساب الشركة.');
        $name = (string) ($model->{$currentMeta['name_col']} ?? '');
        if (Schema::hasColumn($model->getTable(), 'is_active')) {
            $model->forceFill(['is_active' => false])->save();
            $message = 'تم إيقاف العنصر بنجاح';
        } else {
            try {
                $model->delete();
                $message = 'تم حذف العنصر بنجاح';
            } catch (\Throwable) {
                return back()->withErrors(['master' => 'لا يمكن حذف هذا العنصر لأنه مستخدم في بيانات أخرى.']);
            }
        }
        ActivityLog::record('master_delete', "تم حذف/إيقاف عنصر من قائمة {$currentMeta['title']}: {$name}", $model);
        return redirect()->route('master.index', ['type' => $type])->with('toast', $message);
    }

    public function destroyDocument(Request $request, DocumentType $documentType)
    {
        abort_if(!$documentType->company_id && !auth()->user()->isSuperAdmin(), 403, 'لا يمكن حذف العنصر العام من حساب الشركة.');
        if ($documentType->documents()->exists()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => 'لا يمكن حذف نوع مستند مستخدم في مسوغات الموظفين. يمكنك نقله لقسم آخر أو إلغاء كونه مطلوبًا.',
                ], 422);
            }

            return redirect()->route('master.index', ['type' => 'documents'])
                ->with('error', 'لا يمكن حذف نوع مستند مستخدم في مسوغات الموظفين. يمكنك نقله لقسم آخر أو إلغاء كونه مطلوبًا.');
        }

        $name = $documentType->name;
        $documentType->delete();
        ActivityLog::record('document_type_delete', "تم حذف نوع المستند ({$name}).");

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم حذف نوع المستند بنجاح.',
                'item_id' => $documentType->id,
            ]);
        }

        return redirect()->route('master.index', ['type' => 'documents'])->with('toast', 'تم حذف نوع المستند بنجاح');
    }
}
