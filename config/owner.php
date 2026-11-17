<?php

return [
    /*
    | الحسابات المالكة للمنصة المسموح لها بفتح لوحة تحكم المالك الخاصة.
    | تُضبط من متغير البيئة OWNER_EMAILS على السيرفر فقط (قائمة مفصولة
    | بفواصل). لا توجد قيمة افتراضية داخل الكود: إن لم يُضبط المتغير تبقى
    | اللوحة مغلقة تمامًا وتجيب 404 أمام الجميع بما فيهم مديرو المنصة.
    */
    'emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OWNER_EMAILS', ''))
    ))),

    /*
    | قائمة التحديثات المسموح بها من صفحة المالك. كل تحديث مستقبلي يحتاج
    | مراجعة صريحة وتسجيل بصمة SHA-256 لملفه في ملف المراجعة المشار إليه.
    */
    'reviewed_migrations_path' => env(
        'OWNER_REVIEWED_MIGRATIONS_PATH',
        database_path('owner-reviewed-migrations.json')
    ),
];
