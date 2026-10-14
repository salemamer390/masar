# تنظيف الـ CSS والملفات اليتيمة

## اللي اتعمل
1. **دمج** `styles.css` + `ux.css` + `design-system.css` في ملف واحد: `public/app.css`
   - الترتيب (الـ cascade) متحفظ، واتشالت 348 خاصية مكررة كان آخر تعريف بيغطي عليها (من 1081 قاعدة لـ 956).
   - الـ layout الرئيسي وصفحة الدخول بقوا يحمّلوا `app.css` بس.
   - اتشال `@import` الخاص بخط Cairo لأن الـ layouts بتحمّله أصلًا بـ `<link>`.
2. **نقل لمجلد `_archive/`** (مش حذف) كل اللي مش مستخدم:
   - CSS/JS: `public/css/`, `public/js/`, `public/app.js`, `public/responsive.css`, `public/platform-admin.css`, ونسخ `styles.css`/`ux.css`/`design-system.css` الأصلية.
   - Views: صفحات `platform/*` و`layouts/platform`، المكوّنات `kpi-card`/`status-badge`/`icon`، المودالات الجزئية (`discipline`/`documents`/`leaves`)، `payroll/payslip`، `welcome`، `insurance/form`، ونسخة `s2-editable.blade - Copy.php`.
3. إزالة مكتبة `lucide@latest` (كانت مستخدمة في مكوّن `icon` اليتيم بس).
4. زرار القائمة الجانبية + شريط الأيقونات (من التعديل السابق) مدموجين في `app.css`.

## للرجوع عن أي حاجة
- أي ملف في `_archive/` ارجعه لمكانه بنفس المسار.
- لو عايز صفحة الدخول ترجع لشكلها القديم: في `resources/views/auth/login.blade.php` اربطها بـ `_archive/public/styles.css` (انسخه لـ `public/` وغيّر الـ link).

## اللي ما اتعملش
- إعادة تقسيم الـ CSS حسب المسؤولية (base/layout/components): بتغيّر ترتيب القواعد وبتحتاج اختبار بصري.
- استبدال Font Awesome في لوحة التحكم: `dashboard-reference.css` معتمد عليه بأكواد رموز في `::before/::after`.
- استضافة Bootstrap/الأيقونات/الخط محليًا وحذف الـ CSS الميت: محتاجين npm/Vite وإنترنت.
