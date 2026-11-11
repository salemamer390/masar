<?php

namespace App\Support;

use App\Models\User;

/**
 * Step definitions for the first-login guided tour.
 *
 * The tour enters each page in the setup sequence Amer picked (2026-11-11):
 * settings, employees, salary structures, leaves, penalties, requests,
 * fingerprint devices, monthly summary, employee salaries, then the
 * dashboard. Steps are filtered by the same permissions that gate the
 * sidebar, so every user only tours pages they can actually open.
 */
class GuidedTour
{
    private static ?bool $tracked = null;

    /**
     * False until the tour columns are migrated; the tour then stays off
     * (and is never enforced) instead of breaking pages or locking users out.
     */
    public static function isTracked(): bool
    {
        return self::$tracked ??= \Illuminate\Support\Facades\Schema::hasColumn('users', 'tour_seen_at');
    }

    /**
     * @return array<int, array{key: string, url: ?string, target: ?string, title: string, body: string}>
     */
    public static function stepsFor(User $user): array
    {
        $can = fn (string $permission) => $user->hasPermission($permission);

        $steps = [[
            'key' => 'welcome',
            'url' => null,
            'target' => null,
            'icon' => 'bi-stars',
            'title' => __('أهلًا بك في مسار'),
            'body' => __('جولة سريعة وإجبارية على أهم أجزاء النظام، نمر فيها على الصفحات بالترتيب ونوضح لك مكان كل صفحة في القائمة الجانبية. يمكنك إعادتها لاحقًا من قائمة المستخدم.'),
        ]];

        $pages = [
            [
                'key' => 'settings',
                'icon' => 'bi-gear-fill',
                'permission' => 'payroll.settings',
                'route' => 'payroll.settings.index',
                'target' => ['.settings-cards', '.page-head'],
                'title' => __('الإعدادات'),
                'body' => __('أول خطوة في تجهيز النظام: أوقات الدوام وقواعد الحساب وسياسات الإجازات والجزاءات، وكل الصفحات تعتمد عليها.'),
            ],
            [
                'key' => 'employees',
                'icon' => 'bi-people-fill',
                'permission' => 'employees.view',
                'route' => 'employees.index',
                'target' => ['[data-bs-target="#addEmployeeModal"]', '.page-head'],
                'title' => __('الموظفون'),
                'body' => __('أضف موظفيك من هنا: بياناتهم وعقودهم وملفاتهم، مع استيراد سريع من Excel.'),
            ],
            [
                'key' => 'salary-structures',
                'icon' => 'bi-cash-stack',
                'permission' => 'salary.view',
                'route' => 'salaries.index',
                'target' => ['[data-bs-target="#salaryModal"]', '.page-head'],
                'title' => __('هياكل الرواتب'),
                'body' => __('حدد هيكل الراتب لكل موظف من الراتب الإجمالي، والنظام يوزع البدلات ويحسب التأمين والضريبة والصافي تلقائيًا.'),
            ],
            [
                'key' => 'leaves',
                'icon' => 'bi-calendar2-check-fill',
                'permission' => 'leaves.view',
                'route' => 'leaves.index',
                'target' => ['[data-bs-target="#leaveModal"]', '.page-head'],
                'title' => __('الإجازات'),
                'body' => __('سجّل إجازات الموظفين من هنا؛ كل إجازة تنعكس فورًا على الملخص والرصيد.'),
            ],
            [
                'key' => 'penalties',
                'icon' => 'bi-exclamation-octagon-fill',
                'permission' => 'penalties.view',
                'route' => 'discipline.index',
                'target' => ['[data-bs-target="#penaltyModal"]', '.page-head'],
                'title' => __('الجزاءات والمخالفات'),
                'body' => __('سجّل الجزاءات والمخالفات وتابع أثرها المالي على الرواتب.'),
            ],
            [
                'key' => 'requests',
                'icon' => 'bi-file-earmark-text-fill',
                'permission' => 'requests.view',
                'route' => 'requests.index',
                'target' => ['#newRequestButton', '.page-head a[href*="create=1"]', '.page-head'],
                'title' => __('الطلبات'),
                'body' => __('من زر «طلب جديد» تسجّل طلبات السلف والأذونات والمأموريات والإضافي، وتتابعها من نفس الصفحة.'),
            ],
            [
                'key' => 'fingerprint',
                'icon' => 'bi-fingerprint',
                'permission' => 'fingerprint-devices.view',
                'route' => 'fingerprint.devices.index',
                'target' => ['[data-bs-target="#addDeviceModal"]', '.page-head'],
                'title' => __('أجهزة البصمة'),
                'body' => __('اربط أجهزة البصمة وتابع مزامنة سجلات الحضور منها أولًا بأول.'),
            ],
            [
                'key' => 'monthly-summary',
                'icon' => 'bi-calendar4-week',
                'permission' => 'employees.view',
                'route' => 'jobs.index',
                'target' => ['.monthly-summary-table', '.page-head'],
                'title' => __('ملخص الشهر'),
                'body' => __('كشف مجمع لكل موظف في الشهر: الحضور والإجازات والجزاءات والطلبات قبل احتساب الراتب.'),
            ],
            [
                'key' => 'payroll',
                'icon' => 'bi-person-vcard',
                'permission' => 'payroll.view',
                'route' => 'payroll.employee-salaries',
                'target' => ['#payrollViewTabs', '.employee-payroll-actions'],
                'title' => __('رواتب الموظفين'),
                'body' => __('نتائج مسير الرواتب وقسائم الراتب لكل موظف، مع التصدير والطباعة.'),
            ],
            [
                'key' => 'dashboard',
                'icon' => 'bi-speedometer2',
                'permission' => 'dashboard.view',
                'route' => 'dashboard',
                'target' => ['.reference-kpi-grid', '.dashboard-command-bar'],
                'title' => __('لوحة التحكم'),
                'body' => __('نظرتك اليومية: الحضور والموظفون وأهم ما يحتاج متابعة، من مكان واحد.'),
            ],
        ];

        foreach ($pages as $page) {
            $isDashboard = $page['key'] === 'dashboard';
            if ($can($page['permission']) && (! $isDashboard || $user->role?->name !== 'employee')) {
                $steps[] = [
                    'key' => $page['key'],
                    'url' => route($page['route']),
                    'target' => $page['target'],
                    'icon' => $page['icon'],
                    'title' => $page['title'],
                    'body' => $page['body'],
                ];
            }
        }

        $steps[] = [
            'key' => 'closing',
            'url' => null,
            'target' => null,
            'icon' => 'bi-check-circle',
            'title' => __('جاهز للبدء'),
            'body' => __('انتهت الجولة. يمكنك إعادتها متى شئت من قائمة المستخدم بالأعلى.'),
        ];

        return $steps;
    }
}
