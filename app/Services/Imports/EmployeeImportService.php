<?php

namespace App\Services\Imports;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CompanySubscription;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeStatus;
use App\Models\EmploymentType;
use App\Models\Job;
use App\Models\MovementRecord;
use App\Models\MovementType;
use App\Models\Salary;
use App\Services\PayrollCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Bulk employee import (Excel / CSV).
 *
 * analyze() is read-only: it parses the file, validates every row with the same
 * rules used by the "add employee" form and reports what would happen.
 * commit() re-uses an analysis and writes the valid rows inside one DB
 * transaction (each row in its own savepoint so a single failing row never
 * leaves a half-saved employee).
 */
class EmployeeImportService
{
    public const MAX_ROWS = 3000;

    /** Employees in these statuses do not consume the plan's employee limit (same rule as EmployeeController). */
    private const INACTIVE_STATUSES = ['منتهي الخدمة', 'موقوف', 'قيد التعيين', 'Terminated', 'Suspended', 'Pending'];

    /**
     * key => [Arabic label (also the template header), accepted header aliases]
     */
    public const COLUMNS = [
        'employee_code' => ['كود الموظف', ['الكود', 'كود', 'رقم الموظف', 'employee code', 'employee_code', 'emp code', 'code']],
        'full_name' => ['الاسم الكامل', ['الاسم', 'اسم الموظف', 'اسم', 'full name', 'full_name', 'name', 'employee name']],
        'national_id' => ['الرقم القومي', ['رقم قومي', 'الرقم القومى', 'رقم البطاقة', 'national id', 'national_id', 'nid']],
        'insurance_number' => ['الرقم التأميني', ['رقم التأمين', 'الرقم التامينى', 'رقم التأمين الاجتماعي', 'insurance number', 'insurance_number']],
        'phone' => ['رقم الهاتف', ['الهاتف', 'الموبايل', 'رقم الموبايل', 'التليفون', 'رقم التليفون', 'phone', 'mobile']],
        'email' => ['البريد الإلكتروني', ['البريد', 'الايميل', 'الإيميل', 'email', 'e-mail']],
        'birth_date' => ['تاريخ الميلاد', ['الميلاد', 'birth date', 'birth_date', 'date of birth', 'dob']],
        'gender' => ['النوع', ['الجنس', 'gender', 'sex']],
        'marital_status' => ['الحالة الاجتماعية', ['الحاله الاجتماعيه', 'marital status', 'marital_status']],
        'qualification' => ['المؤهل', ['المؤهل الدراسي', 'المؤهل العلمي', 'qualification', 'education']],
        'address' => ['العنوان', ['address']],
        'nationality' => ['الجنسية', ['nationality']],
        'department' => ['القسم', ['الإدارة', 'الادارة', 'department']],
        'job' => ['الوظيفة', ['المسمى الوظيفي', 'المسمى', 'job', 'job title', 'title', 'position']],
        'branch' => ['الفرع', ['مكان العمل', 'branch', 'work location']],
        'employment_type' => ['نوع التوظيف', ['نوع التعاقد', 'نوع العمل', 'employment type', 'employment_type']],
        'status' => ['الحالة الوظيفية', ['حالة الموظف', 'الحالة', 'status']],
        'hire_date' => ['تاريخ التعيين', ['تاريخ المباشرة', 'تاريخ الالتحاق', 'hire date', 'hire_date', 'join date']],
        'contract_start_date' => ['بداية العقد', ['تاريخ بداية العقد', 'contract start', 'contract_start_date']],
        'contract_end_date' => ['نهاية العقد', ['تاريخ نهاية العقد', 'contract end', 'contract_end_date']],
        'contract_duration' => ['مدة العقد', ['contract duration', 'contract_duration']],
        'gross_salary' => ['الراتب الإجمالي', ['الراتب الاجمالي', 'اجمالي الراتب', 'إجمالي الراتب', 'الإجمالي', 'gross salary', 'gross_salary', 'gross']],
        'net_salary' => ['الراتب الصافي', ['صافي الراتب', 'الصافي', 'net salary', 'net_salary', 'net']],
        'manager_code' => ['كود المدير المباشر', ['كود المدير', 'المدير المباشر', 'manager code', 'manager_code']],
        'shift_start' => ['بداية الدوام', ['بدايه الدوام', 'shift start', 'shift_start']],
        'shift_end' => ['نهاية الدوام', ['نهايه الدوام', 'shift end', 'shift_end']],
        'termination_type' => ['نوع انتهاء الخدمة', ['نوع إنهاء الخدمة', 'termination type', 'termination_type']],
        'termination_date' => ['تاريخ انتهاء الخدمة', ['تاريخ إنهاء الخدمة', 'termination date', 'termination_date']],
        'notes' => ['ملاحظات', ['ملاحظة', 'notes', 'note']],
    ];

    private const STATUS_WORDS = [
        'Active' => ['active', 'نشط', 'فعال', 'على رأس العمل'],
        'Leave' => ['leave', 'on leave', 'إجازة', 'اجازة', 'في إجازة'],
        'Suspended' => ['suspended', 'موقوف', 'إيقاف', 'موقوف عن العمل'],
        'Terminated' => ['terminated', 'منتهي الخدمة', 'منتهى الخدمة', 'انتهاء الخدمة', 'انتهت خدمته'],
        'Pending' => ['pending', 'قيد التعيين', 'معلق', 'تحت التعيين'],
    ];

    private const RESIGNED_WORDS = ['resigned', 'resignation', 'مستقيل', 'استقالة', 'استقاله'];
    private const DISMISSED_WORDS = ['dismissed', 'dismissal', 'fired', 'مفصول', 'فصل'];

    private const EMPLOYMENT_TYPE_WORDS = [
        'Full Time' => ['full time', 'fulltime', 'دوام كامل', 'كامل'],
        'Part Time' => ['part time', 'parttime', 'دوام جزئي', 'جزئي'],
        'Fixed Contract' => ['fixed contract', 'عقد محدد المدة', 'عقد محدد', 'عقد'],
        'Freelance' => ['freelance', 'مستقل', 'فريلانس'],
        'Internship' => ['internship', 'تدريب', 'متدرب'],
    ];

    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => ',',
    ];

    /** @var array<string, array<string, string>>|null */
    private ?array $wordMaps = null;

    /** @var array<string, string> */
    private array $statusIds = [];

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly PayrollCalculator $calculator,
    ) {
    }

    // =====================================================================
    // Analysis (read-only)
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $path, string $extension, int $companyId, string $duplicates = 'update'): array
    {
        $duplicates = $duplicates === 'skip' ? 'skip' : 'update';
        $result = [
            'file_errors' => [],
            'unknown_columns' => [],
            'rows' => [],
            'summary' => ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'warnings' => 0],
            'capacity' => null,
            'new_lookups' => ['departments' => [], 'jobs' => [], 'branches' => []],
            'duplicates' => $duplicates,
        ];

        try {
            $sheet = $this->reader->read($path, $extension);
        } catch (RuntimeException $exception) {
            $result['file_errors'][] = $exception->getMessage();

            return $result;
        }

        [$headerRow, $columnMap, $unknown] = $this->detectHeader($sheet);
        if ($headerRow === null) {
            $result['file_errors'][] = 'لم يتم العثور على صف العناوين. تأكد من وجود عمود «الاسم الكامل» في الصف الأول، أو حمّل النموذج الجاهز واستخدمه.';

            return $result;
        }
        $result['unknown_columns'] = $unknown;

        $dataRows = [];
        foreach ($sheet as $rowNumber => $cells) {
            if ($rowNumber <= $headerRow) {
                continue;
            }
            $record = [];
            foreach ($columnMap as $column => $key) {
                $record[$key] = isset($cells[$column]) ? trim((string) $cells[$column]) : '';
            }
            if (implode('', $record) === '') {
                continue;
            }
            $dataRows[$rowNumber] = $record;
        }

        if ($dataRows === []) {
            $result['file_errors'][] = 'لا توجد صفوف بيانات أسفل صف العناوين.';

            return $result;
        }
        if (count($dataRows) > self::MAX_ROWS) {
            $result['file_errors'][] = 'عدد الصفوف (' . count($dataRows) . ') أكبر من الحد المسموح في الملف الواحد (' . self::MAX_ROWS . '). قسّم الملف إلى أكثر من ملف.';

            return $result;
        }

        $context = $this->loadContext($companyId);
        $seen = ['nid' => [], 'code' => []];
        $rows = [];
        foreach ($dataRows as $line => $record) {
            $rows[] = $this->analyzeRow($record, (int) $line, $context, $duplicates, $seen);
        }

        $this->checkManagers($rows, $context);

        $summary = $result['summary'];
        $summary['total'] = count($rows);
        foreach ($rows as $row) {
            $summary[$row['action']]++;
            if ($row['warnings'] !== []) {
                $summary['warnings']++;
            }
        }

        $result['rows'] = $rows;
        $result['summary'] = $summary;
        $result['capacity'] = $this->capacity($companyId, $rows, $context);
        $result['new_lookups'] = $this->newLookups($rows, $context);

        return $result;
    }

    /**
     * @param array<int, array<int, string>> $sheet
     * @return array{0: ?int, 1: array<int, string>, 2: array<int, string>}
     */
    private function detectHeader(array $sheet): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $key => [$label, $extra]) {
            foreach (array_merge([$label], $extra) as $alias) {
                $aliases[$this->key($alias)] = $key;
            }
        }

        $scanned = 0;
        foreach ($sheet as $rowNumber => $cells) {
            if (++$scanned > 15) {
                break;
            }
            $map = [];
            $used = [];
            $unknown = [];
            foreach ($cells as $column => $value) {
                $normalized = $this->key((string) $value);
                if ($normalized === '') {
                    continue;
                }
                $key = $aliases[$normalized] ?? null;
                if ($key !== null && !isset($used[$key])) {
                    $map[$column] = $key;
                    $used[$key] = true;
                } else {
                    $unknown[] = $this->cleanText((string) $value);
                }
            }
            if (isset($used['full_name'])) {
                return [(int) $rowNumber, $map, array_slice($unknown, 0, 15)];
            }
        }

        return [null, [], []];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadContext(int $companyId): array
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'employee_code', 'national_id', 'status_id']);

        $byNid = [];
        $byCode = [];
        $byId = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $byId[$id] = ['status_id' => $employee->status_id !== null ? (int) $employee->status_id : null];
            if ($employee->national_id !== null && $employee->national_id !== '') {
                $byNid[(string) $employee->national_id] = $id;
            }
            if ($employee->employee_code !== null && $employee->employee_code !== '') {
                $byCode[mb_strtolower((string) $employee->employee_code)] = $id;
            }
        }

        $statuses = [];
        $statusNameById = [];
        foreach (EmployeeStatus::all(['id', 'name']) as $status) {
            $statuses[$this->key((string) $status->name)] = ['id' => (int) $status->id, 'name' => (string) $status->name];
            $statusNameById[(int) $status->id] = (string) $status->name;
        }

        $employmentTypes = [];
        $employmentTypeNames = [];
        $types = EmploymentType::withoutGlobalScopes()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->get(['id', 'name']);
        foreach ($types as $type) {
            $employmentTypes[$this->key((string) $type->name)] = ['id' => (int) $type->id, 'name' => (string) $type->name];
            $employmentTypeNames[(string) $type->name] = true;
        }

        return [
            'byNid' => $byNid,
            'byCode' => $byCode,
            'byId' => $byId,
            'statuses' => $statuses,
            'statusNameById' => $statusNameById,
            'employmentTypes' => $employmentTypes,
            'employmentTypeNames' => array_keys($employmentTypeNames),
            'departments' => $this->nameKeys(Department::class, 'name', $companyId),
            'jobs' => $this->nameKeys(Job::class, 'title', $companyId),
            'branches' => $this->nameKeys(Branch::class, 'name', $companyId),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function nameKeys(string $modelClass, string $column, int $companyId): array
    {
        $keys = [];
        $models = $modelClass::withoutGlobalScopes()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->get([$column]);
        foreach ($models as $model) {
            $keys[$this->key((string) $model->{$column})] = true;
        }

        return $keys;
    }

    /**
     * @param array<string, string> $raw
     * @param array<string, mixed> $ctx
     * @param array{nid: array<string, int>, code: array<string, int>} $seen
     * @return array<string, mixed>
     */
    private function analyzeRow(array $raw, int $line, array $ctx, string $duplicates, array &$seen): array
    {
        $errors = [];
        $warnings = [];
        $data = [];
        $get = static fn (string $key): string => trim((string) ($raw[$key] ?? ''));

        $attempt = function (string $label, callable $parser) use (&$errors) {
            try {
                return $parser();
            } catch (InvalidArgumentException $exception) {
                $errors[] = $label . ': ' . $exception->getMessage();

                return null;
            }
        };

        // ---- name -------------------------------------------------------
        $name = $this->cleanText($get('full_name'));
        if ($name === '') {
            $errors[] = 'الاسم الكامل مطلوب.';
        } elseif (mb_strlen($name) > 255) {
            $errors[] = 'الاسم الكامل أطول من 255 حرفًا.';
        } else {
            $data['full_name'] = $name;
        }

        // ---- national id ------------------------------------------------
        $nid = '';
        $rawNid = $get('national_id');
        if ($rawNid !== '') {
            $nid = (string) preg_replace('/\D+/', '', $this->numericText($rawNid));
            $derived = $this->nationalIdData($nid);
            if (is_string($derived)) {
                $errors[] = $derived;
                $nid = '';
            } else {
                $data['national_id'] = $nid;
                $data['birth_date'] = $derived['birth_date'];
                $data['gender'] = $derived['gender'];
            }
        }

        // ---- employee code ---------------------------------------------
        $code = $this->cleanText($this->numericText($get('employee_code')));
        if ($code !== '') {
            if (mb_strlen($code) > 50) {
                $errors[] = 'كود الموظف أطول من 50 حرفًا.';
                $code = '';
            } else {
                $data['employee_code'] = $code;
            }
        }

        // ---- match against existing employees --------------------------
        $byNid = $nid !== '' ? ($ctx['byNid'][$nid] ?? null) : null;
        $byCode = $code !== '' ? ($ctx['byCode'][mb_strtolower($code)] ?? null) : null;
        if ($byNid !== null && $byCode !== null && $byNid !== $byCode) {
            $errors[] = 'الكود والرقم القومي مسجّلان لموظفَين مختلفين في النظام.';
        }
        $existingId = $byNid ?? $byCode;
        $isNew = $existingId === null;

        if ($nid !== '' && isset($seen['nid'][$nid])) {
            $errors[] = 'الرقم القومي مكرر داخل الملف (يطابق الصف ' . $seen['nid'][$nid] . ').';
        }
        if ($code !== '' && isset($seen['code'][mb_strtolower($code)])) {
            $errors[] = 'كود الموظف مكرر داخل الملف (يطابق الصف ' . $seen['code'][mb_strtolower($code)] . ').';
        }

        // ---- contact ----------------------------------------------------
        $phone = $get('phone');
        if ($phone !== '') {
            $normalizedPhone = $this->normalizePhone($phone);
            if ($normalizedPhone === null) {
                $errors[] = 'رقم الهاتف غير صالح «' . $this->cleanText($phone) . '».';
            } else {
                $data['phone'] = $normalizedPhone;
            }
        }

        $email = mb_strtolower($this->cleanText($get('email')));
        if ($email !== '') {
            if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'البريد الإلكتروني غير صالح «' . $email . '».';
            } else {
                $data['email'] = $email;
            }
        }

        $insurance = $this->cleanText($this->numericText($get('insurance_number')));
        if ($insurance !== '') {
            if (mb_strlen($insurance) > 100) {
                $errors[] = 'الرقم التأميني أطول من 100 حرف.';
            } else {
                $data['insurance_number'] = $insurance;
            }
        }

        // ---- personal ---------------------------------------------------
        if ($get('gender') !== '') {
            $gender = $this->parseGender($get('gender'));
            if ($gender === null) {
                $warnings[] = 'قيمة النوع «' . $this->cleanText($get('gender')) . '» غير معروفة (استخدم ذكر أو أنثى) وتم تجاهلها.';
            } elseif (isset($data['gender']) && $data['gender'] !== $gender) {
                $warnings[] = 'النوع في الملف يخالف الرقم القومي؛ تم اعتماد النوع المستخرج من الرقم القومي.';
            } elseif (!isset($data['gender'])) {
                $data['gender'] = $gender;
            }
        }

        if ($get('birth_date') !== '') {
            $birth = $attempt('تاريخ الميلاد', fn () => $this->parseDate($get('birth_date')));
            if ($birth !== null) {
                if ($birth >= date('Y-m-d')) {
                    $errors[] = 'تاريخ الميلاد: يجب أن يكون في الماضي.';
                } elseif (isset($data['birth_date']) && $data['birth_date'] !== $birth) {
                    $warnings[] = 'تاريخ الميلاد في الملف يخالف الرقم القومي؛ تم اعتماد التاريخ المستخرج من الرقم القومي.';
                } elseif (!isset($data['birth_date'])) {
                    $data['birth_date'] = $birth;
                }
            }
        }

        foreach ([
            'marital_status' => ['الحالة الاجتماعية', 50],
            'qualification' => ['المؤهل', 150],
            'address' => ['العنوان', 500],
            'nationality' => ['الجنسية', 100],
            'contract_duration' => ['مدة العقد', 100],
        ] as $field => [$label, $max]) {
            $value = $this->cleanText($get($field));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > $max) {
                $errors[] = $label . ' أطول من ' . $max . ' حرفًا.';
            } else {
                $data[$field] = $value;
            }
        }

        $notes = trim($get('notes'));
        if ($notes !== '') {
            $data['notes'] = mb_substr($notes, 0, 5000);
        }

        // ---- organisation ----------------------------------------------
        foreach (['department' => ['القسم', 'departments'], 'job' => ['الوظيفة', 'jobs'], 'branch' => ['الفرع', 'branches']] as $field => [$label]) {
            $value = $this->cleanText($get($field));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 150) {
                $errors[] = $label . ' أطول من 150 حرفًا.';
            } else {
                $data[$field] = $value;
            }
        }

        if ($get('employment_type') !== '') {
            $type = $this->resolveEmploymentType($get('employment_type'), $ctx);
            if ($type === null) {
                $errors[] = 'نوع التوظيف «' . $this->cleanText($get('employment_type')) . '» غير معروف. القيم المتاحة: ' . implode('، ', $ctx['employmentTypeNames']) . ' (أو ما يقابلها بالعربية: دوام كامل، دوام جزئي، عقد محدد المدة، مستقل، تدريب).';
            } else {
                $data['employment_type_id'] = $type;
            }
        }

        if ($get('manager_code') !== '') {
            $managerCode = $this->cleanText($this->numericText($get('manager_code')));
            if (mb_strlen($managerCode) > 50) {
                $errors[] = 'كود المدير المباشر أطول من 50 حرفًا.';
            } else {
                $data['manager_code'] = $managerCode;
            }
        }

        // ---- status / termination --------------------------------------
        $statusName = null;
        $impliedType = null;
        if ($get('status') !== '') {
            $resolved = $this->resolveStatus($get('status'), $ctx);
            if ($resolved === null) {
                $errors[] = 'الحالة الوظيفية «' . $this->cleanText($get('status')) . '» غير معروفة. القيم المسموحة: نشط، إجازة، موقوف، منتهي الخدمة، قيد التعيين.';
            } else {
                [$statusName, $impliedType] = $resolved;
                $data['status_name'] = $statusName;
            }
        }

        $existingStatusName = null;
        if (!$isNew) {
            $existingStatusId = $ctx['byId'][$existingId]['status_id'] ?? null;
            $existingStatusName = $existingStatusId !== null ? ($ctx['statusNameById'][$existingStatusId] ?? null) : null;
        }
        $finalStatus = $statusName ?? ($isNew ? 'Active' : $existingStatusName);

        $explicitType = null;
        if ($get('termination_type') !== '') {
            $explicitType = $this->parseTerminationType($get('termination_type'));
            if ($explicitType === null) {
                $errors[] = 'نوع انتهاء الخدمة «' . $this->cleanText($get('termination_type')) . '» غير معروف (استخدم استقالة أو فصل).';
            }
        }
        $terminationDate = null;
        if ($get('termination_date') !== '') {
            $terminationDate = $attempt('تاريخ انتهاء الخدمة', fn () => $this->parseDate($get('termination_date')));
        }

        if ($statusName !== null) {
            $kind = $this->statusKind($statusName);
            if ($kind === 'terminated') {
                $type = $explicitType ?? $impliedType;
                if ($type === null) {
                    $errors[] = 'حدّد نوع انتهاء الخدمة (استقالة أو فصل) للموظف منتهي الخدمة.';
                } else {
                    $data['termination_type'] = $type;
                    $data['termination_date'] = $terminationDate ?? date('Y-m-d');
                }
            } elseif ($kind === 'suspended') {
                $data['termination_type'] = null;
                $data['termination_date'] = $terminationDate ?? date('Y-m-d');
            }
        }

        // ---- dates ------------------------------------------------------
        $hire = null;
        if ($get('hire_date') !== '') {
            $hire = $attempt('تاريخ التعيين', fn () => $this->parseDate($get('hire_date')));
        }
        $contractStart = null;
        if ($get('contract_start_date') !== '') {
            $contractStart = $attempt('بداية العقد', fn () => $this->parseDate($get('contract_start_date')));
        }
        $contractEnd = null;
        if ($get('contract_end_date') !== '') {
            $contractEnd = $attempt('نهاية العقد', fn () => $this->parseDate($get('contract_end_date')));
        }

        if ($isNew) {
            $hire ??= date('Y-m-d');
            $contractStart ??= $hire;
            $contractEnd ??= date('Y-m-d', strtotime($contractStart . ' +1 year'));
        } elseif ($hire !== null && $contractStart === null) {
            $contractStart = $hire;
        }
        if ($hire !== null) {
            $data['hire_date'] = $hire;
        }
        if ($contractStart !== null) {
            $data['contract_start_date'] = $contractStart;
        }
        if ($contractEnd !== null) {
            $data['contract_end_date'] = $contractEnd;
        }
        if ($contractStart !== null && $contractEnd !== null && $contractEnd < $contractStart) {
            $errors[] = 'نهاية العقد يجب أن تكون بعد بدايته أو تساويها.';
        }

        // ---- shift ------------------------------------------------------
        foreach (['shift_start' => 'بداية الدوام', 'shift_end' => 'نهاية الدوام'] as $field => $label) {
            if ($get($field) === '') {
                continue;
            }
            $time = $attempt($label, fn () => $this->parseTime($get($field)));
            if ($time !== null) {
                $data[$field] = $time;
            }
        }

        // ---- salary -----------------------------------------------------
        $gross = $get('gross_salary') !== '' ? $attempt('الراتب الإجمالي', fn () => $this->parseMoney($get('gross_salary'))) : null;
        $net = $get('net_salary') !== '' ? $attempt('الراتب الصافي', fn () => $this->parseMoney($get('net_salary'))) : null;
        foreach (['الراتب الإجمالي' => $gross, 'الراتب الصافي' => $net] as $label => $amount) {
            if ($amount !== null && ($amount <= 0 || $amount > 10000000)) {
                $errors[] = $label . ': يجب أن يكون أكبر من صفر وأقل من 10,000,000.';
            }
        }
        if ($gross !== null && $gross > 0 && $gross <= 10000000) {
            $data['salary_source'] = 'gross';
            $data['salary_value'] = $gross;
            if ($net !== null) {
                $warnings[] = 'تم تجاهل الراتب الصافي لأن الراتب الإجمالي مُدخل.';
            }
        } elseif ($net !== null && $net > 0 && $net <= 10000000) {
            $data['salary_source'] = 'net';
            $data['salary_value'] = $net;
        } elseif ($isNew && $gross === null && $net === null) {
            $warnings[] = 'لم يُحدَّد راتب؛ سيُسجَّل الموظف بدون هيكل راتب ويمكن إضافته لاحقًا.';
        }

        // ---- decide -----------------------------------------------------
        if ($errors !== []) {
            $action = 'error';
        } elseif (!$isNew) {
            $action = $duplicates === 'skip' ? 'skip' : 'update';
            if ($action === 'skip') {
                $warnings[] = 'الموظف موجود مسبقًا وتم تخطيه حسب الخيار المحدد.';
            }
        } else {
            $action = 'create';
        }

        if ($action !== 'error') {
            if ($nid !== '') {
                $seen['nid'][$nid] = $line;
            }
            if ($code !== '') {
                $seen['code'][mb_strtolower($code)] = $line;
            }
        }

        return [
            'line' => $line,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'national_id' => $nid !== '' ? $nid : null,
            'action' => $action,
            'existing_id' => $existingId,
            'final_status' => $finalStatus,
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $ctx
     */
    private function checkManagers(array &$rows, array $ctx): void
    {
        $fileCodes = [];
        foreach ($rows as $row) {
            if ($row['action'] !== 'error' && !empty($row['data']['employee_code'])) {
                $fileCodes[mb_strtolower((string) $row['data']['employee_code'])] = true;
            }
        }

        foreach ($rows as $index => $row) {
            if ($row['action'] === 'error' || empty($row['data']['manager_code'])) {
                continue;
            }
            $managerCode = mb_strtolower((string) $row['data']['manager_code']);
            if (!isset($ctx['byCode'][$managerCode]) && !isset($fileCodes[$managerCode])) {
                $rows[$index]['warnings'][] = 'كود المدير المباشر «' . $row['data']['manager_code'] . '» غير موجود؛ لن يُربط المدير.';
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>|null
     */
    private function capacity(int $companyId, array $rows, array $ctx): ?array
    {
        $subscription = CompanySubscription::with('plan')
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'trial'])
            ->latest('id')
            ->first();
        if (!$subscription || !$subscription->plan) {
            return null;
        }

        $limit = (int) $subscription->plan->employee_limit;
        $current = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereHas('status', fn ($query) => $query->whereNotIn('name', self::INACTIVE_STATUSES))
            ->count();

        $projected = $current;
        foreach ($rows as $row) {
            if (!in_array($row['action'], ['create', 'update'], true)) {
                continue;
            }
            if ($row['action'] === 'update') {
                $statusId = $ctx['byId'][$row['existing_id']]['status_id'] ?? null;
                $before = $statusId !== null ? ($ctx['statusNameById'][$statusId] ?? null) : null;
                if ($this->countsTowardLimit($before)) {
                    $projected--;
                }
            }
            if ($this->countsTowardLimit($row['final_status'])) {
                $projected++;
            }
        }

        return [
            'plan' => (string) $subscription->plan->name,
            'limit' => $limit,
            'current' => $current,
            'projected' => $projected,
            'available' => max(0, $limit - $current),
            'exceeded' => $projected > $limit && $projected > $current,
        ];
    }

    private function countsTowardLimit(?string $statusName): bool
    {
        return $statusName !== null && !in_array($statusName, self::INACTIVE_STATUSES, true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $ctx
     * @return array<string, array<int, string>>
     */
    private function newLookups(array $rows, array $ctx): array
    {
        $new = ['departments' => [], 'jobs' => [], 'branches' => []];
        foreach ($rows as $row) {
            if (!in_array($row['action'], ['create', 'update'], true)) {
                continue;
            }
            foreach (['department' => 'departments', 'job' => 'jobs', 'branch' => 'branches'] as $field => $bucket) {
                $name = $row['data'][$field] ?? null;
                if ($name === null) {
                    continue;
                }
                $key = $this->key($name);
                if (!isset($ctx[$bucket][$key]) && !isset($new[$bucket][$key])) {
                    $new[$bucket][$key] = $name;
                }
            }
        }

        return [
            'departments' => array_values($new['departments']),
            'jobs' => array_values($new['jobs']),
            'branches' => array_values($new['branches']),
        ];
    }

    // =====================================================================
    // Commit
    // =====================================================================

    /**
     * @param array<string, mixed> $analysis Output of analyze()
     * @return array<string, mixed>
     */
    public function commit(array $analysis, int $companyId): array
    {
        @set_time_limit(300);

        $rows = array_values(array_filter(
            $analysis['rows'],
            static fn (array $row): bool => in_array($row['action'], ['create', 'update'], true)
        ));

        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => (int) ($analysis['summary']['skip'] ?? 0), 'failed' => [], 'warnings' => []];
        if ($rows === []) {
            return $report;
        }

        DB::transaction(function () use ($rows, $companyId, &$report): void {
            $lookups = $this->prepareLookups($rows, $companyId);
            $codes = $this->prepareCodes($rows, $companyId);
            $managerLinks = [];

            foreach ($rows as $row) {
                try {
                    $outcome = DB::transaction(function () use ($row, $companyId, $lookups, &$codes) {
                        return $row['action'] === 'create'
                            ? $this->createEmployee($row, $companyId, $lookups, $codes)
                            : $this->updateEmployee($row, $companyId, $lookups);
                    });
                } catch (Throwable $exception) {
                    report($exception);
                    $report['failed'][] = [
                        'line' => $row['line'],
                        'name' => $row['name'],
                        'messages' => ['تعذّر حفظ هذا الصف: ' . $this->friendlyError($exception)],
                    ];
                    continue;
                }

                $report[$outcome['result']]++;
                if (!empty($row['data']['manager_code'])) {
                    $managerLinks[] = ['id' => $outcome['id'], 'code' => (string) $row['data']['manager_code'], 'line' => $row['line'], 'name' => $row['name']];
                }
            }

            $this->linkManagers($managerLinks, $companyId, $report);
        });

        ActivityLog::record(
            'employees_import',
            "استيراد موظفين من ملف: {$report['created']} جديد، {$report['updated']} محدَّث.",
            null,
            null,
            ['created' => $report['created'], 'updated' => $report['updated'], 'unchanged' => $report['unchanged'], 'skipped' => $report['skipped'], 'failed' => count($report['failed'])],
            'employees'
        );

        return $report;
    }

    /**
     * Create missing departments / jobs / branches / statuses for the valid rows
     * (outside the per-row savepoints so a failing row can never orphan them).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, int>>
     */
    private function prepareLookups(array $rows, int $companyId): array
    {
        $needed = ['departments' => [], 'jobs' => [], 'branches' => []];
        $statusNames = ['Active' => true];
        foreach ($rows as $row) {
            foreach (['department' => 'departments', 'job' => 'jobs', 'branch' => 'branches'] as $field => $bucket) {
                $name = $row['data'][$field] ?? null;
                if ($name !== null) {
                    $needed[$bucket][$this->key($name)] = $name;
                }
            }
            if (!empty($row['data']['status_name'])) {
                $statusNames[(string) $row['data']['status_name']] = true;
            }
        }

        foreach (array_keys($statusNames) as $statusName) {
            $this->statusIds[$statusName] = (string) EmployeeStatus::firstOrCreate(['name' => $statusName])->id;
        }

        return [
            'departments' => $this->resolveLookupIds(Department::class, 'name', $companyId, $needed['departments']),
            'jobs' => $this->resolveLookupIds(Job::class, 'title', $companyId, $needed['jobs']),
            'branches' => $this->resolveLookupIds(Branch::class, 'name', $companyId, $needed['branches']),
        ];
    }

    /**
     * @param array<string, string> $names normalised key => display name
     * @return array<string, int>
     */
    private function resolveLookupIds(string $modelClass, string $column, int $companyId, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $existing = $modelClass::withoutGlobalScopes()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->get(['id', $column, 'company_id'])
            ->sortBy(fn ($model) => $model->company_id === null ? 1 : 0);

        $map = [];
        foreach ($existing as $model) {
            $key = $this->key((string) $model->{$column});
            if (!isset($map[$key])) {
                $map[$key] = (int) $model->id;
            }
        }

        foreach ($names as $key => $display) {
            if (!isset($map[$key])) {
                $created = $modelClass::create([$column => $display, 'company_id' => $companyId, 'is_active' => true]);
                $map[$key] = (int) $created->id;
            }
        }

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{used: array<string, bool>, max: int}
     */
    private function prepareCodes(array $rows, int $companyId): array
    {
        $used = [];
        $max = 100;
        $existing = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->pluck('employee_code');
        foreach ($existing as $code) {
            $code = (string) $code;
            $used[mb_strtolower($code)] = true;
            if (ctype_digit($code)) {
                $max = max($max, (int) $code);
            }
        }
        foreach ($rows as $row) {
            if ($row['action'] === 'create' && !empty($row['data']['employee_code'])) {
                $used[mb_strtolower((string) $row['data']['employee_code'])] = true;
            }
        }

        return ['used' => $used, 'max' => $max];
    }

    /**
     * @param array{used: array<string, bool>, max: int} $codes
     */
    private function nextCode(array &$codes): string
    {
        do {
            $codes['max']++;
            $candidate = (string) $codes['max'];
        } while (isset($codes['used'][$candidate]));
        $codes['used'][$candidate] = true;

        return $candidate;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, int>> $lookups
     * @param array{used: array<string, bool>, max: int} $codes
     * @return array{id: int, result: string}
     */
    private function createEmployee(array $row, int $companyId, array $lookups, array &$codes): array
    {
        $d = $row['data'];
        [$first, $middle, $last] = $this->splitName($d['full_name']);
        $gross = $this->resolveGross($d);
        $statusName = $d['status_name'] ?? 'Active';

        $payload = [
            'company_id' => $companyId,
            'employee_code' => $d['employee_code'] ?? $this->nextCode($codes),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'full_name' => $d['full_name'],
            'national_id' => $d['national_id'] ?? null,
            'insurance_number' => $d['insurance_number'] ?? null,
            'phone' => $d['phone'] ?? null,
            'email' => $d['email'] ?? null,
            'gender' => $d['gender'] ?? null,
            'birth_date' => $d['birth_date'] ?? null,
            'marital_status' => $d['marital_status'] ?? null,
            'qualification' => $d['qualification'] ?? null,
            'address' => $d['address'] ?? null,
            'department_id' => isset($d['department']) ? ($lookups['departments'][$this->key($d['department'])] ?? null) : null,
            'job_id' => isset($d['job']) ? ($lookups['jobs'][$this->key($d['job'])] ?? null) : null,
            'branch_id' => isset($d['branch']) ? ($lookups['branches'][$this->key($d['branch'])] ?? null) : null,
            'employment_type_id' => $d['employment_type_id'] ?? null,
            'status_id' => (int) ($this->statusIds[$statusName] ?? EmployeeStatus::firstOrCreate(['name' => $statusName])->id),
            'hire_date' => $d['hire_date'] ?? date('Y-m-d'),
            'contract_start_date' => $d['contract_start_date'] ?? ($d['hire_date'] ?? date('Y-m-d')),
            'contract_end_date' => $d['contract_end_date'] ?? null,
            'contract_duration' => $d['contract_duration'] ?? null,
            'termination_date' => $d['termination_date'] ?? null,
            'termination_type' => $d['termination_type'] ?? null,
            'shift_start' => $d['shift_start'] ?? null,
            'shift_end' => $d['shift_end'] ?? null,
            'notes' => $d['notes'] ?? null,
            'gross_salary' => $gross,
        ];

        $employee = Employee::create($payload);
        $employee->profileDetail()->create([
            'nationality' => $d['nationality'] ?? 'مصري',
            'currency' => 'EGP',
        ]);
        if ($gross !== null && $gross > 0) {
            $this->createSalaryStructure($employee);
        }

        ActivityLog::record(
            'employee_import_create',
            "تم استيراد موظف جديد من ملف Excel: {$employee->full_name}",
            $employee,
            null,
            ['employee_code' => $employee->employee_code, 'line' => $row['line']],
            'employees'
        );

        return ['id' => (int) $employee->id, 'result' => 'created'];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, int>> $lookups
     * @return array{id: int, result: string}
     */
    private function updateEmployee(array $row, int $companyId, array $lookups): array
    {
        $d = $row['data'];
        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->find($row['existing_id']);
        if (!$employee) {
            throw new RuntimeException('الموظف لم يعد موجودًا في النظام.');
        }

        $changes = [];
        foreach (['full_name', 'national_id', 'insurance_number', 'phone', 'email', 'gender', 'birth_date', 'marital_status', 'qualification', 'address', 'contract_duration', 'shift_start', 'shift_end', 'notes', 'hire_date', 'contract_start_date', 'contract_end_date', 'employment_type_id', 'termination_type', 'termination_date'] as $field) {
            if (array_key_exists($field, $d)) {
                $changes[$field] = $d[$field];
            }
        }
        if (isset($d['full_name'])) {
            [$changes['first_name'], $changes['middle_name'], $changes['last_name']] = $this->splitName($d['full_name']);
        }
        if (isset($d['department'])) {
            $changes['department_id'] = $lookups['departments'][$this->key($d['department'])] ?? null;
        }
        if (isset($d['job'])) {
            $changes['job_id'] = $lookups['jobs'][$this->key($d['job'])] ?? null;
        }
        if (isset($d['branch'])) {
            $changes['branch_id'] = $lookups['branches'][$this->key($d['branch'])] ?? null;
        }
        if (isset($d['status_name'])) {
            $changes['status_id'] = (int) ($this->statusIds[$d['status_name']] ?? EmployeeStatus::firstOrCreate(['name' => $d['status_name']])->id);
        }

        $salaryChanged = false;
        $gross = $this->resolveGross($d);
        if ($gross !== null && $gross > 0 && abs((float) $employee->gross_salary - $gross) > 0.004) {
            $changes['gross_salary'] = $gross;
            $salaryChanged = true;
        }

        $tracked = [];
        foreach (['department_id', 'job_id', 'branch_id', 'gross_salary'] as $field) {
            if (array_key_exists($field, $changes) && (string) $employee->{$field} !== (string) $changes[$field]) {
                $tracked[$field] = ['old' => $employee->{$field}, 'new' => $changes[$field]];
            }
        }
        $oldStatusId = (int) $employee->status_id;
        $statusChanged = array_key_exists('status_id', $changes) && $oldStatusId !== (int) $changes['status_id'];

        $employee->fill($changes);
        $dirty = $employee->isDirty();
        $before = $dirty ? array_intersect_key($employee->getOriginal(), $employee->getDirty()) : [];
        if ($dirty) {
            $employee->save();
        }

        $profileTouched = false;
        $profile = $employee->profileDetail;
        if (!$profile) {
            $employee->profileDetail()->create(['nationality' => $d['nationality'] ?? 'مصري', 'currency' => 'EGP']);
            $profileTouched = true;
        } elseif (isset($d['nationality']) && $profile->nationality !== $d['nationality']) {
            $profile->update(['nationality' => $d['nationality']]);
            $profileTouched = true;
        }

        if ($salaryChanged) {
            $this->syncSalaryStructure($employee->fresh());
        }

        if ($statusChanged || $tracked !== []) {
            $type = MovementType::firstOrCreate(
                ['name' => $statusChanged ? 'Status Change' : 'Profile Update'],
                ['company_id' => $companyId]
            );
            MovementRecord::create([
                'employee_id' => $employee->id,
                'movement_type_id' => $type->id,
                'movement_date' => today(),
                'reason' => 'تحديث بيانات الموظف عبر الاستيراد من ملف Excel',
                'old_values' => ['status_id' => $oldStatusId, 'changes' => $tracked],
                'new_values' => ['status_id' => $employee->status_id, 'changes' => $tracked],
                'created_by' => auth()->id(),
            ]);
        }

        if (!$dirty && !$profileTouched && !$salaryChanged) {
            return ['id' => (int) $employee->id, 'result' => 'unchanged'];
        }

        ActivityLog::record(
            'employee_import_update',
            "تم تحديث بيانات الموظف من ملف Excel: {$employee->full_name}",
            $employee,
            $before,
            array_intersect_key($employee->getAttributes(), $before),
            'employees'
        );

        return ['id' => (int) $employee->id, 'result' => 'updated'];
    }

    /**
     * @param array<int, array{id: int, code: string, line: int, name: string}> $links
     * @param array<string, mixed> $report
     */
    private function linkManagers(array $links, int $companyId, array &$report): void
    {
        if ($links === []) {
            return;
        }

        $codeToId = [];
        $employees = Employee::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'employee_code']);
        foreach ($employees as $employee) {
            $codeToId[mb_strtolower((string) $employee->employee_code)] = (int) $employee->id;
        }

        foreach ($links as $link) {
            $managerId = $codeToId[mb_strtolower($link['code'])] ?? null;
            if ($managerId === null) {
                $report['warnings'][] = ['line' => $link['line'], 'name' => $link['name'], 'messages' => ['كود المدير المباشر «' . $link['code'] . '» غير موجود؛ لم يُربط المدير.']];
                continue;
            }
            if ($managerId === $link['id']) {
                $report['warnings'][] = ['line' => $link['line'], 'name' => $link['name'], 'messages' => ['لا يمكن أن يكون الموظف مديرًا لنفسه؛ لم يُربط المدير.']];
                continue;
            }
            Employee::withoutGlobalScopes()->whereKey($link['id'])->update(['manager_employee_id' => $managerId]);
        }
    }

    // ---- salary (mirrors EmployeeController so imported salaries behave identically)

    /**
     * @param array<string, mixed> $data
     */
    private function resolveGross(array $data): ?float
    {
        if (!isset($data['salary_source'], $data['salary_value'])) {
            return null;
        }
        $value = (float) $data['salary_value'];
        if ($value <= 0) {
            return null;
        }
        if ($data['salary_source'] === 'net') {
            return round((float) $this->calculator->calculate('net', $value)['gross'], 2);
        }

        return round($value, 2);
    }

    private function createSalaryStructure(Employee $employee): void
    {
        $gross = (float) $employee->gross_salary;
        if ($gross <= 0) {
            return;
        }

        $effectiveFrom = $employee->hire_date?->format('Y-m-d') ?? today()->format('Y-m-d');
        $structure = $this->calculator->structure([
            'salary_type' => 'gross',
            'salary_input' => $gross,
            'insurance_wage' => null,
            'no_insurance' => false,
            'fixed_deductions' => 0,
            'excluded_allowance_pct' => null,
            'personal_exemption' => null,
            'tax_treatment' => 'egyptian',
            'housing_allowance' => 0,
            'transportation_allowance' => 0,
            'meal_allowance' => 0,
            'clothing_allowance' => 0,
        ]);

        Salary::updateOrCreate(
            ['employee_id' => $employee->id, 'effective_from' => $effectiveFrom],
            array_merge([
                'salary_type' => 'gross',
                'salary_input' => $gross,
                'status' => 'Active',
                'fixed_deductions' => 0,
                'deductions' => 0,
                'notes' => 'تم إنشاؤه تلقائيًا من الراتب الإجمالي عند استيراد الموظفين من ملف Excel.',
            ], $this->calculator->persistFields($structure))
        );
        Salary::syncInsuredSalary((int) $employee->id);
    }

    private function syncSalaryStructure(Employee $employee): void
    {
        $salary = Salary::where('employee_id', $employee->id)
            ->where('status', 'Active')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        if (!$salary) {
            $this->createSalaryStructure($employee);

            return;
        }
        if ((float) $employee->gross_salary <= 0) {
            return;
        }

        $structure = $this->calculator->structure([
            'salary_type' => 'gross',
            'salary_input' => (float) $employee->gross_salary,
            'insurance_wage' => $salary->insurance_wage,
            'no_insurance' => $salary->no_insurance,
            'fixed_deductions' => $salary->fixed_deductions,
            'excluded_allowance_pct' => $salary->excluded_allowance_pct,
            'personal_exemption' => $salary->personal_exemption,
            'tax_treatment' => $salary->tax_treatment ?: 'egyptian',
            'housing_allowance' => 0,
            'transportation_allowance' => 0,
            'meal_allowance' => 0,
            'clothing_allowance' => 0,
        ]);
        $salary->update(array_merge([
            'salary_type' => 'gross',
            'salary_input' => (float) $employee->gross_salary,
        ], $this->calculator->persistFields($structure)));
        Salary::syncInsuredSalary((int) $employee->id);
    }

    private function friendlyError(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $code = (int) ($exception->errorInfo[1] ?? 0);
            if ($code === 1062) {
                return 'قيمة مكررة (كود الموظف أو الرقم القومي مسجّل مسبقًا).';
            }
            if ($code === 1406 || $code === 1265) {
                return 'إحدى القيم أطول من الحد المسموح به في قاعدة البيانات.';
            }

            return 'خطأ في قاعدة البيانات.';
        }
        if ($exception instanceof RuntimeException) {
            return $exception->getMessage();
        }

        return 'خطأ غير متوقع.';
    }

    // =====================================================================
    // Parsing helpers
    // =====================================================================

    /**
     * Normalised comparison key: lower case, no diacritics/tatweel, unified
     * Arabic letter forms, no spaces or punctuation.
     */
    public function key(string $value): string
    {
        $value = mb_strtolower(trim($this->latinDigits($value)), 'UTF-8');
        $value = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value);
        $value = strtr($value, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
    }

    private function latinDigits(string $value): string
    {
        return strtr($value, self::DIGIT_MAP);
    }

    private function cleanText(?string $value): string
    {
        $value = (string) preg_replace('/[\x{00A0}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', ' ', (string) $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Plain-text rendering of identifiers that Excel may have stored as numbers
     * (Arabic digits, "2.98E+13", "101.0").
     */
    private function numericText(string $value): string
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d(\.\d+)?[eE]\+\d+$/', $value)) {
            return sprintf('%.0f', (float) $value);
        }
        if (preg_match('/^\d+\.0+$/', $value)) {
            return explode('.', $value)[0];
        }

        return $value;
    }

    /**
     * @return array{birth_date: string, gender: string}|string Error message when invalid
     */
    private function nationalIdData(string $nid): array|string
    {
        if (strlen($nid) !== 14) {
            return 'الرقم القومي يجب أن يتكون من 14 رقمًا.';
        }
        if (!in_array($nid[0], ['2', '3'], true)) {
            return 'الرقم القومي غير صحيح (يجب أن يبدأ بالرقم 2 أو 3).';
        }
        $year = (int) substr($nid, 1, 2) + ($nid[0] === '3' ? 2000 : 1900);
        $month = (int) substr($nid, 3, 2);
        $day = (int) substr($nid, 5, 2);
        if (!checkdate($month, $day, $year)) {
            return 'تاريخ الميلاد داخل الرقم القومي غير صحيح.';
        }

        return [
            'birth_date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            'gender' => ((int) $nid[12]) % 2 === 1 ? 'ذكر' : 'أنثى',
        ];
    }

    private function normalizePhone(string $value): ?string
    {
        $value = $this->numericText($value);
        $value = (string) preg_replace('/[\s\-().]+/u', '', $value);
        if (!preg_match('/^\+?\d{6,20}$/', $value)) {
            return null;
        }
        // Excel drops the leading zero of mobile numbers stored as numbers.
        if (preg_match('/^1\d{9}$/', $value)) {
            $value = '0' . $value;
        }

        return $value;
    }

    private function parseGender(string $value): ?string
    {
        $key = $this->key($value);
        if (in_array($key, ['ذكر', 'ذكور', 'male', 'm', 'رجل'], true)) {
            return 'ذكر';
        }
        if (in_array($key, ['انثي', 'اناث', 'female', 'f', 'سيده', 'امراه'], true)) {
            return 'أنثى';
        }

        return null;
    }

    private function parseTerminationType(string $value): ?string
    {
        $key = $this->key($value);
        if (in_array($key, $this->wordMap('resigned'), true)) {
            return 'resignation';
        }
        if (in_array($key, $this->wordMap('dismissed'), true)) {
            return 'dismissal';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array{0: string, 1: ?string}|null [status name, implied termination type]
     */
    private function resolveStatus(string $input, array $ctx): ?array
    {
        $key = $this->key($input);
        if ($key === '') {
            return null;
        }
        if (in_array($key, $this->wordMap('resigned'), true)) {
            return ['Terminated', 'resignation'];
        }
        if (in_array($key, $this->wordMap('dismissed'), true)) {
            return ['Terminated', 'dismissal'];
        }
        foreach (self::STATUS_WORDS as $canonical => $words) {
            foreach ($words as $word) {
                if ($this->key($word) === $key) {
                    $existing = $ctx['statuses'][$this->key($canonical)]['name'] ?? $canonical;

                    return [$existing, null];
                }
            }
        }
        if (isset($ctx['statuses'][$key])) {
            return [$ctx['statuses'][$key]['name'], null];
        }

        return null;
    }

    private function statusKind(string $statusName): string
    {
        $key = $this->key($statusName);
        foreach (['terminated' => ['Terminated', 'منتهي الخدمة', 'منتهى الخدمة', 'استقاله', 'استقالة', 'Resigned', 'Resignation', 'مستقيل'], 'suspended' => ['Suspended', 'موقوف']] as $kind => $names) {
            foreach ($names as $name) {
                if ($this->key($name) === $key) {
                    return $kind;
                }
            }
        }

        return 'other';
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function resolveEmploymentType(string $input, array $ctx): ?int
    {
        $key = $this->key($input);
        if (isset($ctx['employmentTypes'][$key])) {
            return $ctx['employmentTypes'][$key]['id'];
        }
        foreach (self::EMPLOYMENT_TYPE_WORDS as $canonical => $words) {
            foreach ($words as $word) {
                if ($this->key($word) === $key) {
                    return $ctx['employmentTypes'][$this->key($canonical)]['id'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function wordMap(string $name): array
    {
        if ($this->wordMaps === null) {
            $this->wordMaps = [
                'resigned' => array_map(fn (string $word): string => $this->key($word), self::RESIGNED_WORDS),
                'dismissed' => array_map(fn (string $word): string => $this->key($word), self::DISMISSED_WORDS),
            ];
        }

        return $this->wordMaps[$name];
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [$fullName];
        $first = $parts[0];
        $middle = null;
        $last = null;
        if (count($parts) === 2) {
            $last = $parts[1];
        } elseif (count($parts) > 2) {
            $middle = $parts[1];
            $last = implode(' ', array_slice($parts, 2));
        }

        return [
            mb_substr($first, 0, 100),
            $middle !== null ? mb_substr($middle, 0, 100) : null,
            $last !== null ? mb_substr($last, 0, 100) : null,
        ];
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m)) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})(?:\s.*)?$/', $value, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $value) && (float) $value > 20000 && (float) $value < 80000) {
            $date = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))->modify('+' . (int) floor((float) $value) . ' days');

            return $date->format('Y-m-d');
        } else {
            throw new InvalidArgumentException('تاريخ غير صالح «' . $value . '». استخدم الصيغة 2026-01-31 أو 31/01/2026.');
        }

        if ($year < 1900 || $year > 2100 || !checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('تاريخ غير صالح «' . $value . '».');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseTime(string $value): ?string
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(?:\d{4}-\d{2}-\d{2}[ T]+)?(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm|ص|م)?$/iu', $value, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];
            $second = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 0;
            $meridiem = isset($m[4]) ? mb_strtolower($m[4]) : '';
            if (($meridiem === 'pm' || $meridiem === 'م') && $hour < 12) {
                $hour += 12;
            } elseif (($meridiem === 'am' || $meridiem === 'ص') && $hour === 12) {
                $hour = 0;
            }
        } elseif (preg_match('/^0?\.\d+$/', $value)) {
            $seconds = (int) round(((float) $value) * 86400);
            $hour = intdiv($seconds, 3600);
            $minute = intdiv($seconds % 3600, 60);
            $second = $seconds % 60;
        } else {
            throw new InvalidArgumentException('وقت غير صالح «' . $value . '». استخدم الصيغة 09:00.');
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            throw new InvalidArgumentException('وقت غير صالح «' . $value . '».');
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    private function parseMoney(string $value): ?float
    {
        $value = trim($this->latinDigits($value));
        if ($value === '') {
            return null;
        }

        $clean = trim((string) preg_replace('/[^\d.,\-]/u', '', $value), '.,');
        if ($clean === '' || $clean === '-') {
            throw new InvalidArgumentException('قيمة غير رقمية «' . $value . '».');
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = preg_match('/^\d{1,3}(,\d{3})+$/', $clean) ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
        }

        if (!is_numeric($clean)) {
            throw new InvalidArgumentException('قيمة غير رقمية «' . $value . '».');
        }

        return round((float) $clean, 2);
    }
}
