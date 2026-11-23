<?php

namespace Tests\Unit;

use App\Services\Imports\ImportDateParser;
use App\Services\Imports\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Formula cells saved without a result (written by a tool that does not
 * recalculate) are evaluated by the reader instead of reading as empty;
 * anything it cannot evaluate stays empty and is never turned into 0.
 */
class SpreadsheetFormulaResultsTest extends TestCase
{
    /** @param array<int, array<int, string>> $rows each cell: "=formula", "#number" or text */
    private function workbook(array $rows): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $r => $cells) {
            $sheet .= '<row r="' . ($r + 1) . '">';
            foreach ($cells as $c => $cell) {
                $ref = chr(65 + $c) . ($r + 1);
                if ($cell === '') {
                    continue;
                }
                if ($cell[0] === '=') {
                    $sheet .= '<c r="' . $ref . '"><f>' . htmlspecialchars(substr($cell, 1), ENT_XML1) . '</f></c>';
                } elseif ($cell[0] === '#') {
                    $sheet .= '<c r="' . $ref . '"><v>' . substr($cell, 1) . '</v></c>';
                } elseif ($cell[0] === '@') {
                    // shared formula dependent: <f t="shared" si="0"/>
                    $sheet .= '<c r="' . $ref . '"><f t="shared" si="0"/></c>';
                } elseif ($cell[0] === '$') {
                    $sheet .= '<c r="' . $ref . '"><f t="shared" ref="D2:D4" si="0">' . htmlspecialchars(substr($cell, 1), ENT_XML1) . '</f></c>';
                } else {
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars($cell, ENT_XML1) . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'fx') . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        return $path;
    }

    private function read(array $rows): array
    {
        $path = $this->workbook($rows);
        $sheets = (new SpreadsheetReader())->readAll($path, 'xlsx');
        unlink($path);

        return $sheets[0]['rows'];
    }

    public function test_arithmetic_if_and_text_formulas_get_their_results(): void
    {
        $rows = $this->read([
            ['Deal', 'Pct', 'Commission', 'Id', 'Days'],
            ['#15000', '#5', '=IF(OR(A2="",B2=""),0,A2*B2/100)', '=IF(A2="","","CM-"&TEXT(ROW()-1,"000"))'],
            ['#8000', '#3.5', '=IF(OR(A3="",B3=""),0,A3*B3/100)', '=IF(A3="","","CM-"&TEXT(ROW()-1,"000"))'],
            ['', '', '=IF(OR(A4="",B4=""),0,A4*B4/100)', '=IF(A4="","","CM-"&TEXT(ROW()-1,"000"))'],
        ]);

        $this->assertSame('750', $rows[2][2]);
        $this->assertSame('280', $rows[3][2]);
        $this->assertSame('CM-001', $rows[2][3]);
        $this->assertSame('CM-002', $rows[3][3]);
        // A row with no stored values is not created out of formulas alone.
        $this->assertArrayNotHasKey(4, $rows);
    }

    public function test_date_difference_formula_counts_days(): void
    {
        $rows = $this->read([
            ['From', 'To', 'Days'],
            ['#46338', '#46339', '=IF(OR(A2="",B2=""),0,B2-A2+1)'],
        ]);

        $this->assertSame('2', $rows[2][2]);
    }

    public function test_shared_formula_dependents_are_shifted(): void
    {
        $rows = $this->read([
            ['A', 'B', 'C', 'Sum'],
            ['#1', '#2', '#3', '$SUM(A2:C2)'],
            ['#10', '#20', '#30', '@'],
            ['#100', '#200', '#300', '@'],
        ]);

        $this->assertSame('6', $rows[2][3]);
        $this->assertSame('60', $rows[3][3]);
        $this->assertSame('600', $rows[4][3]);
    }

    public function test_unsupported_formulas_stay_empty_and_never_become_zero(): void
    {
        $rows = $this->read([
            ['Rate', 'Hours', 'Amount'],
            ['=IFERROR(Settings!B13/240,0)', '#2', '=IF(B2="",0,B2*A2)'],
            ['=VLOOKUP(1,A1:B2,2,0)', '#2', '#7'],
        ]);

        $this->assertArrayNotHasKey(0, $rows[2]);
        $this->assertArrayNotHasKey(2, $rows[2]); // depends on the unsupported cell
        $this->assertArrayNotHasKey(0, $rows[3]);
        $this->assertSame('7', $rows[3][2]);
    }

    public function test_iferror_replaces_a_real_spreadsheet_error(): void
    {
        $rows = $this->read([
            ['A', 'B'],
            ['#10', '=IFERROR(A2/0,0)'],
        ]);

        $this->assertSame('0', $rows[2][1]);
    }

    public function test_written_dates_are_day_first(): void
    {
        $this->assertSame('2026-11-05', ImportDateParser::toDate('05/11/2026'));
        $this->assertSame('2026-11-23', ImportDateParser::toDate('23/11/2026'));
        $this->assertSame('2026-11-05', ImportDateParser::toDate('2026-11-05'));
        $this->assertSame('2026-11-05', ImportDateParser::toDate('٠٥/١١/٢٠٢٦'));
        $this->assertSame('2026-11-05', ImportDateParser::toDate('2026-11-05 00:00:00'));
        $this->assertSame('2026-11-12', ImportDateParser::toDate('46338'));
        $this->assertNull(ImportDateParser::toDate('31/02/2026'));
        $this->assertNull(ImportDateParser::toDate(''));
        $this->assertNull(ImportDateParser::toDate('not a date'));
    }
}
