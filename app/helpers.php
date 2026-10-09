<?php

if (! function_exists('format_number')) {
    /**
     * Formats a numeric value for people without changing its stored value.
     * Trailing fractional zeroes are removed, while meaningful decimals remain.
     */
    function format_number(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return '';
            }

            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        }

        $number = trim(str_replace(',', '', (string) $value));
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $number, $matches)) {
            return (string) $value;
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = isset($matches[3]) ? rtrim($matches[3], '0') : '';
        $sign = $matches[1] === '-' && ($integer !== '0' || $fraction !== '') ? '-' : '';

        return $sign.$integer.($fraction !== '' ? '.'.$fraction : '');
    }
}

if (! function_exists('format_money')) {
    /**
     * Presentation-only money formatter: nearest EGP with thousands grouping.
     * Database decimals and all calculator inputs remain untouched.
     */
    function format_money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $number = is_numeric($value) ? (float) $value : null;
        if ($number === null || ! is_finite($number)) {
            return (string) $value;
        }

        return number_format(round($number, 0, PHP_ROUND_HALF_UP), 0, '.', ',');
    }
}

if (! function_exists('arabic_label')) {
    /** Translate internal system values for display without changing stored data. */
    function arabic_label(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return [
            'Active' => 'نشط', 'Inactive' => 'غير نشط', 'Pending' => 'قيد الانتظار',
            'Manager Approved' => 'بانتظار اعتماد الموارد البشرية', 'Approved' => 'معتمد',
            'Rejected' => 'مرفوض', 'Draft' => 'مسودة', 'Locked' => 'مقفل',
            'Paid' => 'تم الصرف', 'Required' => 'مطلوب', 'Completed' => 'مكتمل',
            'Under Review' => 'قيد المراجعة', 'Present' => 'حاضر', 'Late' => 'متأخر',
            'Absent' => 'غائب', 'Leave' => 'إجازة', 'On Leave' => 'في إجازة',
            'manual' => 'إدخال يدوي', 'device' => 'جهاز حضور', 'api' => 'تكامل نظامي',
            'Gross' => 'الراتب الإجمالي', 'Net' => 'الراتب الصافي',
        ][$value] ?? $value;
    }
}

if (! function_exists('ui_theme')) {
    /** Central semantic registry for reusable icons, colors and labels. */
    function ui_theme(string $key): array
    {
        return [
            'employees' => ['tone' => 'blue', 'icon' => 'users'],
            'active' => ['tone' => 'green', 'icon' => 'user-check'],
            'departments' => ['tone' => 'purple', 'icon' => 'chart-no-axes-column'],
            'contracts' => ['tone' => 'orange', 'icon' => 'calendar-days'],
            'insurance' => ['tone' => 'red', 'icon' => 'badge-dollar-sign'],
            'leave' => ['tone' => 'blue', 'icon' => 'calendar-days'],
            'approved' => ['tone' => 'green', 'icon' => 'circle-check'],
            'pending' => ['tone' => 'orange', 'icon' => 'clock'],
            'rejected' => ['tone' => 'red', 'icon' => 'triangle-alert'],
            'payroll' => ['tone' => 'purple', 'icon' => 'badge-dollar-sign'],
            'cost' => ['tone' => 'red', 'icon' => 'wallet'],
            'attendance' => ['tone' => 'green', 'icon' => 'user-check'],
            'requests' => ['tone' => 'purple', 'icon' => 'message-square'],
            'documents' => ['tone' => 'orange', 'icon' => 'file-text'],
        ][$key] ?? ['tone' => 'blue', 'icon' => 'circle-help'];
    }
}
