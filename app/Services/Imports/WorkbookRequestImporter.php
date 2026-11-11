<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\RequestType;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;

/**
 * Imports advances, overtime, financial requests, permissions and missions
 * from workbook sheets, in Arabic or English. Duplicate rules match the
 * request form: advance/bonus may not repeat inside the same month (row is
 * rejected), other dated types import with a dated warning, permission and
 * mission never warn. Document requests and "other" stay unsupported.
 */
class WorkbookRequestImporter
{
    public const MAPPABLE_FIELDS = ['code' => 'كود الموظف', 'name' => 'اسم الموظف', 'date' => 'تاريخ الطلب', 'amount' => 'المبلغ', 'hours' => 'عدد الساعات', 'period' => 'نوع الساعات', 'from' => 'من الساعة', 'to' => 'إلى الساعة', 'end' => 'تاريخ الانتهاء', 'type' => 'نوع الطلب', 'details' => 'التفاصيل'];

    /** Detected header row + field map for one sheet, exposed for the review UI. */
    public function detectConfig(array $rows): ?array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if ($this->looksLikeHeader($key, $row)) {
                return ['header_row' => (int) $number, 'headers' => array_map(static fn ($v) => (string) $v, $row), 'map' => $this->mapHeaders($row)];
            }
        }

        return null;
    }

    public function import(string $path, string $extension, PayrollPeriodGuard $guard, PayrollRunService $payrollRuns, ?array $onlySheets = null, ?array $configs = null): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'warnings' => [], 'failures' => []];
        foreach (app(SpreadsheetReader::class)->readAll($path, $extension) as $sheet) {
            if ($onlySheets !== null && !in_array((string) ($sheet['name'] ?? ''), $onlySheets, true)) continue;
            $config = $configs[$sheet['name']] ?? null;
            if ($config !== null) {
                $headerRow = (int) ($config['header_row'] ?? -1);
                $headers = $sheet['rows'][$headerRow] ?? [];
                $rows = array_filter($sheet['rows'] ?? [], fn ($unused, $line) => $line > $headerRow, ARRAY_FILTER_USE_BOTH);
                $map = array_map('intval', $config['map'] ?? []);
            } else {
                [$headers, $rows] = $this->splitHeader($sheet['rows'] ?? []);
                $map = $this->mapHeaders($headers);
            }
            if (!isset($map['date']) || (!isset($map['code']) && !isset($map['name']))) continue;
            foreach ($rows as $line => $row) {
                try {
                    $employee = $this->employee($row[$map['code']] ?? null, $row[$map['name'] ?? -1] ?? null);
                    if (!$employee) throw new \RuntimeException('الموظف غير موجود أو الاسم لا يطابق الكود.');
                    $date = $this->date($row[$map['date']] ?? null); if (!$date) throw new \RuntimeException('تاريخ الطلب غير صالح.');
                    $guard->ensureOpen($date);
                    $source = $this->source($row, $map, $headers);
                    if (!$source) throw new \RuntimeException('نوع الطلب غير معروف.');
                    $type = RequestType::active()->where('source_key', $source)->first();
                    if (!$type) throw new \RuntimeException('نوع الطلب غير موجود في إعدادات الشركة.');
                    $payload = ['request_date' => $date];
                    if ($source === 'advance' || in_array($source, ['bonus', 'incentive', 'commission', 'profit_share'], true)) {
                        if (!isset($map['amount'])) throw new \RuntimeException('عمود «المبلغ» غير موجود في الورقة.');
                        $payload['amount'] = (float) ($row[$map['amount']] ?? 0);
                        if ($payload['amount'] <= 0) throw new \RuntimeException('قيمة الطلب يجب أن يكون أكبر من صفر.');
                        if ($source !== 'advance') { $payload['affects_payroll'] = true; $payload['impact_type'] = 'earning'; }
                    } elseif ($source === 'overtime') {
                        [$from, $to] = $this->window($row, $map, 'طلب الإضافي');
                        $period = $this->period($row[$map['period'] ?? -1] ?? '');
                        // Hours always come from the from/to window itself,
                        // matching the request form; any hours column in the
                        // sheet is ignored. Payroll still pays the window's
                        // overlap with actual attendance.
                        $payload['hours'] = $this->windowHours($from, $to);
                        $payload['overtime_period'] = $period ?: 'day';
                        $payload['from_time'] = $from; $payload['to_time'] = $to;
                    } elseif ($source === 'permission') {
                        // A permission is one dated interval, exactly like the form.
                        [$from, $to] = $this->window($row, $map, 'طلب الإذن');
                        $payload['start_date'] = $date; $payload['end_date'] = $date;
                        $payload['from_time'] = $from; $payload['to_time'] = $to;
                    } else { // mission: a single day, or a span when an end date column is present
                        $payload['start_date'] = $date;
                        $end = isset($map['end']) && filled($row[$map['end']] ?? null) ? $this->date($row[$map['end']] ?? null) : null;
                        if (isset($map['end']) && filled($row[$map['end']] ?? null) && !$end) throw new \RuntimeException('تاريخ الانتهاء غير صالح.');
                        if ($end) {
                            if (Carbon::parse($end)->lt(Carbon::parse($date))) throw new \RuntimeException('تاريخ الانتهاء يجب ألا يسبق تاريخ الطلب.');
                            $payload['end_date'] = $end;
                        }
                        $from = $this->time($row[$map['from'] ?? -1] ?? null);
                        $to = $this->time($row[$map['to'] ?? -1] ?? null);
                        if (($from && !$to) || (!$from && $to)) throw new \RuntimeException('وقتا «من الساعة» و«إلى الساعة» يُكتبان معًا أو يُتركان فارغين.');
                        if ($from && $to) {
                            if ($from === $to) throw new \RuntimeException('وقت «من الساعة» يجب أن يختلف عن «إلى الساعة».');
                            $payload['from_time'] = $from; $payload['to_time'] = $to;
                        }
                    }
                    $warning = $this->duplicatePolicy($employee, $type, $source, $date, $payload['overtime_period'] ?? null);
                    HrRequest::create(['employee_id' => $employee->id, 'request_type' => $type->code, 'request_type_id' => $type->id, 'title' => $type->display_name, 'details' => $row[$map['details'] ?? -1] ?? null, 'payload' => $payload, 'status' => 'Approved', 'created_by' => auth()->id(), 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                    $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id); $result['imported']++;
                    if ($warning && count($result['warnings']) < 50) $result['warnings'][] = "{$sheet['name']} / الصف {$line}: {$warning}";
                } catch (\Throwable $exception) { $result['skipped']++; if (count($result['errors']) < 25) $result['errors'][] = "{$sheet['name']} / الصف {$line}: {$exception->getMessage()}"; if (count($result['failures']) < 100) $result['failures'][] = ['sheet' => $sheet['name'], 'line' => $line, 'headers' => array_map(static fn ($v) => (string) $v, $headers), 'values' => array_map(static fn ($v) => (string) $v, $row), 'errors' => [$exception->getMessage()]]; }
            }
        }
        return $result;
    }

    /**
     * Same-employee duplicate policy, mirroring the request form: advance or
     * bonus may not repeat inside the same month (the row is rejected),
     * overtime repeats are detected per day and period, other financial types
     * per month - both import with a dated warning. Permission and mission
     * never warn.
     */
    private function duplicatePolicy(Employee $employee, RequestType $type, string $source, string $date, ?string $period): ?string
    {
        if (in_array($source, ['permission', 'mission'], true)) return null;

        $existing = HrRequest::where('employee_id', $employee->id)
            ->effective()
            ->where(function ($query) use ($type) {
                $query->where('request_type_id', $type->id)
                    ->orWhere('request_type', $type->code);
            })->get(['payload', 'created_at'])
            ->filter(function (HrRequest $candidate) use ($source, $date, $period): bool {
                $candidateDate = data_get($candidate->payload, 'request_date') ?: $candidate->created_at?->toDateString();
                if (!$candidateDate) return false;
                if ($source === 'overtime') {
                    return Carbon::parse($candidateDate)->isSameDay(Carbon::parse($date))
                        && data_get($candidate->payload, 'overtime_period') === $period;
                }
                return Carbon::parse($candidateDate)->isSameMonth(Carbon::parse($date));
            })->first();
        if (!$existing) return null;

        $existingDate = data_get($existing->payload, 'request_date') ?: $existing->created_at?->toDateString();
        if (in_array($source, ['advance', 'bonus'], true)) {
            throw new \RuntimeException("يوجد طلب قائم لهذا الموظف داخل نفس الشهر (بتاريخ {$existingDate}). لا يمكن استيراد طلب آخر من النوع نفسه.");
        }
        if ($source === 'overtime') {
            $periodLabel = $period === 'night' ? 'ليلي' : 'نهاري';
            return "يوجد إضافي {$periodLabel} قائم لهذا الموظف في نفس اليوم ({$existingDate}). سُجّل الطلب دون منع.";
        }
        return "يوجد طلب «{$type->display_name}» قائم لهذا الموظف بتاريخ {$existingDate}. سُجّل الطلب دون منع.";
    }

    /** Required from/to window for overtime and permission rows. */
    private function window(array $row, array $map, string $label): array
    {
        $from = $this->time($row[$map['from'] ?? -1] ?? null);
        $to = $this->time($row[$map['to'] ?? -1] ?? null);
        if (!$from || !$to) throw new \RuntimeException("{$label} يتطلب عمودي «من الساعة» و«إلى الساعة» بصيغة HH:MM.");
        if ($from === $to) throw new \RuntimeException('وقت «من الساعة» يجب أن يختلف عن «إلى الساعة».');
        return [$from, $to];
    }

    /** Window length in hours; an end at or before the start crosses midnight. */
    private function windowHours(string $from, string $to): float
    {
        [$fromHour, $fromMinute] = array_map('intval', explode(':', $from));
        [$toHour, $toMinute] = array_map('intval', explode(':', $to));
        $minutes = ($toHour * 60 + $toMinute) - ($fromHour * 60 + $fromMinute);
        if ($minutes <= 0) $minutes += 1440;
        return round($minutes / 60, 2);
    }

    private function splitHeader(array $rows): array { foreach ($rows as $number => $row) { $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row))); if ($this->looksLikeHeader($key, $row)) return [$row, array_filter($rows, fn ($unused, $line) => $line > $number, ARRAY_FILTER_USE_BOTH)]; } return [[], []]; }

    /** A request sheet header row carries the employee code and a date, in either language. */
    private function looksLikeHeader(string $key, array $row = []): bool
    {
        $hasCode = str_contains($key, 'كودالموظف') || str_contains($key, 'employeecode');
        if (!$hasCode) {
            // A standalone «الكود»/«كود» header cell also marks the code column.
            foreach ($row as $value) {
                if (in_array($this->normalise($value), ['الكود', 'كود'], true)) { $hasCode = true; break; }
            }
        }
        return $hasCode && (str_contains($key, 'تاريخ') || str_contains($key, 'date'));
    }

    private function mapHeaders(array $headers): array { $aliases = ['code' => ['كود الموظف','الكود','كود','employee code','code','emp code','employee id'], 'name' => ['اسم الموظف','employee name','name','full name','employee'], 'date' => ['تاريخ الطلب','تاريخ طلب السلفة','تاريخ الإضافي','التاريخ','date','request date'], 'amount' => ['مبلغ السلفة','المبلغ','القيمة','amount','advance amount','bonus amount','value'], 'hours' => ['عدد الساعات','الساعات','hours','total hours'], 'period' => ['نوع الإضافي','نوع الساعات','overtime period','period'], 'from' => ['من الساعة','من الساعه','from time','from','وقت البداية','start time'], 'to' => ['إلى الساعة','الي الساعة','الى الساعة','الى الساعه','to time','to','وقت النهاية','end time'], 'end' => ['تاريخ الانتهاء','تاريخ النهاية','end date','to date'], 'type' => ['نوع الطلب','النوع','request type','type'], 'details' => ['التفاصيل','السبب','ملاحظات','details','notes','reason']]; $map=[]; foreach($headers as $i=>$header){$key=$this->normalise($header);foreach($aliases as $field=>$values)if(in_array($key,array_map(fn($v)=>$this->normalise($v),$values),true)){$map[$field]=$i;break;}}return $map; }
    private function source(array $row, array $map, array $headers): ?string
    {
        $value = $map['type'] ?? null;
        $label = $value !== null ? $this->normalise($row[$value] ?? '') : $this->normalise(implode(' ', $headers));
        // "permission" and "commission" both contain "mission", so they must
        // be tested before it.
        if (str_contains($label, 'إذن') || str_contains($label, 'permission')) return 'permission';
        if (str_contains($label, 'عمول') || str_contains($label, 'commission')) return 'commission';
        if (str_contains($label, 'مأمور') || str_contains($label, 'mission') || str_contains($label, 'errand')) return 'mission';
        if (str_contains($label, 'سلفة') || str_contains($label, 'advance')) return 'advance';
        if (str_contains($label, 'إضاف') || str_contains($label, 'overtime') || isset($map['hours'])) return 'overtime';
        if (str_contains($label, 'حافز') || str_contains($label, 'حوافز') || str_contains($label, 'incentive')) return 'incentive';
        if (str_contains($label, 'أرباح') || str_contains($label, 'ارباح') || str_contains($label, 'profit')) return 'profit_share';
        if (str_contains($label, 'مكاف') || str_contains($label, 'bonus')) return 'bonus';
        return null;
    }
    private function period(mixed $value): ?string { $key=$this->normalise($value); return str_contains($key,'ليل') || str_contains($key,'night') ? 'night' : (str_contains($key,'نهار') || str_contains($key,'day') ? 'day' : null); }
    private function employee(mixed $code,mixed $name): ?Employee { if(filled($code)){ $code=trim((string)$code); $employee=Employee::where('employee_code',$code)->first(); if(!$employee){throw new \RuntimeException('كود الموظف «' . $code . '» غير موجود في الشركة.');} if(filled($name)&&$this->person($employee->full_name)!==$this->person($name)){throw new \RuntimeException('كود الموظف لا يطابق الاسم.');} return $employee;} if(!filled($name)){return null;} $matches=Employee::where('full_name',trim((string)$name))->get(); if($matches->count()>1){throw new \RuntimeException('الاسم يطابق أكثر من موظف؛ حدّد كود الموظف بدلًا من التخمين.');} return $matches->first(); }
    private function normalise(mixed $value): string { return preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower(trim((string)$value))); }
    private function person(mixed $value): string { return mb_strtolower((string)preg_replace('/\s+/u',' ',trim((string)$value))); }
    private function date(mixed $value): ?string { try{return filled($value)?Carbon::parse($value)->toDateString():null;}catch(\Throwable){return null;} }
    private function time(mixed $value): ?string { if(!filled($value))return null; $value=trim((string)$value); if(preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/',$value,$m)){ $h=(int)$m[1]; $min=(int)$m[2]; return $h<24&&$min<60?sprintf('%02d:%02d',$h,$min):null; } try{return Carbon::parse($value)->format('H:i');}catch(\Throwable){return null;} }
}
