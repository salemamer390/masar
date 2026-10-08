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
                'desc' => 'أنواع المستندات المطلوبة في ملف الموظف.',
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
            'name' => ['required', 'string', 'max:150'],
            'code' => ['nullable', 'string', 'max:50'],
        ]);

        $currentMeta = $meta[$type];
        $modelClass = $currentMeta['model'];

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
}
