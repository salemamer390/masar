<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>قسيمة راتب - {{ $record->employee?->full_name }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #24344d; font-family: Tahoma, Arial, sans-serif; }
        body { font-size: 13px; line-height: 1.7; }
        .sheet { width: 100%; max-width: 186mm; margin: 0 auto; }
        .brand { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; border-bottom: 2px solid #1769e0; padding-bottom: 14px; }
        h1 { margin: 0; color: #17365d; font-size: 25px; }
        .muted { color: #71809a; }
        .period { color: #17365d; font-weight: 700; white-space: nowrap; }
        .employee { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; padding: 16px 0; border-bottom: 1px solid #dfe7f1; }
        .label { color: #71809a; font-size: 11px; margin-bottom: 2px; }
        .value { font-weight: 700; overflow-wrap: anywhere; }
        .section { margin-top: 18px; break-inside: avoid; page-break-inside: avoid; }
        .section h2 { margin: 0 0 7px; padding-bottom: 6px; border-bottom: 1px solid #dfe7f1; font-size: 16px; }
        .earnings h2 { color: #087f5b; }
        .deductions h2 { color: #d63345; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        td { padding: 8px 10px; border-bottom: 1px solid #edf1f7; vertical-align: middle; }
        td:first-child { width: 68%; }
        td:last-child { width: 32%; text-align: left; direction: ltr; font-weight: 700; white-space: nowrap; }
        .total { display: flex; justify-content: space-between; margin-top: 9px; padding: 10px 12px; border-radius: 7px; font-weight: 700; }
        .earnings .total { color: #087f5b; background: #e9f8f1; }
        .deductions .total { color: #d63345; background: #fff0f2; }
        .net { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-top: 20px; padding: 16px; border: 1px solid #d7e7ff; border-radius: 9px; background: #eef5ff; break-inside: avoid; page-break-inside: avoid; }
        .net strong { color: #1769e0; font-size: 24px; direction: ltr; white-space: nowrap; }
        footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e1e8f0; color: #8290a4; font-size: 10px; text-align: center; }
        .print-actions { margin-top: 18px; text-align: center; }
        .print-actions button { border: 0; border-radius: 6px; padding: 9px 18px; background: #1769e0; color: #fff; cursor: pointer; font: inherit; }
        @media screen {
            body { background: #f4f7fc; padding: 24px; }
            .sheet { max-width: 760px; padding: 28px; background: #fff; border-radius: 12px; box-shadow: 0 8px 28px #142b5514; }
        }
        @media print {
            html, body { width: 100%; background: #fff; }
            .sheet { max-width: none; padding: 0; }
            .print-actions { display: none !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body>
<main class="sheet">
    <header class="brand">
        <div><h1>MASAR HR</h1><div class="muted">قسيمة راتب الموظف</div></div>
        <div class="period">{{ $record->payroll_month?->translatedFormat('F Y') }}</div>
    </header>

    <section class="employee">
        <div><div class="label">اسم الموظف</div><div class="value">{{ $record->employee?->full_name ?? '—' }}</div></div>
        <div><div class="label">الكود الوظيفي</div><div class="value">{{ $record->employee?->employee_code ?? '—' }}</div></div>
        <div><div class="label">القسم / الوظيفة</div><div class="value">{{ collect([$record->employee?->department?->name, $record->employee?->job?->title])->filter()->implode(' — ') ?: '—' }}</div></div>
    </section>

    <section class="section earnings">
        <h2>الاستحقاقات</h2>
        <table><tbody>
        @foreach($earnings as [$label, $amount])
            <tr><td>{{ $label }}</td><td>{{ number_format($amount, 2) }} ج.م</td></tr>
        @endforeach
        </tbody></table>
        <div class="total"><span>إجمالي الاستحقاقات</span><span>{{ number_format($earningsTotal, 2) }} ج.م</span></div>
    </section>

    <section class="section deductions">
        <h2>الاستقطاعات</h2>
        <table><tbody>
        @forelse($deductions as [$label, $amount])
            <tr><td>{{ $label }}</td><td>{{ number_format($amount, 2) }} ج.م</td></tr>
        @empty
            <tr><td colspan="2" style="text-align:center;color:#71809a">لا توجد استقطاعات</td></tr>
        @endforelse
        </tbody></table>
        <div class="total"><span>إجمالي الاستقطاعات</span><span>{{ number_format($deductionsTotal, 2) }} ج.م</span></div>
    </section>

    <div class="net"><span>صافي الراتب المستحق</span><strong>{{ number_format($net, 2) }} ج.م</strong></div>
    <footer>تم إنشاء هذه القسيمة بواسطة MASAR HR</footer>
    <div class="print-actions"><button type="button" onclick="window.print()">طباعة / حفظ PDF</button></div>
</main>
<script>window.addEventListener('load', () => { if (new URLSearchParams(location.search).get('print') === '1') window.print(); });</script>
</body>
</html>
