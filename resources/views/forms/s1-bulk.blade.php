<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طباعة جميع نماذج س1</title>
    <style>
        .bulk-tools { width: 210mm; margin: 12px auto; display: flex; gap: 8px; direction: rtl; }
        .bulk-tools button, .bulk-tools a { padding: 9px 16px; border: 0; border-radius: 5px; background: #145ec8; color: #fff; font: inherit; cursor: pointer; text-decoration: none; }
        .bulk-tools a { background: #fff; color: #19345b; border: 1px solid #c8d2df; }
        .s1-bulk .tools { display: none !important; }
        .empty-state { width: 210mm; margin: 32px auto; padding: 24px; background: #fff; text-align: center; font-family: Tahoma, Arial, sans-serif; }
        @media print { .bulk-tools { display: none !important; } }
    </style>
</head>
<body>
    <div class="bulk-tools">
        <button type="button" onclick="window.print()">طباعة جميع النماذج</button>
        <a href="{{ route('insurance.index') }}">العودة للتأمينات</a>
    </div>

    @forelse($forms as $d)
        <div class="s1-bulk">@include('forms.s1-editable', ['d' => $d, 'bulk' => true])</div>
    @empty
        <div class="empty-state">لا يوجد موظفون لإنشاء نماذج س1.</div>
    @endforelse
</body>
</html>
