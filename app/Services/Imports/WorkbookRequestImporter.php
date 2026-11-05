<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\RequestType;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use Carbon\Carbon;

/** Imports advances, overtime and financial requests from workbook sheets. */
class WorkbookRequestImporter
{
    public const MAPPABLE_FIELDS = ['code' => 'كود الموظف', 'name' => 'اسم الموظف', 'date' => 'تاريخ الطلب', 'amount' => 'المبلغ', 'hours' => 'عدد الساعات', 'period' => 'نوع الساعات', 'from' => 'من الساعة', 'to' => 'إلى الساعة', 'type' => 'نوع الطلب', 'details' => 'التفاصيل'];

    /** Detected header row + field map for one sheet, exposed for the review UI. */
    public function detectConfig(array $rows): ?array
    {
        foreach ($rows as $number => $row) {
            $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row)));
            if (str_contains($key, 'كودالموظف') && (str_contains($key, 'تاريخ') || str_contains($key, 'date'))) {
                return ['header_row' => (int) $number, 'headers' => array_map(static fn ($v) => (string) $v, $row), 'map' => $this->mapHeaders($row)];
            }
        }

        return null;
    }

    public function import(string $path, string $extension, PayrollPeriodGuard $guard, PayrollRunService $payrollRuns, ?array $onlySheets = null, ?array $configs = null): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => [], 'failures' => []];
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
            if (!isset($map['date'], $map['code']) || !isset($map['amount']) && !isset($map['hours'])) continue;
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
                    $period = $this->period($row[$map['period'] ?? -1] ?? '');
                    $payload = ['request_date' => $date];
                    if ($source === 'advance') $payload['amount'] = (float) ($row[$map['amount']] ?? 0);
                    elseif ($source === 'overtime') {
                        $payload['hours'] = (float) ($row[$map['hours'] ?? -1] ?? 0); $payload['overtime_period'] = $period ?: 'day';
                        // Timed overtime only: a request earns just its window's
                        // overlap with actual attendance, so the window is required.
                        $from = $this->time($row[$map['from'] ?? -1] ?? null);
                        $to = $this->time($row[$map['to'] ?? -1] ?? null);
                        if (!$from || !$to) throw new \RuntimeException('طلب الإضافي يتطلب عمودي «من الساعة» و«إلى الساعة» بصيغة HH:MM.');
                        if ($from === $to) throw new \RuntimeException('وقت «من الساعة» يجب أن يختلف عن «إلى الساعة».');
                        $payload['from_time'] = $from; $payload['to_time'] = $to;
                    }
                    else { $payload['amount'] = (float) ($row[$map['amount'] ?? -1] ?? 0); $payload['affects_payroll'] = true; $payload['impact_type'] = 'earning'; }
                    if (($payload['amount'] ?? $payload['hours'] ?? 0) <= 0) throw new \RuntimeException('قيمة الطلب أو عدد الساعات يجب أن يكون أكبر من صفر.');
                    $duplicate = HrRequest::where('employee_id', $employee->id)->where('request_type_id', $type->id)->get(['payload', 'created_at'])->contains(function (HrRequest $existing) use ($date, $period): bool {
                        return (data_get($existing->payload, 'request_date') ?: $existing->created_at?->toDateString()) === $date && (($period === null) || data_get($existing->payload, 'overtime_period') === $period);
                    });
                    if ($duplicate) throw new \RuntimeException('الطلب موجود بالفعل لنفس الموظف والتاريخ.');
                    HrRequest::create(['employee_id' => $employee->id, 'request_type' => $type->code, 'request_type_id' => $type->id, 'title' => $type->display_name, 'details' => $row[$map['details'] ?? -1] ?? null, 'payload' => $payload, 'status' => 'Approved', 'created_by' => auth()->id(), 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                    $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id); $result['imported']++;
                } catch (\Throwable $exception) { $result['skipped']++; if (count($result['errors']) < 25) $result['errors'][] = "{$sheet['name']} / الصف {$line}: {$exception->getMessage()}"; if (count($result['failures']) < 100) $result['failures'][] = ['sheet' => $sheet['name'], 'line' => $line, 'headers' => array_map(static fn ($v) => (string) $v, $headers), 'values' => array_map(static fn ($v) => (string) $v, $row), 'errors' => [$exception->getMessage()]]; }
            }
        }
        return $result;
    }

    private function splitHeader(array $rows): array { foreach ($rows as $number => $row) { $key = $this->normalise(implode(' ', array_map(static fn ($value) => (string) $value, $row))); if (str_contains($key, 'كودالموظف') && (str_contains($key, 'تاريخ') || str_contains($key, 'date'))) return [$row, array_filter($rows, fn ($unused, $line) => $line > $number, ARRAY_FILTER_USE_BOTH)]; } return [[], []]; }
    private function mapHeaders(array $headers): array { $aliases = ['code' => ['كود الموظف','employee code','code'], 'name' => ['اسم الموظف','employee name','name'], 'date' => ['تاريخ الطلب','تاريخ الإضافي','التاريخ','date'], 'amount' => ['مبلغ السلفة','المبلغ','القيمة','amount'], 'hours' => ['عدد الساعات','الساعات','hours'], 'period' => ['نوع الإضافي','نوع الساعات','overtime period'], 'from' => ['من الساعة','من الساعه','from time','from','وقت البداية'], 'to' => ['إلى الساعة','الي الساعة','الى الساعة','الى الساعه','to time','to','وقت النهاية'], 'type' => ['نوع الطلب','النوع','request type'], 'details' => ['التفاصيل','السبب','ملاحظات','details']]; $map=[]; foreach($headers as $i=>$header){$key=$this->normalise($header);foreach($aliases as $field=>$values)if(in_array($key,array_map(fn($v)=>$this->normalise($v),$values),true)){$map[$field]=$i;break;}}return $map; }
    private function source(array $row, array $map, array $headers): ?string
    {
        $value = $map['type'] ?? null;
        $label = $value !== null ? $this->normalise($row[$value] ?? '') : $this->normalise(implode(' ', $headers));
        if (str_contains($label, 'سلفة') || str_contains($label, 'advance')) return 'advance';
        if (str_contains($label, 'إضاف') || str_contains($label, 'overtime') || isset($map['hours'])) return 'overtime';
        if (str_contains($label, 'حافز') || str_contains($label, 'حوافز') || str_contains($label, 'incentive')) return 'incentive';
        if (str_contains($label, 'عمول') || str_contains($label, 'commission')) return 'commission';
        if (str_contains($label, 'أرباح') || str_contains($label, 'profit')) return 'profit_share';
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
