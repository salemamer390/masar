<?php

namespace App\Support;

use App\Models\TourStep;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Step definitions for the first-login guided tour.
 *
 * The tour enters each page in the setup sequence Amer picked (2026-11-11):
 * settings, employees, salary structures, leaves, penalties, requests,
 * fingerprint devices, monthly summary, employee salaries, then the
 * dashboard. Steps are filtered by the same permissions that gate the
 * sidebar, so every user only tours pages they can actually open.
 *
 * Since the owner tour editor, the live steps are stored in the tour_steps
 * table (seeded from defaults() by the migration, so day one is identical
 * to the built-in tour). defaults() remains the fallback until the table
 * exists, so pages never break before the migration runs.
 */
class GuidedTour
{
    private static ?bool $tracked = null;

    private static ?bool $tableReady = null;

    /**
     * False until the tour columns are migrated; the tour then stays off
     * (and is never enforced) instead of breaking pages or locking users out.
     */
    public static function isTracked(): bool
    {
        return self::$tracked ??= Schema::hasColumn('users', 'tour_seen_at');
    }

    /**
     * False until the tour_steps table is migrated; stepsFor() then falls
     * back to the built-in definition below.
     */
    public static function tableReady(): bool
    {
        return self::$tableReady ??= Schema::hasTable('tour_steps');
    }

    public static function hasDraft(): bool
    {
        return self::tableReady() && TourStep::draft()->exists();
    }

    /**
     * The built-in tour definition: seed content for tour_steps and the
     * fallback until the table is migrated. Titles/bodies are the Arabic
     * source strings; other locales come from the translation files.
     *
     * @return array<int, array{key: string, route: ?string, target: ?array, icon: ?string, permission: ?string, system: bool, title: string, body: string}>
     */
    public static function defaults(): array
    {
        return [
            [
                'key' => 'welcome',
                'route' => null,
                'target' => null,
                'icon' => 'bi-stars',
                'permission' => null,
                'system' => true,
                'title' => 'أهلًا بك في اودير',
                'body' => 'جولة سريعة وإجبارية على أهم أجزاء النظام، نمر فيها على الصفحات بالترتيب ونوضح لك مكان كل صفحة في القائمة الجانبية. يمكنك إعادتها لاحقًا من قائمة المستخدم.',
            ],
            [
                'key' => 'settings',
                'route' => 'payroll.settings.index',
                'target' => ['.settings-cards', '.page-head'],
                'icon' => 'bi-gear-fill',
                'permission' => 'payroll.settings',
                'system' => false,
                'title' => 'الإعدادات',
                'body' => 'أول خطوة في تجهيز النظام: أوقات الدوام وقواعد الحساب وسياسات الإجازات والجزاءات، وكل الصفحات تعتمد عليها.',
            ],
            [
                'key' => 'employees',
                'route' => 'employees.index',
                'target' => ['[data-bs-target="#addEmployeeModal"]', '.page-head'],
                'icon' => 'bi-people-fill',
                'permission' => 'employees.view',
                'system' => false,
                'title' => 'الموظفون',
                'body' => 'أضف موظفيك من هنا: بياناتهم وعقودهم وملفاتهم، مع استيراد سريع من Excel.',
            ],
            [
                'key' => 'salary-structures',
                'route' => 'salaries.index',
                'target' => ['[data-bs-target="#salaryModal"]', '.page-head'],
                'icon' => 'bi-cash-stack',
                'permission' => 'salary.view',
                'system' => false,
                'title' => 'هياكل الرواتب',
                'body' => 'حدد هيكل الراتب لكل موظف من الراتب الإجمالي، والنظام يوزع البدلات ويحسب التأمين والضريبة والصافي تلقائيًا.',
            ],
            [
                'key' => 'leaves',
                'route' => 'leaves.index',
                'target' => ['[data-bs-target="#leaveModal"]', '.page-head'],
                'icon' => 'bi-calendar2-check-fill',
                'permission' => 'leaves.view',
                'system' => false,
                'title' => 'الإجازات',
                'body' => 'سجّل إجازات الموظفين من هنا؛ كل إجازة تنعكس فورًا على الملخص والرصيد.',
            ],
            [
                'key' => 'penalties',
                'route' => 'discipline.index',
                'target' => ['[data-bs-target="#penaltyModal"]', '.page-head'],
                'icon' => 'bi-exclamation-octagon-fill',
                'permission' => 'penalties.view',
                'system' => false,
                'title' => 'الجزاءات والمخالفات',
                'body' => 'سجّل الجزاءات والمخالفات وتابع أثرها المالي على الرواتب.',
            ],
            [
                'key' => 'requests',
                'route' => 'requests.index',
                'target' => ['#newRequestButton', '.page-head a[href*="create=1"]', '.page-head'],
                'icon' => 'bi-file-earmark-text-fill',
                'permission' => 'requests.view',
                'system' => false,
                'title' => 'الطلبات',
                'body' => 'من زر «طلب جديد» تسجّل طلبات السلف والأذونات والمأموريات والإضافي، وتتابعها من نفس الصفحة.',
            ],
            [
                'key' => 'fingerprint',
                'route' => 'fingerprint.devices.index',
                'target' => ['[data-bs-target="#addDeviceModal"]', '.page-head'],
                'icon' => 'bi-fingerprint',
                'permission' => 'fingerprint-devices.view',
                'system' => false,
                'title' => 'أجهزة البصمة',
                'body' => 'اربط أجهزة البصمة وتابع مزامنة سجلات الحضور منها أولًا بأول.',
            ],
            [
                'key' => 'monthly-summary',
                'route' => 'jobs.index',
                'target' => ['.monthly-summary-table', '.page-head'],
                'icon' => 'bi-calendar4-week',
                'permission' => 'employees.view',
                'system' => false,
                'title' => 'ملخص الشهر',
                'body' => 'كشف مجمع لكل موظف في الشهر: الحضور والإجازات والجزاءات والطلبات قبل احتساب الراتب.',
            ],
            [
                'key' => 'payroll',
                'route' => 'payroll.employee-salaries',
                'target' => ['#payrollViewTabs', '.employee-payroll-actions'],
                'icon' => 'bi-person-vcard',
                'permission' => 'payroll.view',
                'system' => false,
                'title' => 'رواتب الموظفين',
                'body' => 'نتائج مسير الرواتب وقسائم الراتب لكل موظف، مع التصدير والطباعة.',
            ],
            [
                'key' => 'dashboard',
                'route' => 'dashboard',
                'target' => ['.reference-kpi-grid', '.dashboard-command-bar'],
                'icon' => 'bi-speedometer2',
                'permission' => 'dashboard.view',
                'system' => false,
                'title' => 'لوحة التحكم',
                'body' => 'نظرتك اليومية: الحضور والموظفون وأهم ما يحتاج متابعة، من مكان واحد.',
            ],
            [
                'key' => 'closing',
                'route' => null,
                'target' => null,
                'icon' => 'bi-check-circle',
                'permission' => null,
                'system' => true,
                'title' => 'جاهز للبدء',
                'body' => 'انتهت الجولة. يمكنك إعادتها متى شئت من قائمة المستخدم بالأعلى.',
            ],
        ];
    }

    /**
     * @return array<int, array{key: string, url: ?string, target: ?array, title: string, body: string}>
     */
    public static function stepsFor(User $user, bool $draft = false): array
    {
        $stored = self::storedSteps($draft);

        if ($stored === null) {
            return collect(self::defaults())
                ->filter(fn (array $step) => self::visibleTo($step['permission'], $step['key'], $user))
                ->map(fn (array $step) => [
                    'key' => $step['key'],
                    'url' => $step['route'] ? route($step['route']) : null,
                    'target' => $step['target'],
                    'icon' => $step['icon'],
                    'title' => __($step['title']),
                    'body' => __($step['body']),
                ])
                ->values()
                ->all();
        }

        return $stored
            ->filter(fn (TourStep $step) => self::visibleTo($step->permission, $step->key, $user))
            ->map(fn (TourStep $step) => [
                'key' => $step->key,
                'url' => $step->route_name ? route($step->route_name) : null,
                'target' => $step->target,
                'icon' => $step->icon,
                'title' => self::localized($step->title_ar, $step->title_en),
                'body' => self::localized($step->body_ar, $step->body_en),
            ])
            ->values()
            ->all();
    }

    /**
     * Published steps (or the draft set when asked for it). Null when the
     * table is missing or holds no rows, so the caller falls back to the
     * built-in definition.
     */
    private static function storedSteps(bool $draft): ?Collection
    {
        if (! self::tableReady()) {
            return null;
        }

        $rows = TourStep::query()
            ->where('is_draft', $draft)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // A route from an older version may be gone; broken steps are
        // dropped here so the tour itself never fails on a page.
        $rows = $rows->filter(fn (TourStep $step) => $step->route_name === null || \Illuminate\Support\Facades\Route::has($step->route_name));

        return $rows->isEmpty() ? null : $rows->values();
    }

    private static function visibleTo(?string $permission, string $key, User $user): bool
    {
        if ($permission !== null && ! $user->hasPermission($permission)) {
            return false;
        }

        // The dashboard stop is management-only, same rule as the sidebar.
        return $key !== 'dashboard' || $user->role?->name !== 'employee';
    }

    private static function localized(string $arabic, ?string $english): string
    {
        return app()->getLocale() === 'en' && $english ? $english : $arabic;
    }
}
