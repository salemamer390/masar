# المرحلة 6: محركات التأمين والضريبة

**الحالة:** منفذة جزئيًا  
**تاريخ التنفيذ:** 7 أكتوبر 2026

## ما نُفذ

1. تم توحيد `InsuranceEngine` مع `PayrollSettingsService` عند توفر جداول الإعدادات، ليقرأ نسب الموظف وصاحب العمل وصندوق الشهداء والحدود التأمينية من المصدر نفسه الذي تعدله صفحة الإعدادات.
2. تم تعديل `PayrollEngine` لقراءة صندوق الشهداء من `PayrollSettingsService` بدل `config`.
3. أُنشئ جدول `insurance_record_history` عبر migration `2026_10_07_003000_create_insurance_record_history`، ونُفذ بنجاح في batch 8.
4. كل إنشاء أو تعديل لسجل التأمين يضيف Snapshot قبل/بعد، تاريخ السريان، المستخدم المنفذ، ومراجع الموظف والسجل التأميني.
5. تتم مزامنة الأجر التأميني في هياكل الراتب بعد إنشاء أو تعديل سجل التأمين.
6. أصبحت بيانات سجل التأمين تدعم تاريخ الانتهاء، الأجر التأميني، والملاحظات في validation.

## التحقق

- فحص PHP ناجح لـ InsuranceEngine وPayrollEngine وInsuranceController وmigration.
- `php artisan migrate --pretend` نجح قبل التطبيق.
- `php artisan migrate --force` نجح؛ migration مسجلة في batch 8.

## أعمال متبقية

- ربط أكواد الوظائف التأمينية من Master Data بدل حقل نصي حر.
- تطبيق effective-date selection عند حساب راتب شهر تاريخي، بدل الاعتماد على السجل الحالي وحده.
- إضافة Tax Year/Reconciliation workflow وتثبيت إعدادات السنة ضمن Payroll snapshots.
- توفير اختبارات رقمية للحد الأدنى/الأقصى والحالات اليدوية وعدم التأمين والشرائح المختلفة.

