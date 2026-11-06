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
use Carbon\Carbon;

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
    use ParsesImportValues;

    public const MAX_ROWS = 3000;

    /** Report/calculation columns are not employee master-data inputs. */
    private const CALCULATED_HEADERS = [
        'سنوات الخدمة', 'مدة الخدمة', 'الرصيد المستحق', 'المستخدم', 'المتبقي', 'تنبيه',
        'رصيد الإجازة السنوية المتبقي', 'إجمالي الإجازات', 'أيام الحضور', 'أيام الغياب',
    ];

    /** Employees in these statuses do not consume the plan's employee limit (same rule as EmployeeController). */
    private const INACTIVE_STATUSES = ['منتهي الخدمة', 'موقوف', 'قيد التعيين', 'Terminated', 'Suspended', 'Pending'];

    /**
     * key => [Arabic label (also the template header), accepted header aliases]
     */
    public const COLUMNS = [
        'employee_code' => ['كود الموظف', ['الكود', 'كود', 'رقم الموظف', 'employee code', 'employee_code', 'emp code', 'code']],
        'full_name' => ['الاسم الكامل', ['الاسم', 'اسم الموظف', 'اسم', 'full name', 'full_name', 'name', 'employee name']],
        'national_id' => ['الرقم القومي', ['رقم قومي', 'الرقم القومى', 'رقم البطاقة', 'national id', 'national_id', 'nid']],
        'insurance_number' => ['الرقم التأميني', [
            // Arabic variants used by personnel/insurance spreadsheets.
            'رقم التأمين', 'الرقم التامينى', 'رقم التأمين الاجتماعي', 'رقم التأمينات',
            'الرقم التأميني الاجتماعي', 'رقم التأمينات الاجتماعية', 'الرقم التأميني للتأمينات',
            // English variants (including the labels produced by common HR exports).
            'insurance number', 'insurance_number', 'insurance no', 'insurance no.',
            'insurance id', 'social insurance number', 'social insurance id',
            'social security number', 'social security id', 'insurance registration number',
            'ins no', 'ins number', 'ins id',
        ]],
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
        'employment_type' => ['نوع التوظيف', ['نوع التعاقد', 'نوع العمل', 'نوع الموظف', 'employment type', 'employment_type']],
        'status' => ['الحالة الوظيفية', ['حالة الموظف', 'حالة العمل', 'employee status', 'status']],
        'hire_date' => ['بداية العقد (عمود قديم)', ['تاريخ التعيين', 'تاريخ المباشرة', 'تاريخ الالتحاق', 'hire date', 'hire_date', 'join date']],
        'contract_start_date' => ['بداية العقد', ['تاريخ بداية العقد', 'contract start', 'contract_start_date']],
        'contract_end_date' => ['نهاية العقد', ['تاريخ نهاية العقد', 'contract end', 'contract_end_date']],
        'contract_duration' => ['مدة العقد', ['contract duration', 'contract_duration']],
        'gross_salary' => ['الراتب الإجمالي', ['الراتب الاجمالي', 'اجمالي الراتب', 'إجمالي الراتب', 'المرتب الإجمالي', 'المرتب الاجمالي', 'إجمالي المرتب', 'الإجمالي', 'gross salary', 'gross_salary', 'gross']],
        'net_salary' => ['الراتب الصافي', ['صافي الراتب', 'الصافي', 'net salary', 'net_salary', 'net']],
        'manager_code' => ['كود المدير المباشر', ['كود المدير', 'manager code', 'manager_code']],
        'manager_name' => ['اسم المدير المباشر', ['المدير المباشر', 'اسم المدير', 'manager name', 'manager_name']],
        'shift_start' => ['بداية الدوام', ['بدايه الدوام', 'بداية دوام خاصة', 'بداية الوردية', 'وقت الحضور الرسمي', 'shift start', 'shift_start']],
        'shift_end' => ['نهاية الدوام', ['نهايه الدوام', 'نهاية دوام خاصة', 'نهاية الوردية', 'وقت الانصراف الرسمي', 'shift end', 'shift_end']],
        'termination_type' => ['نوع انتهاء الخدمة', ['نوع إنهاء الخدمة', 'termination type', 'termination_type']],
        'termination_date' => ['تاريخ انتهاء الخدمة', ['تاريخ إنهاء الخدمة', 'تاريخ ترك العمل', 'تاريخ الخروج', 'آخر يوم عمل', 'تاريخ الاستقالة', 'termination date', 'termination_date']],
        'manual_annual_balance' => ['رصيد الإجازة السنوية اليدوي', ['رصيد سنوي يدوي (اختياري)', 'رصيد إجازة سنوية', 'رصيد الإجازات', 'manual annual balance', 'manual_annual_balance']],
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
     * @param array{sheet?: string, mappings?: mixed} $options
     *        sheet: "auto" | "all" | worksheet index; mappings: [normalised header key => field key]
     * @return array<string, mixed>
     */
    public function analyze(string $path, string $extension, int $companyId, string $duplicates = 'update', array $options = []): array
    {
        $duplicates = $duplicates === 'skip' ? 'skip' : 'update';
        $manual = $this->sanitizeMappings($options['mappings'] ?? []);
        $choice = (string) ($options['sheet'] ?? 'auto');
        $confirmedKinds = $this->sanitizeSheetKinds($options['sheet_kinds'] ?? []);
        $domainMappings = $this->sanitizeDomainMappings($options['domain_mappings'] ?? []);

        $result = [
            'file_errors' => [],
            'unknown_columns' => [],
            'columns' => [],
            'field_usage' => [],
            'needs_mapping' => false,
            'sheets' => [],
            'selected_sheet' => null,
            'merge_available' => false,
            'rows' => [],
            'summary' => ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'warnings' => 0, 'defaulted_dates' => 0],
            'capacity' => null,
            'new_lookups' => ['departments' => [], 'jobs' => [], 'branches' => []],
            'duplicates' => $duplicates,
        ];

        try {
            $sheets = $this->reader->readAll($path, $extension);
        } catch (RuntimeException $exception) {
            $result['file_errors'][] = $exception->getMessage();

            return $result;
        }

        $aliases = $this->aliasMap();
        $infos = [];
        foreach ($sheets as $index => $sheet) {
            [$headerRow, $map, $entries, $used] = $this->detectHeader($sheet['rows'], $aliases, $manual);
            $dataRows = 0;
            if ($headerRow !== null) {
                foreach (array_keys($sheet['rows']) as $rowNumber) {
                    if ($rowNumber > $headerRow) {
                        $dataRows++;
                    }
                }
            }
            $infos[$index] = ['header_row' => $headerRow, 'map' => $map, 'entries' => $entries, 'used' => $used, 'rows' => $dataRows, 'matched' => $headerRow !== null];
        }

        $multipleSheets = count($sheets) > 1;
        foreach ($infos as $index => $info) {
            $detected = $this->detectWorkbookSheetKind($sheets[$index]['rows'], $info['used'], (string) $sheets[$index]['name']);
            $kind = $confirmedKinds[(string) $index] ?? $confirmedKinds[(string) $sheets[$index]['name']] ?? $detected;
            // The user's confirmed type is authoritative: a sheet confirmed as
            // employee data is eligible even when the auto-markers missed it,
            // and every non-employee type is out of the employee import.
            $eligible = $info['matched'] && ($kind === 'employees')
                && (!$multipleSheets || $this->isEmployeeDataSheet($info['used']) || ($confirmedKinds[(string) $index] ?? $confirmedKinds[(string) $sheets[$index]['name']] ?? null) === 'employees');
            $infos[$index]['eligible'] = $eligible;
            $entry = [
                'index' => $index,
                'name' => $sheets[$index]['name'],
                'rows' => $info['matched'] ? $info['rows'] : count($sheets[$index]['rows']),
                'matched' => $info['matched'],
                'eligible' => $eligible,
                'kind' => $kind,
                'detected_kind' => $detected,
                'sample' => $this->sheetSample($sheets[$index]['rows'], $info['header_row']),
            ];
            // The column mapping the domain parser will use: auto-detected, with
            // the user's reviewed choices on top. Shown and editable in the review.
            if (in_array($kind, ['attendance', 'leaves', 'penalties', 'requests', 'advances', 'insurance'], true)) {
                $config = $this->detectDomainConfig($kind, $sheets[$index]['rows']);
                if ($config === null) {
                    // Header-row detection failed: fall back to the first
                    // non-empty row so the user can still map every column
                    // manually instead of being locked out of the sheet.
                    foreach ($sheets[$index]['rows'] as $number => $row) {
                        $cells = array_map(static fn ($v) => (string) $v, $row);
                        if (implode('', $cells) === '') {
                            continue;
                        }
                        $config = ['header_row' => (int) $number, 'headers' => $cells, 'map' => []];
                        break;
                    }
                }
                if ($config !== null) {
                    $config['map'] = array_merge($config['map'], $domainMappings[(string) $sheets[$index]['name']] ?? []);
                    $entry['domain_config'] = $config;
                }
            }
            $result['sheets'][] = $entry;
        }

        $candidates = array_values(array_keys(array_filter($infos, static fn (array $info): bool => !empty($info['eligible']))));
        $result['merge_available'] = count($candidates) > 1;

        // No sheet has a recognisable "full name" header: let the user map columns manually.
        if ($candidates === []) {
            $columns = $this->unmatchedColumns($sheets, $infos, $aliases, $manual, false);
            if ($columns === []) {
                $columns = $this->unmatchedColumns($sheets, $infos, $aliases, $manual, true);
            }
            if ($columns === []) {
                $hasRows = false;
                foreach ($sheets as $sheet) {
                    $hasRows = $hasRows || $sheet['rows'] !== [];
                }
                $result['file_errors'][] = $hasRows
                    ? 'لم يتم العثور على عمود «الاسم الكامل» في الملف. أضف عمودًا بهذا الاسم أو حمّل النموذج الجاهز.'
                    : 'الملف لا يحتوي على أي بيانات.';

                return $result;
            }
            $result['needs_mapping'] = true;
            $result['columns'] = array_values($columns);
            $result['unknown_columns'] = array_values(array_map(static fn (array $c): string => $c['header'], $columns));

            return $result;
        }

        if ($choice === 'all' && count($candidates) > 1) {
            $selected = $candidates;
            $result['selected_sheet'] = 'all';
        } elseif (ctype_digit($choice) && in_array((int) $choice, $candidates, true)) {
            $selected = [(int) $choice];
            $result['selected_sheet'] = (int) $choice;
        } else {
            $selected = [$candidates[0]];
            $result['selected_sheet'] = $candidates[0];
        }

        $columns = [];
        $usage = [];
        foreach ($selected as $index) {
            foreach ($this->columnList($infos[$index]['entries'], $aliases, $infos[$index]['used'], null, $sheets[$index]['rows'], $infos[$index]['header_row']) as $column) {
                $columns[$column['key']] ??= $column;
            }
            foreach ($infos[$index]['used'] as $field => $source) {
                $usage[$field] = $source;
            }
        }
        // Once a valid employee sheet is selected, title rows from other
        // workbook sheets are irrelevant and must not appear as unknown fields.
        $result['columns'] = array_values($columns);
        $result['field_usage'] = $usage;
        $result['unknown_columns'] = array_values(array_map(static fn (array $c): string => $c['header'], array_filter($columns, static fn (array $c): bool => $c['field'] === null)));

        $context = $this->loadContext($companyId);
        $seen = ['nid' => [], 'code' => []];
        $rows = [];
        $label = count($sheets) > 1;
        foreach ($selected as $index) {
            $info = $infos[$index];
            foreach ($sheets[$index]['rows'] as $rowNumber => $cells) {
                if ($rowNumber <= $info['header_row']) {
                    continue;
                }
                $record = [];
                foreach ($info['map'] as $column => $key) {
                    $record[$key] = isset($cells[$column]) ? trim((string) $cells[$column]) : '';
                }
                if (implode('', $record) === '') {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $result['file_errors'][] = 'عدد الصفوف أكبر من الحد المسموح في عملية الاستيراد الواحدة (' . self::MAX_ROWS . '). قسّم الملف أو اختر ورقة واحدة.';

                    return $result;
                }
                $rows[] = $this->analyzeRow($record, (int) $rowNumber, $context, $duplicates, $seen, $label ? $sheets[$index]['name'] : null);
            }
        }

        if ($rows === []) {
            $result['file_errors'][] = 'لا توجد صفوف بيانات أسفل صف العناوين.';

            return $result;
        }

        $this->checkManagers($rows, $context);

        $summary = $result['summary'];
        $summary['total'] = count($rows);
        foreach ($rows as $row) {
            $summary[$row['action']]++;
            if ($row['warnings'] !== []) {
                $summary['warnings']++;
            }
            if (!empty($row['defaulted_date']) && in_array($row['action'], ['create', 'update'], true)) {
                $summary['defaulted_dates']++;
            }
        }

        $result['rows'] = $rows;
        $result['summary'] = $summary;
        $result['capacity'] = $this->capacity($companyId, $rows, $context);
        $result['new_lookups'] = $this->newLookups($rows, $context);

        return $result;
    }

    /** A multi-sheet employee source must contain identity/profile data too. */
    private function isEmployeeDataSheet(array $used): bool
    {
        if (!isset($used['full_name'])) {
            return false;
        }

        foreach (['national_id', 'hire_date', 'contract_start_date', 'job', 'employment_type', 'phone', 'insurance_number'] as $marker) {
            if (isset($used[$marker])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Classify every workbook sheet before any import is committed. The sheet
     * NAME counts as evidence, dashboard/summary sheets are recognised before
     * data sheets so their aggregate columns never pull them into a data
     * domain, and an attendance sheet needs a real date column - anything
     * uncertain stays 'unknown' for the user to decide.
     */
    private function detectWorkbookSheetKind(array $rows, array $used, string $name = ''): string
    {
        // Dashboard/summary/account sheets are auxiliary, and the file's own
        // sheet name is the strongest signal for them.
        $auxiliaryNeedles = ['لوحة المؤشرات', 'لوحة التحكم', 'داشبورد', 'dashboard', 'حسابات اللوحة', 'حسابات لوحة المؤشرات', 'دليل الاستخدام', 'ملخص شئون العاملين', 'ملخص شؤون العاملين', 'كشف الحضور الشهري'];
        $nameKey = $this->key($name);
        if ($nameKey !== '') {
            foreach ($auxiliaryNeedles as $needle) {
                if (str_contains($nameKey, $this->key($needle))) return 'auxiliary';
            }
        }

        $headers = [];
        foreach (array_slice($rows, 0, 8, true) as $row) {
            foreach ($row as $value) {
                $value = $this->key((string) $value);
                if ($value !== '') $headers[$value] = true;
            }
        }
        $has = function (array $needles) use ($headers): bool {
            foreach ($needles as $needle) {
                $needle = $this->key((string) $needle);
                foreach (array_keys($headers) as $header) {
                    if (str_contains($header, $needle)) return true;
                }
            }
            return false;
        };
        $hasExact = function (array $needles) use ($headers): bool {
            foreach ($needles as $needle) {
                if (isset($headers[$this->key((string) $needle)])) return true;
            }
            return false;
        };

        if ($has($auxiliaryNeedles)) return 'auxiliary';
        $hasTime = $has(['وقتالحضور', 'وقت الانصراف', 'دقائقالتأخير', 'checkin', 'checkout']);
        $hasDate = $has(['التاريخ', 'تاريخ الحضور', 'attendance date', 'attendance_date']) || $hasExact(['date', 'day', 'اليوم']);
        if ($hasTime && $hasDate) return 'attendance';
        if ($has(['نوعالإجازة', 'من تاريخ', 'إلى تاريخ', 'leave type', 'start date'])) return 'leaves';
        if ($has(['نوع الجزاء', 'أيام الخصم', 'penalty type', 'deduction days'])) return 'penalties';
        if ($has(['مبلغ السلفة', 'تاريخ طلب السلفة', 'advance amount', 'advance date', 'سلفة'])) return 'advances';
        if ($has(['الإضافي النهاري', 'الإضافي الليلي', 'عدد الساعات', 'نوع الطلب', 'overtime', 'bonus', 'commission', 'incentive', 'profit', 'permission', 'mission', 'request type', 'إذن', 'مأمورية'])) return 'requests';
        // Insurance-record sheets need markers beyond الرقم التأميني, which is a
        // normal employee column too; a sheet that also carries strong employee
        // identity columns stays an employees sheet, and ambiguous sheets stay
        // 'unknown' for review rather than being guessed.
        if ($has(['الأجر التأميني', 'مبلغ الاشتراك', 'اشتراك التأمينات', 'insurance wage', 'insurance subscription'])
            && !$has(['الرقم القومي', 'تاريخ التعيين', 'بداية العقد', 'تاريخ الميلاد', 'national id', 'hire date', 'contract start', 'birth date'])) return 'insurance';
        if (isset($used['full_name']) || $has(['كود الموظف', 'اسم الموظف', 'employee code', 'full name'])) return 'employees';

        return 'unknown';
    }

    /** Confirmed per-sheet kinds from the user, keyed by sheet index or name; values limited to known kinds + ignore. */
    private function sanitizeSheetKinds(mixed $kinds): array
    {
        if (!is_array($kinds)) return [];
        $allowed = array_merge(array_keys(self::kindLabels()), ['ignore']);
        $clean = [];
        foreach ($kinds as $key => $kind) {
            $kind = (string) $kind;
            if (in_array($kind, $allowed, true)) $clean[(string) $key] = $kind;
        }

        return $clean;
    }

    /** First data rows of a sheet (strings, capped) so the user can eyeball what a sheet really is. */
    private function sheetSample(array $rows, ?int $headerRow, int $maxRows = 3, int $maxCols = 8): array
    {
        $sample = [];
        $count = 0;
        foreach ($rows as $number => $cells) {
            if ($count >= $maxRows) break;
            if ($headerRow !== null && $number < $headerRow) continue;
            $values = array_map(static fn ($v) => (string) $v, array_slice($cells, 0, $maxCols));
            if (implode('', $values) === '') continue;
            $sample[] = $values;
            $count++;
        }

        return $sample;
    }

    /** The importer that parses a sheet kind, and its mappable fields. */
    public static function domainFields(string $kind): array
    {
        return match ($kind) {
            'attendance' => WorkbookAttendanceImporter::MAPPABLE_FIELDS,
            'leaves' => WorkbookLeavePenaltyImporter::LEAVE_FIELDS,
            'penalties' => WorkbookLeavePenaltyImporter::PENALTY_FIELDS,
            'requests', 'advances' => WorkbookRequestImporter::MAPPABLE_FIELDS,
            'insurance' => WorkbookInsuranceImporter::MAPPABLE_FIELDS,
            default => [],
        };
    }

    /** Auto-detected header row + field map of a sheet for its (confirmed) kind. */
    private function detectDomainConfig(string $kind, array $rows): ?array
    {
        return match ($kind) {
            'attendance' => app(WorkbookAttendanceImporter::class)->detectConfig($rows),
            'leaves', 'penalties' => app(WorkbookLeavePenaltyImporter::class)->detectConfig($rows),
            'requests', 'advances' => app(WorkbookRequestImporter::class)->detectConfig($rows),
            'insurance' => app(WorkbookInsuranceImporter::class)->detectConfig($rows),
            default => null,
        };
    }

    /**
     * Fields a confirmed sheet of this kind must map before the parser can run
     * (mirrors the sheets-step editor; enforced server-side at commit).
     *
     * @return array<int, string>
     */
    public static function requiredDomainFields(string $kind): array
    {
        return match ($kind) {
            'attendance' => ['date'],
            'leaves' => ['leave_type', 'start', 'end'],
            'penalties' => ['penalty_type', 'date', 'deduction'],
            'requests', 'advances' => ['date'],
            'insurance' => ['insurance_number'],
            default => [],
        };
    }

    /**
     * First blocking problem with the confirmed per-sheet domain configs, or
     * null when every data sheet is importable exactly as reviewed. A sheet
     * the parser cannot read as its confirmed kind fails closed: it is never
     * silently re-detected or skipped at commit.
     *
     * @param array<int, array<string, mixed>> $sheets
     */
    public function domainConfigError(array $sheets): ?string
    {
        $labels = self::kindLabels();
        foreach ($sheets as $sheet) {
            $kind = (string) ($sheet['kind'] ?? 'unknown');
            $fields = self::domainFields($kind);
            if ($fields === []) {
                continue;
            }
            $name = (string) ($sheet['name'] ?? '');
            $config = $sheet['domain_config'] ?? null;
            if (!is_array($config) || (int) ($config['header_row'] ?? -1) < 0) {
                return 'ورقة «' . $name . '»: لم يتعرف التطبيق على صف العناوين لنوع «' . ($labels[$kind] ?? $kind) . '». اختر نوعًا آخر أو راجع الملف.';
            }
            $map = (array) ($config['map'] ?? []);
            $missing = [];
            foreach (self::requiredDomainFields($kind) as $field) {
                if (!isset($map[$field])) {
                    $missing[] = (string) ($fields[$field] ?? $field);
                }
            }
            if (in_array($kind, ['attendance', 'leaves', 'penalties', 'requests', 'advances'], true) && !isset($map['code']) && !isset($map['name'])) {
                $missing[] = 'كود أو اسم الموظف';
            }
            // Mirrors the insurance importer's own sheet gate.
            if ($kind === 'insurance' && isset($map['insurance_number'])
                && !array_intersect(['insured_salary', 'office', 'category', 'status', 'job_code'], array_keys($map))) {
                $missing[] = 'حقل تأميني واحد على الأقل (الأجر التأميني، المكتب، الفئة، الحالة، أو كود الوظيفة)';
            }
            if ($missing !== []) {
                return 'ورقة «' . $name . '»: أكمل ربط الحقول المطلوبة (' . implode('، ', $missing) . ').';
            }
        }

        return null;
    }

    /** Reviewed per-sheet column choices: only real fields of the sheet's domain, only non-negative column indexes. */
    private function sanitizeDomainMappings(mixed $mappings): array
    {
        if (!is_array($mappings)) return [];
        $clean = [];
        foreach ($mappings as $sheet => $fields) {
            if (!is_array($fields)) continue;
            foreach ($fields as $field => $column) {
                if (!is_numeric($column) || (int) $column < 0) continue;
                $clean[(string) $sheet][(string) $field] = (int) $column;
            }
        }

        return $clean;
    }

    /** Arabic labels for every workbook sheet kind shown in the preview. */
    public static function kindLabels(): array
    {
        return [
            'employees' => 'بيانات الموظفين',
            'attendance' => 'حضور وانصراف',
            'leaves' => 'إجازات',
            'penalties' => 'جزاءات',
            'advances' => 'سلف',
            'requests' => 'طلبات (إضافي / مكافآت / إذن / مأمورية)',
            'insurance' => 'تأمينات',
            'auxiliary' => 'تجاهل',
            'unknown' => 'غير معروفة - تحتاج مراجعة',
        ];
    }

    /**
     * Rejected rows laid out like the import template (original values + the
     * reason), so the user can fix them and upload the file again.
     *
     * @param array<string, mixed> $analysis Output of analyze()
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>} [headers, rows]
     */
    public function rejectedSheet(array $analysis): array
    {
        $rejected = array_values(array_filter($analysis['rows'], static fn (array $row): bool => $row['action'] === 'error'));

        $present = [];
        $multiSheet = false;
        foreach ($rejected as $row) {
            foreach ($row['raw'] as $key => $value) {
                if ($value !== '') {
                    $present[$key] = true;
                }
            }
            $multiSheet = $multiSheet || !empty($row['sheet']);
        }
        $present['full_name'] = true;

        $fields = array_values(array_filter(array_keys(self::COLUMNS), static fn (string $key): bool => isset($present[$key])));
        $headers = array_map(static fn (string $key): string => self::COLUMNS[$key][0], $fields);
        if ($multiSheet) {
            $headers[] = 'الورقة';
        }
        $headers[] = 'رقم الصف في الملف';
        $headers[] = 'سبب الرفض';

        $lines = [];
        foreach ($rejected as $row) {
            $line = [];
            foreach ($fields as $key) {
                $line[] = (string) ($row['raw'][$key] ?? '');
            }
            if ($multiSheet) {
                $line[] = (string) ($row['sheet'] ?? '');
            }
            $line[] = (string) $row['line'];
            $line[] = implode(' | ', $row['errors']);
            $lines[] = $line;
        }

        return [$headers, $lines];
    }

    /**
     * @return array<string, string> normalised header alias => field key
     */
    private function aliasMap(): array
    {
        $aliases = [];
        foreach (self::COLUMNS as $key => [$label, $extra]) {
            foreach (array_merge([$label], $extra) as $alias) {
                $aliases[$this->key($alias)] = $key;
            }
        }

        return $aliases;
    }

    /**
     * Keeps only [normalised header => known field] pairs (a field can be used once).
     *
     * @return array<string, string>
     */
    private function sanitizeMappings(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $clean = [];
        $taken = [];
        foreach ($input as $header => $field) {
            $header = (string) $header;
            if (!is_string($field) || !isset(self::COLUMNS[$field]) || isset($taken[$field]) || $header === '') {
                continue;
            }
            $clean[$header] = $field;
            $taken[$field] = true;
        }

        return $clean;
    }

    /**
     * Maps one header row. Built-in aliases win; manual mappings only apply to
     * headers the system did not recognise.
     *
     * @param array<int, string> $cells
     * @param array<string, string> $aliases
     * @param array<string, string> $manual
     * @return array{0: array<int, string>, 1: array<string, string>, 2: array<int, array<string, mixed>>}
     *         [column => field, field => source ("auto" or header key), unmatched header entries]
     */
    private function mapRow(array $cells, array $aliases, array $manual): array
    {
        $map = [];
        $used = [];
        $entries = [];

        foreach ($cells as $column => $value) {
            $normalized = $this->key((string) $value);
            if ($normalized === '') {
                continue;
            }
            if ($this->isCalculatedHeader($normalized)) {
                continue;
            }
            $field = $aliases[$normalized] ?? null;
            if ($field !== null && !isset($used[$field])) {
                $map[$column] = $field;
                $used[$field] = 'auto';
                continue;
            }
            $entries[] = ['key' => $normalized, 'header' => $this->cleanText((string) $value), 'column' => $column, 'field' => null];
        }

        foreach ($entries as $position => $entry) {
            $field = $manual[$entry['key']] ?? null;
            if ($field !== null && !isset($used[$field])) {
                $map[$entry['column']] = $field;
                $used[$field] = $entry['key'];
                $entries[$position]['field'] = $field;
            }
        }

        return [$map, $used, $entries];
    }

    private function isCalculatedHeader(string $normalized): bool
    {
        foreach (self::CALCULATED_HEADERS as $header) {
            if ($normalized === $this->key($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<string, string> $aliases
     * @param array<string, string> $manual
     * @return array{0: ?int, 1: array<int, string>, 2: array<int, array<string, mixed>>, 3: array<string, string>}
     */
    private function detectHeader(array $rows, array $aliases, array $manual): array
    {
        $scanned = 0;
        foreach ($rows as $rowNumber => $cells) {
            if (++$scanned > 15) {
                break;
            }
            [$map, $used, $entries] = $this->mapRow($cells, $aliases, $manual);
            if (isset($used['full_name'])) {
                return [(int) $rowNumber, $map, $entries, $used];
            }
        }

        return [null, [], [], []];
    }

    /**
     * Columns the user may want to map, with a "did you mean" suggestion that is
     * never applied until the user confirms it.
     *
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, string> $aliases
     * @param array<string, string> $used
     * @return array<int, array<string, mixed>>
     */
    private function columnList(array $entries, array $aliases, array $used, ?string $sheet = null, ?array $rows = null, ?int $headerRow = null): array
    {
        $list = [];
        foreach ($entries as $entry) {
            $suggestion = $entry['field'] === null ? $this->suggestField((string) $entry['key'], $aliases, $used) : null;
            $list[] = [
                'key' => (string) $entry['key'],
                'header' => (string) $entry['header'],
                'field' => $entry['field'],
                'suggestion' => $suggestion,
                'suggestion_label' => $suggestion !== null ? self::COLUMNS[$suggestion][0] : null,
                'sheet' => $sheet,
                'samples' => ($rows !== null && $headerRow !== null) ? $this->sampleValues($rows, $headerRow, (int) $entry['column']) : [],
            ];
        }

        return $list;
    }

    /**
     * Up to three distinct example values found under a column (shown to the user
     * while mapping so they can judge what the column contains).
     *
     * @param array<int, array<int, string>> $rows
     * @return array<int, string>
     */
    private function sampleValues(array $rows, int $headerRow, int $column): array
    {
        $samples = [];
        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber <= $headerRow || !isset($cells[$column])) {
                continue;
            }
            $value = $this->cleanText((string) $cells[$column]);
            if ($value === '') {
                continue;
            }
            $value = mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value;
            if (!in_array($value, $samples, true)) {
                $samples[] = $value;
            }
            if (count($samples) >= 3) {
                break;
            }
        }

        return $samples;
    }

    /**
     * Header-like first rows of worksheets that were not recognised, so the user can
     * map their columns too (single-cell rows such as cover-page titles are skipped).
     *
     * @param array<int, array{name: string, rows: array<int, array<int, string>>}> $sheets
     * @param array<int, array<string, mixed>> $infos
     * @param array<string, string> $aliases
     * @param array<string, string> $manual
     * @return array<string, array<string, mixed>> keyed by normalised header
     */
    private function unmatchedColumns(array $sheets, array $infos, array $aliases, array $manual, bool $force): array
    {
        $columns = [];
        foreach ($sheets as $index => $sheet) {
            if (($infos[$index]['matched'] ?? false) || $sheet['rows'] === []) {
                continue;
            }
            $firstRow = reset($sheet['rows']);
            if (!is_array($firstRow) || (!$force && count($firstRow) < 2)) {
                continue;
            }
            [, $used, $entries] = $this->mapRow($firstRow, $aliases, $manual);
            foreach ($this->columnList($entries, $aliases, $used, $sheet['name'], $sheet['rows'], (int) array_key_first($sheet['rows'])) as $column) {
                $columns[$column['key']] ??= $column;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, string> $aliases
     * @param array<string, string> $used
     */
    private function suggestField(string $key, array $aliases, array $used): ?string
    {
        $length = mb_strlen($key);
        if ($length < 3) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($aliases as $alias => $field) {
            if (isset($used[$field])) {
                continue;
            }
            $aliasLength = mb_strlen((string) $alias);
            if ($aliasLength >= 4 && (str_contains($key, (string) $alias) || str_contains((string) $alias, $key))) {
                $score = 0.8 + 0.2 * (min($length, $aliasLength) / max($length, $aliasLength));
            } else {
                $score = $this->similarity($key, (string) $alias);
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $field;
            }
        }

        return $bestScore >= 0.70 ? $best : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadContext(int $companyId): array
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'employee_code', 'national_id', 'full_name', 'status_id', 'insurance_number', 'phone', 'email', 'gender', 'birth_date', 'marital_status', 'qualification', 'address', 'contract_duration', 'shift_start', 'shift_end', 'manual_annual_balance', 'notes', 'hire_date', 'contract_start_date', 'contract_end_date', 'employment_type_id', 'termination_type', 'termination_date', 'department_id', 'job_id', 'branch_id', 'gross_salary']);

        $byNid = [];
        $byCode = [];
        $byName = [];
        $byId = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            // Raw attributes keep database date/time formats so the update
            // preview compares exactly what updateEmployee() would write.
            $byId[$id] = $employee->getAttributes();
            $byId[$id]['status_id'] = $employee->status_id !== null ? (int) $employee->status_id : null;
            if ($employee->national_id !== null && $employee->national_id !== '') {
                $byNid[(string) $employee->national_id] = $id;
            }
            if ($employee->employee_code !== null && $employee->employee_code !== '') {
                $byCode[mb_strtolower((string) $employee->employee_code)] = $id;
            }
            $nameKey = $this->key((string) $employee->full_name);
            if ($nameKey !== '') {
                $byName[$nameKey] = array_key_exists($nameKey, $byName) ? null : $id;
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
            'byName' => $byName,
            'byId' => $byId,
            'statuses' => $statuses,
            'statusNameById' => $statusNameById,
            'employmentTypes' => $employmentTypes,
            'employmentTypeNames' => array_keys($employmentTypeNames),
            'departments' => $this->nameKeys(Department::class, 'name', $companyId),
            'jobs' => $this->nameKeys(Job::class, 'title', $companyId),
            'branches' => $this->nameKeys(Branch::class, 'name', $companyId),
            'departmentNameById' => $this->nameById(Department::class, 'name', $companyId),
            'jobNameById' => $this->nameById(Job::class, 'title', $companyId),
            'branchNameById' => $this->nameById(Branch::class, 'name', $companyId),
            'employmentTypeNameById' => $this->employmentTypeNameById($companyId),
        ];
    }

    /** @return array<int, string> */
    private function nameById(string $modelClass, string $column, int $companyId): array
    {
        $map = [];
        $models = $modelClass::withoutGlobalScopes()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->get(['id', $column]);
        foreach ($models as $model) {
            $map[(int) $model->id] = (string) $model->{$column};
        }

        return $map;
    }

    /** @return array<int, string> */
    private function employmentTypeNameById(int $companyId): array
    {
        $map = [];
        $types = EmploymentType::withoutGlobalScopes()
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->get(['id', 'name']);
        foreach ($types as $type) {
            $map[(int) $type->id] = (string) $type->name;
        }

        return $map;
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
    private function analyzeRow(array $raw, int $line, array $ctx, string $duplicates, array &$seen, ?string $sheetName = null): array
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
        if ($get('manager_name') !== '') {
            $managerName = $this->cleanText($get('manager_name'));
            if (mb_strlen($managerName) > 255) {
                $errors[] = 'اسم المدير المباشر أطول من 255 حرفًا.';
            } else {
                $data['manager_name'] = $managerName;
            }
        }

        // ---- status / termination --------------------------------------
        $statusName = null;
        $impliedType = null;
        $defaultedDate = false;
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
                    if ($terminationDate === null && $get('termination_date') === '') {
                        $defaultedDate = true;
                    }
                }
            } elseif ($kind === 'suspended') {
                $data['termination_type'] = null;
                $data['termination_date'] = $terminationDate ?? date('Y-m-d');
                if ($terminationDate === null && $get('termination_date') === '') {
                    $defaultedDate = true;
                }
            }
            if ($defaultedDate) {
                $warnings[] = 'لم يُحدَّد تاريخ انتهاء الخدمة؛ سيُعتمد تاريخ اليوم (' . date('d/m/Y') . ').';
            }
        }

        // ---- dates ------------------------------------------------------
        $hire = null;
        if ($get('hire_date') !== '') {
            $hire = $attempt('بداية العقد (عمود قديم)', fn () => $this->parseDate($get('hire_date')));
        }
        $contractStart = null;
        if ($get('contract_start_date') !== '') {
            $contractStart = $attempt('بداية العقد', fn () => $this->parseDate($get('contract_start_date')));
        }
        $contractEnd = null;
        if ($get('contract_end_date') !== '') {
            $contractEnd = $attempt('نهاية العقد', fn () => $this->parseDate($get('contract_end_date')));
        }

        try {
            $contractStart = \App\Support\EmploymentStartDate::resolve($contractStart, $hire);
            $hire = $contractStart;
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $errors[] = $exception->errors()['contract_start_date'][0];
        }

        if ($isNew) {
            $hire ??= date('Y-m-d');
            $contractStart ??= $hire;
            $contractEnd ??= $this->renewedContractEnd($contractStart);
        } elseif ($hire !== null && $contractStart === null) {
            $contractStart = $hire;
        }
        // A missing end date means an annually renewing contract, not a
        // contract that expires one year after the original hire date.
        if ($contractEnd === null && $contractStart !== null && $get('termination_date') === '') {
            $contractEnd = $this->renewedContractEnd($contractStart);
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

        if ($get('manual_annual_balance') !== '') {
            $balance = $attempt('رصيد الإجازة السنوية اليدوي', fn () => $this->parseMoney($get('manual_annual_balance')));
            if ($balance !== null) {
                if ($balance < 0 || $balance > 365) {
                    $errors[] = 'رصيد الإجازة السنوية اليدوي يجب أن يكون بين 0 و365 يومًا.';
                } else {
                    $data['manual_annual_balance'] = round($balance, 2);
                }
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

        $changes = [];
        if ($action === 'update' && $existingId !== null) {
            $changes = $this->computeChanges($data, $ctx['byId'][$existingId] ?? [], $ctx);
        }

        return [
            'line' => $line,
            'changes' => $changes,
            'name' => $name,
            'code' => $code !== '' ? $code : null,
            'national_id' => $nid !== '' ? $nid : null,
            'action' => $action,
            'existing_id' => $existingId,
            'sheet' => $sheetName,
            'raw' => $raw,
            'defaulted_date' => $defaultedDate,
            'final_status' => $finalStatus,
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Field-level old => new preview for an update row: exactly the fields
     * updateEmployee() would write, compared against the current record.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $existing raw employee attributes
     * @param array<string, mixed> $ctx
     * @return array<int, array{field: string, label: string, old: string, new: string}>
     */
    private function computeChanges(array $data, array $existing, array $ctx): array
    {
        if ($existing === []) {
            return [];
        }

        $labels = [];
        foreach (self::COLUMNS as $key => [$label]) {
            $labels[$key] = $label;
        }

        $changes = [];
        $add = function (string $field, string $label, mixed $old, mixed $new) use (&$changes): void {
            $oldText = trim((string) ($old ?? ''));
            $newText = trim((string) ($new ?? ''));
            if ($oldText !== $newText) {
                $changes[] = ['field' => $field, 'label' => $label, 'old' => $oldText, 'new' => $newText];
            }
        };

        foreach (['full_name', 'national_id', 'insurance_number', 'phone', 'email', 'gender', 'birth_date', 'marital_status', 'qualification', 'address', 'contract_duration', 'shift_start', 'shift_end', 'manual_annual_balance', 'notes', 'hire_date', 'contract_start_date', 'contract_end_date', 'termination_type', 'termination_date'] as $field) {
            if ($field === 'hire_date') continue;
            if (array_key_exists($field, $data)) {
                $add($field, $labels[$field] ?? $field, $existing[$field] ?? null, $data[$field]);
            }
        }

        foreach (['department' => ['department_id', 'departmentNameById'], 'job' => ['job_id', 'jobNameById'], 'branch' => ['branch_id', 'branchNameById']] as $field => [$column, $mapKey]) {
            if (array_key_exists($field, $data)) {
                $oldName = $ctx[$mapKey][(int) ($existing[$column] ?? 0)] ?? '';
                $add($field, $labels[$field], $oldName, $data[$field]);
            }
        }

        if (array_key_exists('employment_type_id', $data)) {
            $oldType = $ctx['employmentTypeNameById'][(int) ($existing['employment_type_id'] ?? 0)] ?? '';
            $newType = $ctx['employmentTypeNameById'][(int) $data['employment_type_id']] ?? '';
            $add('employment_type', $labels['employment_type'], $oldType, $newType);
        }

        if (array_key_exists('status_name', $data)) {
            $oldStatus = $ctx['statusNameById'][(int) ($existing['status_id'] ?? 0)] ?? '';
            $add('status', $labels['status'], $oldStatus, $data['status_name']);
        }

        $gross = $this->resolveGross($data);
        if ($gross !== null && abs((float) ($existing['gross_salary'] ?? 0) - $gross) > 0.004) {
            $add('gross_salary', $labels['gross_salary'], $existing['gross_salary'] ?? null, $gross);
        }

        return $changes;
    }

    /**
     * Re-validate one corrected row from the review queue with the same rules
     * as a fresh file import (never trust the stored payload).
     *
     * @param array<string, string> $raw
     * @return array<string, mixed>
     */
    public function analyzeSingleRow(array $raw, int $companyId): array
    {
        $ctx = $this->loadContext($companyId);
        $seen = ['nid' => [], 'code' => []];

        return $this->analyzeRow($raw, 0, $ctx, 'update', $seen, null);
    }

    private function renewedContractEnd(string $start): string
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $end = $startDate->copy()->addYear()->subDay();
        $today = Carbon::today();
        while ($end->lt($today)) {
            $end->addYear();
        }
        return $end->toDateString();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $ctx
     */
    private function checkManagers(array &$rows, array $ctx): void
    {
        $fileCodes = [];
        $fileNames = [];
        foreach ($rows as $row) {
            if ($row['action'] !== 'error' && !empty($row['data']['employee_code'])) {
                $fileCodes[mb_strtolower((string) $row['data']['employee_code'])] = true;
            }
            if ($row['action'] !== 'error' && !empty($row['data']['full_name'])) {
                $key = $this->key((string) $row['data']['full_name']);
                $fileNames[$key] = array_key_exists($key, $fileNames) ? false : true;
            }
        }

        foreach ($rows as $index => $row) {
            if ($row['action'] === 'error') {
                continue;
            }
            if (!empty($row['data']['manager_code'])) {
                $managerCode = mb_strtolower((string) $row['data']['manager_code']);
                if (!isset($ctx['byCode'][$managerCode]) && !isset($fileCodes[$managerCode])) {
                    $rows[$index]['warnings'][] = 'كود المدير المباشر «' . $row['data']['manager_code'] . '» غير موجود؛ لن يُربط المدير.';
                }
                continue;
            }
            if (!empty($row['data']['manager_name'])) {
                $managerName = $this->key((string) $row['data']['manager_name']);
                if (($ctx['byName'][$managerName] ?? null) === null && !($fileNames[$managerName] ?? false)) {
                    $rows[$index]['warnings'][] = 'اسم المدير المباشر «' . $row['data']['manager_name'] . '» غير موجود أو غير فريد؛ لن يُربط المدير.';
                }
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

        $report = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => (int) ($analysis['summary']['skip'] ?? 0), 'failed' => [], 'warnings' => [], 'created_ids' => [], 'updated_ids' => [], 'movement_ids' => []];
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
                if ($outcome['result'] === 'created') {
                    $report['created_ids'][] = $outcome['id'];
                }
                if ($outcome['result'] === 'updated') {
                    $report['updated_ids'][] = $outcome['id'];
                }
                if (!empty($outcome['movement_id'])) {
                    $report['movement_ids'][] = $outcome['movement_id'];
                }
                if (!empty($row['data']['manager_code']) || !empty($row['data']['manager_name'])) {
                    $managerLinks[] = [
                        'id' => $outcome['id'],
                        'code' => (string) ($row['data']['manager_code'] ?? ''),
                        'manager_name' => (string) ($row['data']['manager_name'] ?? ''),
                        'line' => $row['line'],
                        'name' => $row['name'],
                    ];
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
            'manual_annual_balance' => $d['manual_annual_balance'] ?? null,
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

        return ['id' => (int) $employee->id, 'result' => 'created', 'movement_id' => null];
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
        foreach (['full_name', 'national_id', 'insurance_number', 'phone', 'email', 'gender', 'birth_date', 'marital_status', 'qualification', 'address', 'contract_duration', 'shift_start', 'shift_end', 'manual_annual_balance', 'notes', 'hire_date', 'contract_start_date', 'contract_end_date', 'employment_type_id', 'termination_type', 'termination_date'] as $field) {
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
            $movement = MovementRecord::create([
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
            return ['id' => (int) $employee->id, 'result' => 'unchanged', 'movement_id' => null];
        }

        ActivityLog::record(
            'employee_import_update',
            "تم تحديث بيانات الموظف من ملف Excel: {$employee->full_name}",
            $employee,
            $before,
            array_intersect_key($employee->getAttributes(), $before),
            'employees'
        );

        return ['id' => (int) $employee->id, 'result' => 'updated', 'movement_id' => isset($movement) ? (int) $movement->id : null];
    }

    /**
     * @param array<int, array{id: int, code: string, manager_name: string, line: int, name: string}> $links
     * @param array<string, mixed> $report
     */
    private function linkManagers(array $links, int $companyId, array &$report): void
    {
        if ($links === []) {
            return;
        }

        $codeToId = [];
        $nameToId = [];
        $employees = Employee::withoutGlobalScopes()->where('company_id', $companyId)->get(['id', 'employee_code', 'full_name']);
        foreach ($employees as $employee) {
            $codeToId[mb_strtolower((string) $employee->employee_code)] = (int) $employee->id;
            $nameKey = $this->key((string) $employee->full_name);
            if ($nameKey !== '') {
                $nameToId[$nameKey] = array_key_exists($nameKey, $nameToId) ? null : (int) $employee->id;
            }
        }

        foreach ($links as $link) {
            $managerId = $link['code'] !== ''
                ? ($codeToId[mb_strtolower($link['code'])] ?? null)
                : ($nameToId[$this->key($link['manager_name'])] ?? null);
            if ($managerId === null) {
                $reference = $link['code'] !== '' ? 'كود المدير المباشر «' . $link['code'] . '»' : 'اسم المدير المباشر «' . $link['manager_name'] . '»';
                $report['warnings'][] = ['line' => $link['line'], 'name' => $link['name'], 'messages' => [$reference . ' غير موجود أو غير فريد؛ لم يُربط المدير.']];
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

        $effectiveFrom = $employee->contract_start_date?->format('Y-m-d') ?? today()->format('Y-m-d');
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

}
