<!doctype html>
<html lang="ar" dir="rtl">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:0;background:#f4f7fc;font-family:Tahoma,'Segoe UI',Arial,sans-serif;direction:rtl;text-align:right;color:#1d3154">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fc;padding:28px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;table-layout:fixed;background:#ffffff;border:1px solid #e4ebf5;border-radius:16px;overflow:hidden">
<tr><td style="padding:26px 30px 8px;text-align:center"><img src="{{ asset('images/odir-hr-wordmark.png') }}" alt="Odir HR" width="132" style="border:0;height:auto"></td></tr>
<tr><td style="height:3px;background:#f5a524;font-size:0;line-height:0">&nbsp;</td></tr>
<tr><td style="padding:26px 30px 8px">
<h1 style="margin:0 0 14px;font-size:20px;line-height:1.7;color:#10264d">{{ $heading }}</h1>
@foreach ($lines as $line)<p style="margin:0 0 12px;font-size:15px;line-height:2;color:#44546f">{{ $line }}</p>@endforeach
</td></tr>
@if (!empty($actionUrl))
<tr><td style="padding:10px 30px 8px;text-align:center"><a href="{{ $actionUrl }}" style="display:inline-block;background:#1677f9;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;padding:13px 30px;border-radius:11px">{{ $actionText }}</a></td></tr>
<tr><td style="padding:14px 30px 4px;word-break:break-all"><p style="margin:0;font-size:12px;line-height:1.9;color:#8190a8">إذا لم يعمل الزر، انسخ هذا الرابط والصقه في المتصفح:</p><p style="margin:4px 0 0;font-size:12px;line-height:1.7;word-break:break-all;direction:ltr;text-align:left"><a href="{{ $actionUrl }}" style="color:#1677f9;word-break:break-all;overflow-wrap:anywhere">{{ $actionUrl }}</a></p></td></tr>
@endif
@if (!empty($footnote))
<tr><td style="padding:14px 30px 4px"><p style="margin:0;font-size:13px;line-height:1.9;color:#6e7d98">{{ $footnote }}</p></td></tr>
@endif
<tr><td style="padding:22px 30px 26px"><p style="margin:0;border-top:1px solid #eef2f8;padding-top:16px;font-size:12px;line-height:1.9;color:#8190a8">هذه رسالة تلقائية من اودير HR، من فضلك لا ترد عليها.<br>© 2026 اودير HR</p></td></tr>
</table></td></tr></table>
</body></html>
