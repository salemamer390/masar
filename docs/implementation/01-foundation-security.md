# المرحلة 1: Foundation والأمان — إعدادات Payroll

**الحالة:** منفذة جزئيًا  
**تاريخ التنفيذ:** 6 أكتوبر 2026

## الهدف المنفذ

تصحيح صفحة إعدادات الرواتب لتكون واجهة فعلية للمصدر الذي يستخدمه محرك الحساب، مع صلاحية مستقلة والتحقق من القيم وسجل تاريخي للتعديلات.

## المشكلة السابقة

كان `PayrollSettingsController` يحدّث جدول `payroll_settings` القديم، بينما `PayrollSettingsService` يقرأ من:

- `payroll_calculation_settings`
- `payroll_calculation_rules`
- `payroll_allowance_settings`

كما لم تكن للـ controller مسارات، وبالتالي لم تكن صفحة إعدادات الرواتب جزءًا تشغيليًا من التطبيق.

## ما نُفذ

1. أضيفت migration `2026_10_06_223000_create_payroll_setting_changes_table` ونُفذت بنجاح في batch 2.
2. أُنشئ جدول `payroll_setting_changes` للاحتفاظ بـ before/after snapshots، تاريخ السريان، المستخدم وعنوان IP.
3. أضيفت الصلاحية `payroll.settings` ومنحت لدوري `super_admin` و`payroll`.
4. أضيفت المسارات المحمية:
   - `GET /payroll/settings`
   - `PUT /payroll/settings`
5. أعيد بناء `PayrollSettingsController` ليقرأ ويكتب جداول الإعدادات الفعالة، داخل DB transaction.
6. أُنشئت واجهة [settings.blade.php](../../resources/views/payroll/settings.blade.php) لتعديل النسب والحدود ومعاملات الإضافي وتوزيع البدلات.
7. التحقق على الخادم يرفض مفاتيح إعدادات غير معتمدة، والقيم السالبة، وأي توزيع بدلات لا يساوي 100%.
8. كل حفظ يضيف Activity Log ونسخة تاريخية للإعدادات قبل وبعد التغيير.

## الضمانات الحالية

- صلاحية `payroll.settings` مطلوبة لكل عرض أو تعديل.
- لا تسمح الواجهة بتعديل حقول settings غير المدرجة في القائمة المعتمدة داخل controller.
- لا تقبل العملية أي تعديل جزئي لتوزيع البدلات؛ المعاملة كلها تتراجع عند فشل أي خطوة.
- السجل التاريخي لا يستبدل إعدادات Payroll snapshots الموجودة في دورات الرواتب.

## التحقق

- `php -l app/Http/Controllers/PayrollSettingsController.php`: ناجح.
- `php -l database/migrations/2026_10_06_223000_create_payroll_setting_changes_table.php`: ناجح.
- `php artisan migrate --pretend`: نجح قبل التطبيق.
- `php artisan migrate --force`: نجح.
- `php artisan route:list --name=payroll.settings`: أظهر مساري GET وPUT المحميين.

## أعمال لاحقة في المرحلة نفسها

- إضافة رابط الإعدادات إلى القائمة الجانبية بعد مراجعة ترميز قوالب الواجهة الحالية.
- تحويل الإعدادات من update-in-place إلى سجل إعدادات كامل versioned rows عند اعتماد سياسة business date النهائية.
- إكمال Audit Log ليحفظ old/new بقيم قابلة للاستعلام لكل العمليات الحساسة، وليس Snapshot إعدادات فقط.
- إضافة أدوات اختبار PHPUnit/Feature tests للـ validation والصلاحيات والمعاملات.

## تحديث: Audit Log التاريخي

أُضيفت migration `2026_10_06_224000_add_change_snapshots_to_activity_logs` ونُفذت بنجاح في batch 3.

- أضيفت الحقول `module` و`old_values` و`new_values` إلى `activity_logs`، مع فهرس على `module`.
- أصبح `ActivityLog::record()` يدعم snapshots اختيارية قبل/بعد للعملية مع تحديد الوحدة.
- تعديل بيانات الموظف أصبح يسجل Snapshot للقيم المعدلة قبل وبعد الحفظ.
- تم التحقق بـ `php -l` و`php artisan migrate --pretend` قبل التطبيق ثم `php artisan migrate --force` بعده.

**تنبيه تقني:** بقية العمليات الحساسة (الراتب، الاعتمادات، المستندات، الأدوار، الحضور) ستُنقل تدريجيًا إلى نفس صيغة snapshot أثناء تنفيذ وحداتها؛ لا تُعد تغطيتها مكتملة بعد.
