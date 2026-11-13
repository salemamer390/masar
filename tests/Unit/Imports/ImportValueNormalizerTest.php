<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\ImportValueNormalizer;
use PHPUnit\Framework\TestCase;

class ImportValueNormalizerTest extends TestCase
{
    public function test_name_keys_share_employee_import_rules_without_fuzzy_matching(): void
    {
        $n = new ImportValueNormalizer();
        $this->assertSame($n->key('عَامِر سـالم جُمعة'), $n->key('عامر سالم جمعه'));
        $this->assertSame($n->key('أحمد مصطفى'), $n->key('احمد مصطفي'));
        $this->assertNotSame($n->key('محمد علي'), $n->key('محمود علي'));
    }

    public function test_identifiers_normalize_both_digit_sets_and_excel_zero_fraction_without_losing_leading_zeroes(): void
    {
        $n = new ImportValueNormalizer();
        foreach (['١٠١', '۱۰۱', '101.0'] as $value) $this->assertSame('101', $n->identifier($value));
        $this->assertSame('0101', $n->identifier('٠١٠١.٠'));
        $this->assertSame('HR-012', $n->identifier('HR-٠١٢'));
        $this->assertNotSame($n->identifier('0101'), $n->identifier('101'));
    }

    public function test_time_uses_the_shared_employee_parser_and_rejects_invalid_values(): void
    {
        $n = new ImportValueNormalizer();
        foreach (['٠٩:٣٠' => '09:30', '۰۹:۳۰' => '09:30', '٩:٣٠ م' => '21:30', '12:00 AM' => '00:00', '0' => '00:00', '0.5' => '12:00'] as $value => $expected) {
            $this->assertSame($expected, $n->time((string) $value));
        }
        foreach (['25:00', '13:30 PM', '00:30 AM', 'tomorrow', '9:65', '0.9999999'] as $value) $this->assertNull($n->time($value));
    }

    public function test_status_aliases_normalize_letters_and_never_invent_payroll_states(): void
    {
        $n = new ImportValueNormalizer();
        foreach (['متاخر' => 'Late', 'تأخير' => 'Late', 'حضور' => 'Present', 'غياب' => 'Absent', 'اجازه' => 'Leave', 'إجازة' => 'Leave', 'PRESENT' => 'Present'] as $value => $expected) $this->assertSame($expected, $n->attendanceStatus($value));
        foreach (['راحة', 'عطلة', 'مأمورية', 'عمل من المنزل', 'unexpected'] as $value) $this->assertNull($n->attendanceStatus($value));
        $this->assertSame('', $n->attendanceStatus(''));
    }
}
