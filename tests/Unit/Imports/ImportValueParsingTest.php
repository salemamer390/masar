<?php

namespace Tests\Unit\Imports;

use App\Services\Imports\ParsesImportValues;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImportValueParsingTest extends TestCase
{
    private object $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new class {
            use ParsesImportValues;

            public function call(string $method, mixed ...$arguments): mixed
            {
                return $this->{$method}(...$arguments);
            }
        };
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        return $this->parser->call($method, ...$arguments);
    }

    /** @dataProvider validDates */
    public function test_parse_date_accepts_common_formats(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->call('parseDate', $input));
    }

    public static function validDates(): array
    {
        return [
            ['2026-01-31', '2026-01-31'],
            ['31/01/2026', '2026-01-31'],
            ['٣١/٠١/٢٠٢٦', '2026-01-31'],
            ['2026-01-31 00:00:00', '2026-01-31'],
            ['45322', '2024-01-31'],
            ['1-2-2026', '2026-02-01'],
        ];
    }

    /** @dataProvider invalidDates */
    public function test_parse_date_rejects_invalid_values(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->call('parseDate', $input);
    }

    public static function invalidDates(): array
    {
        return [['31/02/2026'], ['abc'], ['2026-13-01'], ['1850-01-01']];
    }

    /** @dataProvider times */
    public function test_parse_time(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->call('parseTime', $input));
    }

    public static function times(): array
    {
        return [
            ['09:00', '09:00:00'], ['9:00 ص', '09:00:00'], ['5:30 م', '17:30:00'], ['12:00 AM', '00:00:00'],
            ['0.375', '09:00:00'], ['2026-01-01 08:30:00', '08:30:00'],
        ];
    }

    public function test_parse_time_rejects_out_of_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->call('parseTime', '25:00');
    }

    /** @dataProvider money */
    public function test_parse_money(string $input, float $expected): void
    {
        $this->assertSame($expected, $this->call('parseMoney', $input));
    }

    public static function money(): array
    {
        return [['28000', 28000.0], ['28,000 ج.م', 28000.0], ['١٥٬٠٠٠', 15000.0], ['12,5', 12.5], ['15000.567', 15000.57], ['EGP 9,500.50', 9500.5]];
    }

    public function test_parse_money_rejects_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->call('parseMoney', 'abc');
    }

    public function test_national_id_data_extracts_birth_date_and_gender(): void
    {
        $this->assertSame(['birth_date' => '1998-01-01', 'gender' => 'أنثى'], $this->call('nationalIdData', '29801011234567'));
        $this->assertSame(['birth_date' => '2005-01-15', 'gender' => 'أنثى'], $this->call('nationalIdData', '30501150012341'));
        $this->assertSame(['birth_date' => '1990-05-20', 'gender' => 'ذكر'], $this->call('nationalIdData', '29005201234513'));
    }

    public function test_national_id_data_reports_problems(): void
    {
        $this->assertIsString($this->call('nationalIdData', '123'));
        $this->assertIsString($this->call('nationalIdData', '12345678901234'));
        $this->assertIsString($this->call('nationalIdData', '29802301234567'));
    }

    public function test_phone_normalisation(): void
    {
        $this->assertSame('01001234567', $this->call('normalizePhone', '1001234567'));
        $this->assertSame('01001234567', $this->call('normalizePhone', '01001234567'));
        $this->assertSame('+201001234567', $this->call('normalizePhone', '+20 100 123 4567'));
        $this->assertNull($this->call('normalizePhone', '12ab'));
    }

    public function test_numeric_text_restores_identifiers_stored_as_numbers(): void
    {
        $this->assertSame('29801011234567', $this->call('numericText', '2.9801011234567E+13'));
        $this->assertSame('101', $this->call('numericText', '101.0'));
        $this->assertSame('A-12', $this->call('numericText', 'A-12'));
        $this->assertSame('12E5', $this->call('numericText', '12E5'));
    }

    public function test_key_normalisation_ignores_hamza_diacritics_and_punctuation(): void
    {
        $this->assertSame($this->call('key', 'الرقم القومى'), $this->call('key', 'الرقم القومي *'));
        $this->assertSame($this->call('key', 'البريد الإلكتروني'), $this->call('key', 'البريد الالكتروني'));
        $this->assertSame('employeecode', $this->call('key', 'Employee Code'));
    }

    public function test_similarity_is_higher_for_closer_strings(): void
    {
        $close = $this->call('similarity', 'الراتباجمالي', 'الراتبالاجمالي');
        $far = $this->call('similarity', 'ملاحظاتداخليه', 'الراتبالاجمالي');

        $this->assertGreaterThan(0.8, $close);
        $this->assertLessThan(0.5, $far);
        $this->assertSame(1.0, $this->call('similarity', 'abc', 'abc'));
    }
}
