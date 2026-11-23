<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * Small, safe evaluator for the Excel formulas found in HR workbooks, used
 * only for formula cells that carry no saved result (files written by tools
 * that do not recalculate). It supports arithmetic, text joins, comparisons,
 * same-sheet cell and range references and a short list of functions. Any
 * formula outside that list throws, and the reader then keeps the cell empty
 * exactly as before. It never evaluates anything outside the workbook text.
 */
final class SpreadsheetFormulaEvaluator
{
    private string $src = '';
    private int $pos = 0;
    private int $currentRow = 0;
    private int $depth = 0;

    /** @param callable(int,int): mixed $cell raw cell value (number, string, bool or null for blank) */
    public function __construct(private $cell, private readonly bool $date1904 = false)
    {
    }

    /** @return string|float|bool|null */
    public function evaluate(string $formula, int $row): string|float|bool|null
    {
        // Re-entrant: a referenced formula cell is evaluated in the middle of this one.
        $saved = [$this->src, $this->pos, $this->currentRow, $this->depth];
        try {
            $this->src = ltrim($formula, '=');
            $this->pos = 0;
            $this->currentRow = $row;
            $this->depth = 0;
            $value = $this->comparison();
            $this->skip();
            if ($this->pos < strlen($this->src)) {
                throw new RuntimeException('trailing formula text');
            }
            if (is_array($value)) {
                $value = $value[0][0] ?? null;
            }

            return $value;
        } finally {
            [$this->src, $this->pos, $this->currentRow, $this->depth] = $saved;
        }
    }

    private function skip(): void
    {
        while ($this->pos < strlen($this->src) && ctype_space($this->src[$this->pos])) {
            $this->pos++;
        }
    }

    private function peek(int $len = 1): string
    {
        $this->skip();

        return substr($this->src, $this->pos, $len);
    }

    private function comparison(): mixed
    {
        $left = $this->concat();
        while (true) {
            $op = null;
            foreach (['<>', '<=', '>=', '=', '<', '>'] as $candidate) {
                if ($this->peek(strlen($candidate)) === $candidate) {
                    $op = $candidate;
                    break;
                }
            }
            if ($op === null) {
                return $left;
            }
            $this->pos += strlen($op);
            $right = $this->concat();
            $left = $this->compare($this->scalar($left), $this->scalar($right), $op);
        }
    }

    private function compare(mixed $a, mixed $b, string $op): bool
    {
        $a = $a ?? (is_string($b) ? '' : 0.0);
        $b = $b ?? (is_string($a) ? '' : 0.0);
        if (is_string($a) && is_string($b)) {
            $cmp = strcmp(mb_strtolower($a), mb_strtolower($b));
        } elseif (is_string($a) !== is_string($b)) {
            // Excel orders text above numbers.
            $cmp = is_string($a) ? 1 : -1;
        } else {
            $cmp = (float) $a <=> (float) $b;
        }

        return match ($op) {
            '=' => $cmp === 0,
            '<>' => $cmp !== 0,
            '<' => $cmp < 0,
            '>' => $cmp > 0,
            '<=' => $cmp <= 0,
            default => $cmp >= 0,
        };
    }

    private function concat(): mixed
    {
        $left = $this->additive();
        while ($this->peek() === '&') {
            $this->pos++;
            $right = $this->additive();
            $left = $this->text($this->scalar($left)) . $this->text($this->scalar($right));
        }

        return $left;
    }

    private function additive(): mixed
    {
        $left = $this->term();
        while (in_array($this->peek(), ['+', '-'], true)) {
            $op = $this->peek();
            $this->pos++;
            $right = $this->term();
            $left = $op === '+' ? $this->num($left) + $this->num($right) : $this->num($left) - $this->num($right);
        }

        return $left;
    }

    private function term(): mixed
    {
        $left = $this->power();
        while (in_array($this->peek(), ['*', '/'], true)) {
            $op = $this->peek();
            $this->pos++;
            $right = $this->power();
            if ($op === '*') {
                $left = $this->num($left) * $this->num($right);
            } else {
                $divisor = $this->num($right);
                if ($divisor == 0.0) {
                    throw new FormulaValueError('#DIV/0!');
                }
                $left = $this->num($left) / $divisor;
            }
        }

        return $left;
    }

    private function power(): mixed
    {
        $base = $this->unary();
        while ($this->peek() === '^') {
            $this->pos++;
            $base = $this->num($base) ** $this->num($this->unary());
        }

        return $base;
    }

    private function unary(): mixed
    {
        $c = $this->peek();
        if ($c === '-') {
            $this->pos++;

            return -$this->num($this->unary());
        }
        if ($c === '+') {
            $this->pos++;

            return $this->unary();
        }
        $value = $this->primary();
        while ($this->peek() === '%') {
            $this->pos++;
            $value = $this->num($value) / 100;
        }

        return $value;
    }

    private function primary(): mixed
    {
        if (++$this->depth > 60) {
            throw new RuntimeException('formula too deep');
        }
        try {
            $this->skip();
            $c = $this->src[$this->pos] ?? '';
            if ($c === '') {
                throw new RuntimeException('unexpected end');
            }
            if ($c === '(') {
                $this->pos++;
                $value = $this->comparison();
                $this->expect(')');

                return $value;
            }
            if ($c === '"') {
                return $this->string();
            }
            if (ctype_digit($c) || $c === '.') {
                return $this->number();
            }
            if (!preg_match('/\G(\$?[A-Za-z]{1,3}\$?\d+(?::\$?[A-Za-z]{1,3}\$?\d+)?|[A-Za-z_][A-Za-z0-9_.]*)/', $this->src, $m, 0, $this->pos)) {
                throw new RuntimeException('unsupported token');
            }
            $token = $m[1];
            $this->pos += strlen($token);
            if ($this->peek() === '(') {
                return $this->call(strtoupper($token));
            }
            if (preg_match('/^\$?([A-Za-z]{1,3})\$?(\d+)(?::\$?([A-Za-z]{1,3})\$?(\d+))?$/', $token, $r)) {
                $c1 = $this->col($r[1]);
                $r1 = (int) $r[2];
                if (isset($r[3]) && $r[3] !== '') {
                    $c2 = $this->col($r[3]);
                    $r2 = (int) $r[4];
                    if (($r2 - $r1 + 1) * (abs($c2 - $c1) + 1) > 5000) {
                        throw new RuntimeException('range too large');
                    }
                    $grid = [];
                    for ($y = min($r1, $r2); $y <= max($r1, $r2); $y++) {
                        $line = [];
                        for ($x = min($c1, $c2); $x <= max($c1, $c2); $x++) {
                            $line[] = $this->cellValue($y, $x);
                        }
                        $grid[] = $line;
                    }

                    return $grid;
                }

                return $this->cellValue($r1, $c1);
            }
            $upper = strtoupper($token);
            if ($upper === 'TRUE') {
                return true;
            }
            if ($upper === 'FALSE') {
                return false;
            }
            throw new RuntimeException('unsupported name');
        } finally {
            $this->depth--;
        }
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new RuntimeException('expected ' . $char);
        }
        $this->pos++;
    }

    private function string(): string
    {
        $this->pos++;
        $out = '';
        while ($this->pos < strlen($this->src)) {
            $c = $this->src[$this->pos++];
            if ($c === '"') {
                if (($this->src[$this->pos] ?? '') === '"') {
                    $out .= '"';
                    $this->pos++;
                    continue;
                }

                return $out;
            }
            $out .= $c;
        }
        throw new RuntimeException('unterminated string');
    }

    private function number(): float
    {
        if (!preg_match('/\G\d*\.?\d+(?:[eE][+-]?\d+)?|\G\d+\./', $this->src, $m, 0, $this->pos)) {
            throw new RuntimeException('bad number');
        }
        $this->pos += strlen($m[0]);

        return (float) $m[0];
    }

    private function col(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** Cell as number, string, bool or null; dates and times become Excel serials. */
    private function cellValue(int $row, int $col): mixed
    {
        $raw = ($this->cell)($row, $col);
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_bool($raw) || is_float($raw) || is_int($raw)) {
            return is_int($raw) ? (float) $raw : $raw;
        }
        $text = (string) $raw;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/', $text, $m)) {
            $base = new \DateTimeImmutable($this->date1904 ? '1904-01-01' : '1899-12-30', new \DateTimeZone('UTC'));
            $day = new \DateTimeImmutable($m[1] . '-' . $m[2] . '-' . $m[3], new \DateTimeZone('UTC'));
            $serial = (float) $base->diff($day)->days * ($day < $base ? -1 : 1);
            if (isset($m[4])) {
                $serial += ((int) $m[4] * 3600 + (int) $m[5] * 60 + (int) $m[6]) / 86400;
            }

            return $serial;
        }
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $text, $m)) {
            return ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]) / 86400;
        }
        if (is_numeric($text)) {
            return (float) $text;
        }

        return $text;
    }

    private function scalar(mixed $value): mixed
    {
        return is_array($value) ? ($value[0][0] ?? null) : $value;
    }

    private function num(mixed $value): float
    {
        $value = $this->scalar($value);
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        throw new FormulaValueError('#VALUE!');
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_float($value)) {
            return floor($value) == $value && abs($value) < 1.0E+15 ? sprintf('%.0f', $value) : rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        }

        return (string) $value;
    }

    private function truthy(mixed $value): bool
    {
        $value = $this->scalar($value);
        if (is_string($value)) {
            throw new RuntimeException('text is not a condition');
        }

        return (bool) ($value ?? false);
    }

    /** @return array<int, mixed> flat values of an argument */
    private function flat(mixed $value): array
    {
        if (!is_array($value)) {
            return [$value];
        }
        $out = [];
        foreach ($value as $line) {
            foreach ($line as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function call(string $name): mixed
    {
        $this->expect('(');
        $args = [];
        $lazy = in_array($name, ['IF', 'IFERROR'], true);
        $start = $this->pos;
        if ($this->peek() === ')') {
            $this->pos++;
        } else {
            if ($lazy) {
                // Only the chosen branch is evaluated, as in Excel.
                $parts = $this->splitArguments();
                $args = $parts;
            } else {
                do {
                    $args[] = $this->comparison();
                    $more = $this->peek() === ',' || $this->peek() === ';';
                    if ($more) {
                        $this->pos++;
                    }
                } while ($more);
                $this->expect(')');
            }
        }

        return match ($name) {
            'IF' => $this->fnIf($args),
            'IFERROR' => $this->fnIfError($args),
            'AND' => $this->fnLogic($args, true),
            'OR' => $this->fnLogic($args, false),
            'NOT' => !$this->truthy($args[0] ?? null),
            'SUM' => array_sum(array_map(fn ($v) => $this->numericOrZero($v), array_merge(...array_map(fn ($a) => $this->flat($a), $args ?: [[]])))),
            'MIN' => $this->fnMinMax($args, true),
            'MAX' => $this->fnMinMax($args, false),
            'ABS' => abs($this->num($args[0] ?? null)),
            'INT' => floor($this->num($args[0] ?? null)),
            'ROUND' => round($this->num($args[0] ?? null), (int) $this->num($args[1] ?? 0)),
            'ROUNDUP' => $this->roundDir($this->num($args[0] ?? null), (int) $this->num($args[1] ?? 0), true),
            'ROUNDDOWN' => $this->roundDir($this->num($args[0] ?? null), (int) $this->num($args[1] ?? 0), false),
            'ROW' => $args === [] ? (float) $this->currentRow : throw new RuntimeException('ROW(ref) unsupported'),
            'LEN' => (float) mb_strlen($this->text($this->scalar($args[0] ?? null))),
            'TRIM' => trim((string) preg_replace('/\s+/u', ' ', $this->text($this->scalar($args[0] ?? null)))),
            'CONCATENATE', 'CONCAT' => implode('', array_map(fn ($a) => $this->text($this->scalar($a)), $args)),
            'VALUE' => $this->fnValue($args[0] ?? null),
            'ISBLANK' => $this->scalar($args[0] ?? null) === null,
            'ISNUMBER' => is_float($this->scalar($args[0] ?? null)),
            'TEXT' => $this->fnText($args),
            'DATE' => $this->fnDate($args),
            default => throw new RuntimeException('unsupported function ' . $name),
        };
    }

    /** Splits IF/IFERROR arguments at top level and evaluates them lazily by position. */
    private function splitArguments(): array
    {
        $depth = 0;
        $inString = false;
        $begin = $this->pos;
        $parts = [];
        for ($i = $this->pos; $i < strlen($this->src); $i++) {
            $c = $this->src[$i];
            if ($c === '"') {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                if ($depth === 0) {
                    $parts[] = substr($this->src, $begin, $i - $begin);
                    $this->pos = $i + 1;

                    return array_map(fn (string $part) => ['lazy' => $part], $parts);
                }
                $depth--;
            } elseif (($c === ',' || $c === ';') && $depth === 0) {
                $parts[] = substr($this->src, $begin, $i - $begin);
                $begin = $i + 1;
            }
        }
        throw new RuntimeException('unterminated call');
    }

    private function lazy(mixed $arg): mixed
    {
        if (!is_array($arg) || !array_key_exists('lazy', $arg)) {
            return $arg;
        }
        $saved = [$this->src, $this->pos];
        $this->src = $arg['lazy'];
        $this->pos = 0;
        try {
            $value = $this->comparison();
            $this->skip();
            if ($this->pos < strlen($this->src)) {
                throw new RuntimeException('trailing argument text');
            }

            return $value;
        } finally {
            [$this->src, $this->pos] = $saved;
        }
    }

    private function fnIf(array $args): mixed
    {
        if (count($args) < 2) {
            throw new RuntimeException('IF needs arguments');
        }
        if ($this->truthy($this->lazy($args[0]))) {
            return $this->lazy($args[1]);
        }

        return count($args) > 2 ? $this->lazy($args[2]) : false;
    }

    private function fnIfError(array $args): mixed
    {
        try {
            return $this->lazy($args[0] ?? null);
        } catch (FormulaValueError) {
            // Only genuine spreadsheet errors are replaced; a formula this
            // evaluator cannot read stays unevaluated instead of becoming 0.
            return $this->lazy($args[1] ?? '');
        }
    }

    private function fnLogic(array $args, bool $all): bool
    {
        $values = array_map(fn ($v) => $this->truthy($v), array_filter(array_merge(...array_map(fn ($a) => $this->flat($a), $args ?: [[]])), fn ($v) => $v !== null));

        return $all ? !in_array(false, $values, true) : in_array(true, $values, true);
    }

    private function numericOrZero(mixed $value): float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_bool($value) ? 0.0 : 0.0);
    }

    private function fnMinMax(array $args, bool $min): float
    {
        $numbers = array_values(array_filter(array_merge(...array_map(fn ($a) => $this->flat($a), $args ?: [[]])), fn ($v) => is_float($v) || is_int($v)));
        if ($numbers === []) {
            return 0.0;
        }

        return (float) ($min ? min($numbers) : max($numbers));
    }

    private function roundDir(float $value, int $digits, bool $up): float
    {
        $factor = 10 ** $digits;

        return ($up ? ceil(abs($value) * $factor) : floor(abs($value) * $factor)) / $factor * ($value < 0 ? -1 : 1);
    }

    private function fnValue(mixed $value): float
    {
        return $this->num($value);
    }

    private function fnText(array $args): string
    {
        $value = $this->scalar($args[0] ?? null);
        $format = $this->text($this->scalar($args[1] ?? ''));
        if (preg_match('/^(#,##)?0+(\.0+)?$/', $format)) {
            $decimals = str_contains($format, '.') ? strlen(substr($format, strpos($format, '.') + 1)) : 0;
            $width = strlen(explode('.', ltrim($format, '#,'))[0]);
            $number = number_format($this->num($value), $decimals, '.', str_starts_with($format, '#,') ? ',' : '');
            if (!str_starts_with($format, '#,')) {
                [$whole, $fraction] = array_pad(explode('.', $number), 2, null);
                $whole = str_pad($whole, $width, '0', STR_PAD_LEFT);
                $number = $fraction === null ? $whole : $whole . '.' . $fraction;
            }

            return $number;
        }
        throw new RuntimeException('unsupported TEXT format');
    }

    private function fnDate(array $args): float
    {
        $y = (int) $this->num($args[0] ?? 0);
        $m = (int) $this->num($args[1] ?? 1);
        $d = (int) $this->num($args[2] ?? 1);
        $date = (new \DateTimeImmutable(sprintf('%04d-01-01', $y), new \DateTimeZone('UTC')))
            ->modify('+' . ($m - 1) . ' months')->modify('+' . ($d - 1) . ' days');
        $base = new \DateTimeImmutable($this->date1904 ? '1904-01-01' : '1899-12-30', new \DateTimeZone('UTC'));

        return (float) $base->diff($date)->days * ($date < $base ? -1 : 1);
    }
}

/** A spreadsheet error value (#DIV/0!, #VALUE!) that IFERROR may replace. */
final class FormulaValueError extends RuntimeException
{
}
