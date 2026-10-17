<?php

namespace App\Services\Imports;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Minimal, dependency-free reader for .xlsx and .csv files.
 *
 * It returns the first visible worksheet as: [excelRowNumber => [columnIndex => string]].
 * Only non-empty cells and non-empty rows are returned. Dates are converted to
 * "Y-m-d" / "Y-m-d H:i:s" (or "H:i:s" for time-only cells) strings, numbers are
 * returned as plain strings (no scientific notation), shared/inline strings are
 * resolved. The project already relies on ext-zip for its workbook importer, so
 * no new Composer package is required.
 */
class SpreadsheetReader
{
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const MAX_CELL_COLUMN = 100;
    private const MAX_PART_BYTES = 41943040; // 40 MB per uncompressed XML part (zip-bomb guard)

    public function __construct(private readonly int $maxRows = 6000)
    {
    }

    /**
     * Every visible worksheet: [['name' => string, 'rows' => [excelRow => [col => value]]], ...].
     * CSV files are returned as a single sheet named "CSV".
     *
     * @return array<int, array{name: string, rows: array<int, array<int, string>>}>
     */
    public function readAll(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'xlsx') {
            return $this->readXlsx($path);
        }
        if ($extension === 'csv') {
            return [['name' => 'CSV', 'rows' => $this->readCsv($path)]];
        }

        throw new RuntimeException('نوع الملف غير مدعوم. استخدم ملف Excel بصيغة xlsx أو ملف CSV.');
    }

    /**
     * First visible worksheet only (kept for backward compatibility).
     *
     * @return array<int, array<int, string>>
     */
    public function read(string $path, string $extension): array
    {
        return $this->readAll($path, $extension)[0]['rows'] ?? [];
    }

    // ------------------------------------------------------------------ XLSX

    /**
     * @return array<int, array{name: string, rows: array<int, array<int, string>>}>
     */
    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('امتداد PHP المسمى zip غير مفعّل على الخادم. فعّله من ملف php.ini ثم أعد تشغيل Apache.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('تعذر فتح الملف. تأكد أنه ملف Excel بصيغة xlsx وغير تالف.');
        }

        try {
            $sheetList = $this->sheetList($zip);
            $date1904 = $this->usesDate1904($zip);
            $shared = $this->sharedStrings($zip);
            $dateStyles = $this->dateStyles($zip);

            $sheets = [];
            $totalRows = 0;
            foreach ($sheetList as $sheet) {
                $rows = $this->readSheet($zip, $sheet['path'], $shared, $dateStyles, $date1904);
                $totalRows += count($rows);
                if ($totalRows > $this->maxRows * 2) {
                    throw new RuntimeException('الملف كبير جدًا (إجمالي الصفوف في كل الأوراق يتجاوز ' . ($this->maxRows * 2) . ' صف). قسّمه إلى أكثر من ملف.');
                }
                $sheets[] = ['name' => $sheet['name'], 'rows' => $rows];
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * Visible worksheets in workbook order.
     *
     * @return array<int, array{name: string, path: string}>
     */
    private function sheetList(ZipArchive $zip): array
    {
        $workbook = $this->loadDom($zip, 'xl/workbook.xml');
        if ($workbook === null) {
            throw new RuntimeException('ملف Excel غير صالح: لم يتم العثور على أوراق العمل.');
        }

        $targets = [];
        $relationships = $this->loadDom($zip, 'xl/_rels/workbook.xml.rels');
        if ($relationships !== null) {
            $items = [];
            $this->collect($relationships->documentElement, 'Relationship', $items);
            foreach ($items as $item) {
                $targets[$item->getAttribute('Id')] = $item->getAttribute('Target');
            }
        }

        $list = [];
        $sheets = [];
        $this->collect($workbook->documentElement, 'sheet', $sheets);
        foreach ($sheets as $sheet) {
            $state = $sheet->getAttribute('state');
            if ($state === 'hidden' || $state === 'veryHidden') {
                continue;
            }
            $target = $targets[$sheet->getAttributeNS(self::NS_REL, 'id')] ?? '';
            if ($target === '') {
                continue;
            }
            $target = ltrim($target, '/');
            $full = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            if ($zip->locateName($full) === false) {
                continue;
            }
            $name = trim($sheet->getAttribute('name'));
            $list[] = ['name' => $name !== '' ? $name : 'Sheet' . (count($list) + 1), 'path' => $full];
            if (count($list) >= 30) {
                break;
            }
        }

        if ($list === [] && $zip->locateName('xl/worksheets/sheet1.xml') !== false) {
            $list[] = ['name' => 'Sheet1', 'path' => 'xl/worksheets/sheet1.xml'];
        }
        if ($list === []) {
            throw new RuntimeException('ملف Excel غير صالح: لا توجد ورقة بيانات ظاهرة.');
        }

        return $list;
    }

    private function usesDate1904(ZipArchive $zip): bool
    {
        $workbook = $this->loadDom($zip, 'xl/workbook.xml');
        if ($workbook === null) {
            return false;
        }
        $nodes = [];
        $this->collect($workbook->documentElement, 'workbookPr', $nodes);
        if ($nodes === []) {
            return false;
        }
        $value = strtolower($nodes[0]->getAttribute('date1904'));

        return $value === '1' || $value === 'true';
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $name = 'xl/sharedStrings.xml';
        if ($zip->locateName($name) === false) {
            return [];
        }
        $this->assertPartSize($zip, $name);
        $xml = $zip->getFromName($name);
        if ($xml === false || $xml === '') {
            return [];
        }

        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $document = new DOMDocument();
        $strings = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $expanded = $reader->expand();
            if (!$expanded) {
                $strings[] = '';
                continue;
            }
            $node = $document->importNode($expanded, true);
            $strings[] = $node instanceof DOMElement ? $this->stringItemText($node) : '';
        }
        $reader->close();

        return $strings;
    }

    /**
     * Returns, for every cell style index, whether it renders dates/times.
     *
     * @return array<int, bool>
     */
    private function dateStyles(ZipArchive $zip): array
    {
        $dom = $this->loadDom($zip, 'xl/styles.xml');
        if ($dom === null) {
            return [];
        }

        $custom = [];
        $formats = [];
        $this->collect($dom->documentElement, 'numFmt', $formats);
        foreach ($formats as $format) {
            $custom[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
        }

        $styles = [];
        foreach ($dom->documentElement->childNodes as $section) {
            if (!$section instanceof DOMElement || $section->localName !== 'cellXfs') {
                continue;
            }
            foreach ($section->childNodes as $xf) {
                if (!$xf instanceof DOMElement || $xf->localName !== 'xf') {
                    continue;
                }
                $id = (int) $xf->getAttribute('numFmtId');
                $styles[] = $this->isDateFormat($id, $custom[$id] ?? null);
            }
        }

        return $styles;
    }

    private function isDateFormat(int $id, ?string $code): bool
    {
        if (in_array($id, [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47], true)) {
            return true;
        }
        if (($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58)) {
            return true;
        }
        if ($code === null || $code === '') {
            return false;
        }

        $code = (string) preg_replace('/"[^"]*"/u', '', $code);
        $code = (string) preg_replace('/\\\\./u', '', $code);
        $code = (string) preg_replace('/\[(?![hms])[^\]]*\]/i', '', $code);

        return (bool) preg_match('/[ymdhs]/i', $code);
    }

    /**
     * @param array<int, string> $shared
     * @param array<int, bool> $dateStyles
     * @return array<int, array<int, string>>
     */
    private function readSheet(ZipArchive $zip, string $sheetPath, array $shared, array $dateStyles, bool $date1904): array
    {
        $this->assertPartSize($zip, $sheetPath);
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false || $xml === '') {
            return [];
        }

        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $document = new DOMDocument();
        $rows = [];
        $lastRow = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $declared = (int) $reader->getAttribute('r');
            $lastRow = $declared > 0 ? $declared : $lastRow + 1;

            $expanded = $reader->expand();
            if (!$expanded) {
                continue;
            }
            $node = $document->importNode($expanded, true);
            if (!$node instanceof DOMElement) {
                continue;
            }

            $cells = $this->parseRow($node, $shared, $dateStyles, $date1904);
            if ($cells === []) {
                continue;
            }

            $rows[$lastRow] = $cells;
            if (count($rows) > $this->maxRows + 30) {
                $reader->close();
                throw new RuntimeException('الملف يحتوي على عدد صفوف أكبر من المسموح (' . $this->maxRows . ' صف). قسّمه إلى أكثر من ملف.');
            }
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param array<int, string> $shared
     * @param array<int, bool> $dateStyles
     * @return array<int, string>
     */
    private function parseRow(DOMElement $row, array $shared, array $dateStyles, bool $date1904): array
    {
        $cells = [];
        $next = 0;

        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                continue;
            }

            $reference = $cell->getAttribute('r');
            $column = $reference !== '' ? $this->columnIndex($reference) : $next;
            $next = $column + 1;
            if ($column >= self::MAX_CELL_COLUMN) {
                continue;
            }

            $value = $this->cellValue($cell, $shared, $dateStyles, $date1904);
            if ($value !== '') {
                $cells[$column] = $value;
            }
        }

        return $cells;
    }

    /**
     * @param array<int, string> $shared
     * @param array<int, bool> $dateStyles
     */
    private function cellValue(DOMElement $cell, array $shared, array $dateStyles, bool $date1904): string
    {
        $type = $cell->getAttribute('t');
        $style = (int) $cell->getAttribute('s');
        $raw = '';
        $inline = '';

        foreach ($cell->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'v') {
                $raw = $child->textContent;
            } elseif ($child->localName === 'is') {
                $inline = $this->stringItemText($child);
            }
        }

        switch ($type) {
            case 's':
                return $raw === '' ? '' : trim($shared[(int) $raw] ?? '');
            case 'inlineStr':
                return trim($inline);
            case 'str':
            case 'd':
                return trim($raw);
            case 'b':
                return $raw === '' ? '' : ($raw === '1' ? '1' : '0');
            case 'e':
                return '';
        }

        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (($dateStyles[$style] ?? false) && is_numeric($raw)) {
            return $this->excelDate((float) $raw, $date1904);
        }

        return $this->numericString($raw);
    }

    private function stringItemText(DOMElement $item): string
    {
        $nodes = [];
        $this->collect($item, 't', $nodes);
        $text = '';
        foreach ($nodes as $node) {
            // Skip phonetic runs (<rPh>) that Excel adds for East-Asian text.
            if ($node->parentNode instanceof DOMElement && $node->parentNode->localName === 'rPh') {
                continue;
            }
            $text .= $node->textContent;
        }

        return $text;
    }

    private function excelDate(float $serial, bool $date1904): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }

        $time = sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        if ($days <= 0) {
            return $time; // time-only cell
        }

        $base = new \DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', new \DateTimeZone('UTC'));
        $date = $base->modify('+' . $days . ' days')->format('Y-m-d');

        return $seconds === 0 ? $date : $date . ' ' . $time;
    }

    private function numericString(string $raw): string
    {
        if (preg_match('/^-?\d+$/', $raw)) {
            return $raw;
        }
        if (!is_numeric($raw)) {
            return $raw;
        }

        $float = (float) $raw;
        if (floor($float) == $float && abs($float) < 1.0E+15) {
            return sprintf('%.0f', $float);
        }

        $text = rtrim(rtrim(number_format($float, 10, '.', ''), '0'), '.');

        return ($text === '' || $text === '-') ? '0' : $text;
    }

    private function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
        if ($letters === '') {
            return 0;
        }
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }

    private function loadDom(ZipArchive $zip, string $name): ?DOMDocument
    {
        if ($zip->locateName($name) === false) {
            return null;
        }
        $this->assertPartSize($zip, $name);
        $xml = $zip->getFromName($name);
        if ($xml === false || $xml === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return ($loaded && $document->documentElement !== null) ? $document : null;
    }

    private function assertPartSize(ZipArchive $zip, string $name): void
    {
        $stat = $zip->statName($name);
        if (is_array($stat) && ($stat['size'] ?? 0) > self::MAX_PART_BYTES) {
            throw new RuntimeException('محتوى الملف كبير جدًا. قلّل عدد الصفوف أو احذف التنسيقات والصور غير الضرورية.');
        }
    }

    /**
     * Namespace-agnostic descendant lookup by local name (document order).
     *
     * @param array<int, DOMElement> $result
     */
    private function collect(DOMNode $node, string $localName, array &$result): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === $localName) {
                $result[] = $child;
            }
            $this->collect($child, $localName, $result);
        }
    }

    // ------------------------------------------------------------------- CSV

    private function readCsv(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('تعذر قراءة الملف.');
        }

        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            // Excel on Arabic Windows saves "CSV (Comma delimited)" as Windows-1256.
            $converted = function_exists('iconv') ? @iconv('Windows-1256', 'UTF-8//IGNORE', $content) : false;
            if ($converted === false || $converted === '') {
                $converted = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-6');
            }
            $content = $converted;
        }

        $firstLine = '';
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $candidate) {
            if (trim($candidate) !== '') {
                $firstLine = $candidate;
                break;
            }
        }
        $delimiter = $this->detectDelimiter($firstLine);

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('تعذر معالجة الملف.');
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        $line = 0;
        while (($record = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($record === [null]) {
                continue;
            }

            $cells = [];
            foreach ($record as $index => $value) {
                if ($index >= self::MAX_CELL_COLUMN) {
                    break;
                }
                $value = trim((string) $value);
                if ($value !== '') {
                    $cells[$index] = $value;
                }
            }
            if ($cells === []) {
                continue;
            }

            $rows[$line] = $cells;
            if (count($rows) > $this->maxRows + 30) {
                fclose($stream);
                throw new RuntimeException('الملف يحتوي على عدد صفوف أكبر من المسموح (' . $this->maxRows . ' صف). قسّمه إلى أكثر من ملف.');
            }
        }
        fclose($stream);

        return $rows;
    }

    private function detectDelimiter(string $line): string
    {
        $candidates = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($candidates);
        $best = array_key_first($candidates);

        return ($best !== null && $candidates[$best] > 0) ? (string) $best : ',';
    }
}
