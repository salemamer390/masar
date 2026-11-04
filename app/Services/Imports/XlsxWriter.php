<?php

namespace App\Services\Imports;

use RuntimeException;
use ZipArchive;

/**
 * Tiny dependency-free .xlsx writer used for the "rejected rows" download.
 * Every value is written as text so national ids and phone numbers keep their
 * leading zeros / all digits when the user fixes the file and uploads it again.
 */
class XlsxWriter
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    public function write(string $path, array $headers, array $rows, string $sheetName = 'الصفوف المرفوضة'): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('امتداد PHP المسمى zip غير مفعّل على الخادم.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذر إنشاء ملف Excel.');
        }

        $this->writeSheetsWith($zip, [['name' => $sheetName, 'rows' => array_merge([array_values($headers)], array_map('array_values', $rows))]]);
    }

    /**
     * Write a workbook with several sheets. Each sheet is
     * ['name' => string, 'rows' => array<int, array<int, string>>] and every
     * row is written verbatim from the first spreadsheet row.
     *
     * @param array<int, array{name: string, rows: array<int, array<int, string>>}> $sheets
     */
    public function writeSheets(string $path, array $sheets): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('امتداد PHP المسمى zip غير مفعّل على الخادم.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذر إنشاء ملف Excel.');
        }

        $this->writeSheetsWith($zip, $sheets);
    }

    /**
     * @param array<int, array{name: string, rows: array<int, array<int, string>>}> $sheets
     */
    private function writeSheetsWith(ZipArchive $zip, array $sheets): void
    {
        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach (array_values($sheets) as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($index + 1) . '.xml', $this->sheet($sheet['rows']));
        }
        $zip->close();
    }

    private function contentTypes(int $sheetCount = 1): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $overrides
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . self::NS_REL . '/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '"><sheets>';
        foreach (array_values($sheets) as $index => $sheet) {
            $id = $index + 1;
            $xml .= '<sheet name="' . $this->escape(mb_substr($sheet['name'], 0, 31)) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        }
        return $xml . '</sheets></workbook>';
    }

    private function workbookRels(int $sheetCount = 1): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="' . self::NS_REL . '/styles" Target="styles.xml"/>';
        return $xml . '</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3A68"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, string>> $rows
     */
    /**
     * @param array<int, array<int, string>> $rows written verbatim from row 1
     */
    private function sheet(array $rows): string
    {
        $columns = max(1, max(array_map('count', $rows ?: [[]])));
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::NS_MAIN . '">'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols><col min="1" max="' . $columns . '" width="24" customWidth="1"/></cols>'
            . '<sheetData>';

        $rowNumber = 0;
        foreach ($rows as $row) {
            $rowNumber++;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach (array_values($row) as $index => $value) {
                if ((string) $value !== '') {
                    $xml .= $this->cell($index, $rowNumber, (string) $value, $rowNumber === 1 ? 1 : 2);
                }
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    private function cell(int $column, int $row, string $value, int $style): string
    {
        return '<c r="' . $this->columnLetter($column) . $row . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
            . $this->escape($value) . '</t></is></c>';
    }

    private function columnLetter(int $index): string
    {
        $letters = '';
        $number = $index + 1;
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $number = intdiv($number - 1, 26);
        }

        return $letters;
    }

    private function escape(string $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
