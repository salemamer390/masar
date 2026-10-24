<?php

return [
    // Internal Tazamun salary-structure rule.
    'allowances_rate' => 0.25,

    // Month-end job: generate, approve and lock every company's payroll without user action.
    'auto_close' => [
        'enabled' => false,
        // 0 = close on the last day of the month. N = close N days after month end
        // (use a grace period when fingerprint files are uploaded after month end).
        'days_after_month_end' => 0,
        'time' => '23:55',
    ],

    // Work on a weekly rest day or official holiday is paid only when an overtime request covers that date.
    'rest_work_requires_request' => true,

    'insurance' => [
        'employee_rate' => 0.11,
        'employer_rate' => 0.1875,
        'martyrs_rate' => 0.0005,
        'minimum_wage' => 2700.00,
        'ceiling' => 16700.00,
        'allowance_exclusion_options' => [30, 25, 20, 0],
    ],

    'tax' => [
        'personal_exemption' => 20000.00,
        // Nearest 0.50 is retained because it is the rounding used by the
        // approved calculator/reference results already used by Tazamun.
        'monthly_rounding' => 'half',

        'categories' => [
            'category_1' => ['label' => 'الفئة الأولى — حتى 600,000', 'max' => 600000.00],
            'category_2' => ['label' => 'الفئة الثانية — أكثر من 600,000 حتى 700,000', 'max' => 700000.00],
            'category_3' => ['label' => 'الفئة الثالثة — أكثر من 700,000 حتى 800,000', 'max' => 800000.00],
            'category_4' => ['label' => 'الفئة الرابعة — أكثر من 800,000 حتى 900,000', 'max' => 900000.00],
            'category_5' => ['label' => 'الفئة الخامسة — أكثر من 900,000 حتى 1,200,000', 'max' => 1200000.00],
            'category_6' => ['label' => 'الفئة السادسة — أكثر من 1,200,000', 'max' => null],
        ],

        'brackets' => [
            'category_1' => [
                ['from' => 0, 'to' => 40000, 'rate' => 0.00],
                ['from' => 40000, 'to' => 55000, 'rate' => 0.10],
                ['from' => 55000, 'to' => 70000, 'rate' => 0.15],
                ['from' => 70000, 'to' => 200000, 'rate' => 0.20],
                ['from' => 200000, 'to' => 400000, 'rate' => 0.225],
                ['from' => 400000, 'to' => INF, 'rate' => 0.25],
            ],
            'category_2' => [
                ['from' => 0, 'to' => 55000, 'rate' => 0.10],
                ['from' => 55000, 'to' => 70000, 'rate' => 0.15],
                ['from' => 70000, 'to' => 200000, 'rate' => 0.20],
                ['from' => 200000, 'to' => 400000, 'rate' => 0.225],
                ['from' => 400000, 'to' => INF, 'rate' => 0.25],
            ],
            'category_3' => [
                ['from' => 0, 'to' => 70000, 'rate' => 0.15],
                ['from' => 70000, 'to' => 200000, 'rate' => 0.20],
                ['from' => 200000, 'to' => 400000, 'rate' => 0.225],
                ['from' => 400000, 'to' => INF, 'rate' => 0.25],
            ],
            'category_4' => [
                ['from' => 0, 'to' => 200000, 'rate' => 0.20],
                ['from' => 200000, 'to' => 400000, 'rate' => 0.225],
                ['from' => 400000, 'to' => INF, 'rate' => 0.25],
            ],
            'category_5' => [
                ['from' => 0, 'to' => 400000, 'rate' => 0.225],
                ['from' => 400000, 'to' => INF, 'rate' => 0.25],
            ],
            'category_6' => [
                ['from' => 0, 'to' => 1200000, 'rate' => 0.25],
                ['from' => 1200000, 'to' => INF, 'rate' => 0.275],
            ],
        ],
    ],
];
