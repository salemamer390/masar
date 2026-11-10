<?php

namespace App\Support;

/**
 * Read-only reference of Egyptian Labour Law No. 14 of 2025 leave minimums
 * (arts. 54, 57, 124, 126, 128, 130, 132), used only to COMPARE what a company
 * chose. It never changes a stored value and never calls a lower value legal:
 * a value below the reference is shown as "below", and a comparison that
 * cannot be completed is shown as "review", never as a pass.
 */
final class LeaveLegalReference
{
    public const OK = 'ok';
    public const BELOW = 'below';
    public const REVIEW = 'review';
    public const OUTSIDE = 'outside';

    public const LABELS = [
        self::OK => 'مطابق للقانون',
        self::BELOW => 'غير مطابق للقانون',
        self::REVIEW => 'مطابق للقانون', // review لا يصل للواجهة؛ يُعامَل كمطابق مع ملاحظة تشغيلية
        self::OUTSIDE => 'نوع خاص بالشركة',
    ];

    /** Bootstrap colour per status. */
    public const CLASSES = [
        self::OK => 'success',
        self::BELOW => 'danger',
        self::REVIEW => 'success',
        self::OUTSIDE => 'secondary',
    ];

    /**
     * Company policy keys that have a statutory reference.
     * dir "min": the company value must be at least the reference (more is better for the employee).
     * dir "max": the company value must be at most the reference (a stricter, higher requirement is worse).
     */
    public static function policyRules(): array
    {
        return [
            'annual_first_year' => ['label' => 'رصيد السنة الأولى', 'article' => 124, 'ref' => 15, 'dir' => 'min', 'unit' => 'يوم'],
            'annual_second_year' => ['label' => 'رصيد ما بعد السنة الأولى', 'article' => 124, 'ref' => 21, 'dir' => 'min', 'unit' => 'يوم'],
            'annual_long_service' => ['label' => 'رصيد 10 سنوات / تجاوز 50 سنة', 'article' => 124, 'ref' => 30, 'dir' => 'min', 'unit' => 'يوم'],
            'annual_disability_days' => ['label' => 'رصيد ذوي الإعاقة والأقزام', 'article' => 124, 'ref' => 45, 'dir' => 'min', 'unit' => 'يوم'],
            'annual_wait_months' => ['label' => 'مدة الانتظار', 'article' => 124, 'ref' => 6, 'dir' => 'max', 'unit' => 'شهر'],
            'annual_hazardous_extra_days' => ['label' => 'إضافة العمل الخطر أو النائي', 'article' => 124, 'ref' => 7, 'dir' => 'min', 'unit' => 'يوم'],
            'annual_long_service_years' => ['label' => 'سنوات الخدمة للرصيد الأعلى', 'article' => 124, 'ref' => 10, 'dir' => 'max', 'unit' => 'سنة'],
            'annual_age_threshold' => ['label' => 'السن للرصيد الأعلى (تجاوز)', 'article' => 124, 'ref' => 50, 'dir' => 'max', 'unit' => 'سنة'],
            'casual_max_days_year' => ['label' => 'حد العارضة السنوي', 'article' => 128, 'ref' => 7, 'dir' => 'min', 'unit' => 'يوم'],
            'casual_max_days_request' => ['label' => 'حد العارضة للطلب الواحد', 'article' => 128, 'ref' => 2, 'dir' => 'min', 'unit' => 'يوم'],
            'maternity_months' => ['label' => 'مدة إجازة الوضع (شهر تقويمي)', 'article' => 54, 'ref' => 4, 'dir' => 'min', 'unit' => 'شهر'],
            'maternity_min_postpartum_days' => ['label' => 'الحد الأدنى بعد الولادة', 'article' => 54, 'ref' => 45, 'dir' => 'min', 'unit' => 'يوم'],
            'childcare_max_years_per_request' => ['label' => 'رعاية الطفل (سنة تقويمية للطلب)', 'article' => 57, 'ref' => 2, 'dir' => 'min', 'unit' => 'سنة'],
            'childcare_min_gap_years' => ['label' => 'الفاصل بين إجازات رعاية الطفل', 'article' => 57, 'ref' => 2, 'dir' => 'max', 'unit' => 'سنة'],
            'exam_notice_days' => ['label' => 'مهلة إخطار إجازة الامتحانات', 'article' => 126, 'ref' => 10, 'dir' => 'max', 'unit' => 'يوم'],
            'contagious_max_months' => ['label' => 'مدة المنع للمخالطة (حد أقصى قانوني)', 'article' => 132, 'ref' => 3, 'dir' => 'cap', 'unit' => 'شهر'],
            // مراحل تعويض المرضية للمنشآت الصناعية (م131): تُحفظ لكل شركة وتُقارن بالمرجع؛ الاحتساب في المسير ينتظر تحديد أساس الأجر وآلية الصرف.
            'sick_phase1_months' => ['label' => 'المنشآت الصناعية: المرحلة 1', 'article' => 131, 'ref' => 3, 'dir' => 'min', 'unit' => 'شهر'],
            'sick_phase1_pct' => ['label' => 'نسبة الأجر في المرحلة 1', 'article' => 131, 'ref' => 100, 'dir' => 'min', 'unit' => '%'],
            'sick_phase2_months' => ['label' => 'المرحلة 2', 'article' => 131, 'ref' => 6, 'dir' => 'min', 'unit' => 'شهر'],
            'sick_phase2_pct' => ['label' => 'نسبة الأجر في المرحلة 2', 'article' => 131, 'ref' => 85, 'dir' => 'min', 'unit' => '%'],
            'sick_phase3_months' => ['label' => 'المرحلة 3', 'article' => 131, 'ref' => 3, 'dir' => 'min', 'unit' => 'شهر'],
            'sick_phase3_pct' => ['label' => 'نسبة الأجر في المرحلة 3', 'article' => 131, 'ref' => 75, 'dir' => 'min', 'unit' => '%'],
            'sick_cycle_years' => ['label' => 'تتكرر المراحل عن كل', 'article' => 131, 'ref' => 3, 'dir' => 'max', 'unit' => 'سنوات خدمة'],
            'sick_termination_notice_days' => ['label' => 'إخطار قبل إنهاء العقد بسبب المرض', 'article' => 131, 'ref' => 15, 'dir' => 'min', 'unit' => 'يوم'],
            // حقوق واردة في القانون لكنها محفوظة للعرض فقط حاليًا (لا تدخل في الحساب).
            // dir "bool": القيمة 1 هي المطابقة للمرجع، و0 منح أوسع (قرار الشركة).
            // بدء التخفيض بالشهر مرجع فقط: التخفيض يبدأ بتاريخ موثق من الشهادة
            // الطبية على إجازة الوضع، والاحتساب بالشهر تقدير لم يُعتمد بعد.
            'annual_prorate_first_year' => ['label' => 'احتساب السنة الأولى نسبيًا', 'article' => 124, 'ref' => 1, 'dir' => 'bool', 'unit' => ''],
            'pregnancy_reduced_hours_from_month' => ['label' => 'بدء تخفيض ساعات الحامل (الشهر)', 'article' => 54, 'ref' => 6, 'dir' => 'max', 'unit' => 'شهر'],
            // مفاتيح مطبّقة فعلًا في الحساب (تعويض التأخير/الانصراف المبكر ومنع الإضافي).
            'pregnancy_reduced_hours_per_day' => ['label' => 'تخفيض ساعات الحامل يوميًا', 'article' => 54, 'ref' => 1, 'dir' => 'min', 'unit' => 'ساعة'],
            'pregnancy_overtime_protection_months' => ['label' => 'حماية منع الإضافي بعد الوضع (شهر)', 'article' => 54, 'ref' => 6, 'dir' => 'min', 'unit' => 'شهر'],
            'nursing_window_months' => ['label' => 'مدة فترة الرضاعة من الوضع (شهر)', 'article' => 56, 'ref' => 24, 'dir' => 'min', 'unit' => 'شهر'],
            'nursing_sessions_per_day' => ['label' => 'فترات الرضاعة يوميًا', 'article' => 56, 'ref' => 2, 'dir' => 'min', 'unit' => 'فترة'],
            'nursing_minutes_per_session' => ['label' => 'مدة فترة الرضاعة (دقيقة)', 'article' => 56, 'ref' => 30, 'dir' => 'min', 'unit' => 'دقيقة'],
        ];
    }

    /**
     * Per leave type code: article, one-line reference, field comparisons and
     * whether the law expects a document.
     * Fields map to leave type limits: dir min / max as in policyRules().
     */
    public static function typeRules(): array
    {
        return [
            'annual' => ['article' => 124, 'text' => '15 يوم السنة الأولى، 21 من الثانية، 30 بعد 10 سنوات أو تجاوز 50 سنة، 45 لذوي الإعاقة، +7 للعمل الخطر', 'fields' => [], 'paid' => true, 'document' => false],
            'casual' => ['article' => 128, 'text' => '7 أيام في السنة، يومان في المرة، وتُحسب من السنوية', 'fields' => [], 'paid' => true, 'document' => false],
            'maternity' => ['article' => 54, 'text' => '4 أشهر تشمل ما قبل الوضع وبعده، لا تقل عن 45 يومًا بعد الوضع، بأجر، حتى 3 مرات، بشهادة طبية', 'fields' => ['max_occurrences_service' => ['ref' => 3, 'dir' => 'min']], 'paid' => true, 'document' => true],
            'childcare' => ['article' => 57, 'text' => 'دون أجر حتى سنتين، منشأة 50 عاملًا فأكثر، بعد سنة خدمة، حتى 3 مرات، وفاصل سنتين', 'fields' => ['min_service_months' => ['ref' => 12, 'dir' => 'max'], 'max_occurrences_service' => ['ref' => 3, 'dir' => 'min']], 'paid' => false, 'document' => false],
            'newborn' => ['article' => 128, 'text' => 'يوم الولادة بأجر، لا يُحسب من السنوية، حتى 3 مرات', 'fields' => ['max_days_per_request' => ['ref' => 1, 'dir' => 'min'], 'max_occurrences_service' => ['ref' => 3, 'dir' => 'min']], 'paid' => true, 'document' => false],
            'pilgrimage' => ['article' => 130, 'text' => 'شهر بأجر مرة واحدة بعد 5 سنوات خدمة متصلة', 'fields' => ['min_service_months' => ['ref' => 60, 'dir' => 'max'], 'max_occurrences_service' => ['ref' => 1, 'dir' => 'min']], 'paid' => true, 'document' => false],
            'exams' => ['article' => 126, 'text' => 'بأجر عن أيام الامتحان الفعلية، لا تُحسب من السنوية، بإخطار قبلها بـ10 أيام وإثبات دخول الامتحان', 'fields' => [], 'paid' => true, 'document' => true],
            'contagious_contact' => ['article' => 132, 'text' => 'بقرار الجهة الطبية المختصة، بما لا يجاوز 3 أشهر. القانون لا يحدد الأجر في هذه المادة', 'fields' => [], 'paid' => null, 'document' => true],
            'sick' => ['article' => 131, 'text' => 'تحددها الجهة الطبية، وتعويض الأجر وفق قانون التأمينات الاجتماعية', 'fields' => [], 'paid' => null, 'document' => true],
            'unpaid' => ['article' => null, 'text' => 'لا يوجد حد قانوني للإجازة بدون أجر في مواد الإجازات', 'fields' => [], 'paid' => null, 'document' => false],
        ];
    }

    public static function compare(float|int|string|null $value, float|int $ref, string $dir): string
    {
        if ($value === null || $value === '') return self::REVIEW;
        $value = (float) $value;
        return match ($dir) {
            'min' => $value >= (float) $ref ? self::OK : self::BELOW,
            'max' => $value <= (float) $ref ? self::OK : self::BELOW,
            // المرجع يتطلب التفعيل (1)؛ القيمة 0 هنا منح أوسع من المرجع وليست نقصًا
            'bool' => $value === (float) $ref ? self::OK : self::REVIEW,
            // statutory ceiling on how long someone may be kept away: a lower number is never a reduction of a right
            'cap' => $value === (float) $ref ? self::OK : self::REVIEW,
            default => self::REVIEW,
        };
    }

    public static function policyStatus(string $key, float|int|string|null $value): string
    {
        $rule = self::policyRules()[$key] ?? null;
        return $rule ? self::compare($value, $rule['ref'], $rule['dir']) : self::OUTSIDE;
    }

    public static function policyReference(string $key): ?array
    {
        return self::policyRules()[$key] ?? null;
    }

    /** Human wording of one policy rule, e.g. "المرجع (م124): 15 يوم على الأقل". */
    public static function policyHint(string $key): string
    {
        $r = self::policyRules()[$key] ?? null;
        if (!$r) return '';
        $ref = rtrim(rtrim(number_format((float) $r['ref'], 2, '.', ''), '0'), '.');
        if ($r['dir'] === 'bool') return "المرجع (م{$r['article']}): بنسبة المدة (1). القيمة 0 تمنح الرصيد كاملًا بعد الانتظار، وهو منح أوسع من المرجع (قرار الشركة).";
        $bound = match ($r['dir']) { 'min' => 'على الأقل', 'max' => 'على الأكثر', default => 'حد أقصى' };
        return "المرجع (م{$r['article']}): {$ref} {$r['unit']} {$bound}";
    }

    /**
     * Status of one leave type for a company.
     * @param array $limits effective limits (max_days_per_request, max_days_per_year, min_service_months, max_occurrences_service)
     * @return array{status:string,notes:string[]}
     */
    public static function typeStatus(?string $code, array $limits, ?bool $isPaid, bool $requiresDocument, array $policies = []): array
    {
        $rules = $code ? (self::typeRules()[$code] ?? null) : null;
        if (!$rules) return ['status' => self::OUTSIDE, 'notes' => ['نوع خاص بالشركة، لا يوجد له مرجع قانوني للمقارنة.']];
        $below = []; $review = [];
        foreach ($rules['fields'] as $field => $r) {
            $value = $limits[$field] ?? null;
            // a missing limit means "no limit" for a ceiling and "no minimum" for a requirement: nothing to reduce
            if ($value === null && in_array($field, ['min_service_months'], true)) continue;
            if ($value === null) { $review[] = self::fieldLabel($field).': غير محدد، المقارنة غير مكتملة.'; continue; }
            if (self::compare($value, $r['ref'], $r['dir']) === self::BELOW) {
                $below[] = self::fieldLabel($field).': '.self::num($value).' مقابل المرجع '.self::num($r['ref']).' (م'.$rules['article'].').';
            }
        }
        if ($rules['paid'] === true && $isPaid === false) $below[] = 'الإجازة غير مدفوعة بينما المرجع (م'.$rules['article'].') يجعلها بأجر.';
        if ($rules['document'] && !$requiresDocument) $review[] = 'المرجع يتوقع مستندًا لهذه الإجازة، والإعداد الحالي لا يطلبه (قرار الشركة).';
        if ($rules['paid'] === null && $code === 'contagious_contact') $review[] = 'القانون لا يحدد الأجر في م132؛ يُحدد وفق لائحة الشركة.';
        if ($code === 'annual') {
            foreach (['annual_first_year', 'annual_second_year', 'annual_long_service', 'annual_disability_days', 'annual_hazardous_extra_days', 'annual_long_service_years', 'annual_age_threshold'] as $k) {
                if (isset($policies[$k]) && self::policyStatus($k, $policies[$k]) === self::BELOW) $below[] = self::policyRules()[$k]['label'].': '.self::num($policies[$k]).' مقابل المرجع '.self::num(self::policyRules()[$k]['ref']).'.';
            }
            if ((float) ($policies['annual_prorate_first_year'] ?? 0) === 0.0) $review[] = 'السنة الأولى: 15 يومًا كاملة بعد مدة الانتظار بدون تناسب (قرار الشركة؛ المرجع م124 ينص على التناسب).';
        }
        if ($code === 'casual') {
            foreach (['casual_max_days_year', 'casual_max_days_request'] as $k) {
                if (isset($policies[$k]) && self::policyStatus($k, $policies[$k]) === self::BELOW) $below[] = self::policyRules()[$k]['label'].': '.self::num($policies[$k]).' مقابل المرجع '.self::num(self::policyRules()[$k]['ref']).'.';
            }
        }
        if ($code === 'maternity') self::policyNotes(['maternity_months', 'maternity_min_postpartum_days'], $policies, $below);
        if ($code === 'childcare') self::policyNotes(['childcare_max_years_per_request', 'childcare_min_gap_years'], $policies, $below);
        if ($code === 'exams') self::policyNotes(['exam_notice_days'], $policies, $below);
        if ($code === 'contagious_contact') {
            if (isset($policies['contagious_max_months']) && self::policyStatus('contagious_max_months', $policies['contagious_max_months']) === self::REVIEW) $review[] = 'المدة المحددة أكبر من الحد القانوني الأقصى (3 أشهر).';
        }
        if ($below) return ['status' => self::BELOW, 'notes' => array_merge($below, $review)];
        // بلا وسم مراجعة: ما ليس أقل من المرجع فهو مطابق، وتظهر فروق المنح أو عدم اكتمال المقارنة كملاحظات فقط.
        return ['status' => self::OK, 'notes' => $review];
    }

    private static function policyNotes(array $keys, array $policies, array &$below): void
    {
        foreach ($keys as $k) {
            if (isset($policies[$k]) && self::policyStatus($k, $policies[$k]) === self::BELOW) {
                $r = self::policyRules()[$k];
                $below[] = $r['label'].': '.self::num($policies[$k]).' مقابل المرجع '.self::num($r['ref']).' (م'.$r['article'].').';
            }
        }
    }

    public static function fieldLabel(string $field): string
    {
        return [
            'max_days_per_request' => 'الحد في الطلب', 'max_days_per_year' => 'الحد السنوي',
            'min_service_months' => 'الحد الأدنى للخدمة (شهر)', 'max_occurrences_service' => 'مرات طوال الخدمة',
        ][$field] ?? $field;
    }

    public static function num(float|int|string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
