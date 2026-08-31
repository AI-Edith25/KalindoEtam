<?php

namespace App\Services\Import;

/**
 * Pure, static row/column normalization — no I/O, no Eloquent. Every method
 * takes/returns plain scalars or arrays so it's directly unit-testable and
 * reusable from both the interactive preview step and the commit job.
 */
final class DataCleaner
{
    /**
     * Drops columns that are 100% blank, and columns whose only non-blank
     * value is the same for every row (e.g. a `currency` column that's
     * always "IDR"). $rows is a list of associative arrays keyed by column.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, droppedEmpty: string[], droppedConstant: string[]}
     */
    public static function dropEmptyAndConstantColumns(array $rows): array
    {
        if ($rows === []) {
            return ['rows' => [], 'droppedEmpty' => [], 'droppedConstant' => []];
        }

        $columns = array_keys($rows[0]);
        $droppedEmpty = [];
        $droppedConstant = [];

        foreach ($columns as $column) {
            $nonBlank = [];

            foreach ($rows as $row) {
                $value = $row[$column] ?? null;

                if (! self::blank($value)) {
                    $nonBlank[] = $value;
                }
            }

            if ($nonBlank === []) {
                $droppedEmpty[] = $column;
            } elseif (count(array_unique(array_map('strval', $nonBlank))) === 1) {
                $droppedConstant[] = $column;
            }
        }

        $dropped = [...$droppedEmpty, ...$droppedConstant];

        if ($dropped === []) {
            return ['rows' => $rows, 'droppedEmpty' => [], 'droppedConstant' => []];
        }

        $keep = array_flip($dropped);
        $rows = array_map(fn (array $row) => array_diff_key($row, $keep), $rows);

        return ['rows' => $rows, 'droppedEmpty' => $droppedEmpty, 'droppedConstant' => $droppedConstant];
    }

    /**
     * Strips `Rp`, `#`, and spaces, then interprets `.` per $decimalStyle:
     * 'dot_thousands' (default) treats `.` as a thousands separator and `,`
     * as the decimal point; 'dot_decimal' treats `.` as the decimal point.
     * Indonesian source files are ambiguous either way, so this is a setting
     * the user confirms per column, never guessed — EXCEPT when the cell
     * arrives already numeric (a genuinely-numeric Excel column, via
     * PhpSpreadsheet), which is never ambiguous and must never be
     * re-stringified: doing so once turned a real `255945.95` into
     * `25594595` under the default thousands style.
     */
    public static function normalizeNumber(mixed $value, string $decimalStyle = 'dot_thousands'): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (self::blank($value)) {
            return null;
        }

        $stripped = preg_replace('/[^0-9.,]/', '', trim($value)) ?? '';

        $normalized = $decimalStyle === 'dot_decimal'
            ? str_replace(',', '', $stripped)
            : str_replace(',', '.', str_replace('.', '', $stripped));

        return $normalized === '' || $normalized === '.' ? null : (float) $normalized;
    }

    /**
     * Evidence-based default for a numeric column's decimal style, used to
     * seed clean_settings instead of always guessing 'dot_thousands'. Only
     * string values carry real ambiguity (an already-numeric Excel cell is
     * handled by normalizeNumber()'s type short-circuit above and never
     * needs a style at all) — a value like "255945.95" (dot then exactly
     * 1-2 digits, no other dot) is decimal evidence; "540.541" (dot then
     * exactly 3 digits, no decimal remainder) is thousands evidence. Mixed
     * or absent evidence keeps today's 'dot_thousands' default.
     *
     * @param  array<int, mixed>  $values  raw, uncleaned column values
     */
    public static function detectDecimalStyle(array $values): string
    {
        $sawDecimal = false;
        $sawThousands = false;

        foreach ($values as $value) {
            if (! is_string($value) || self::blank($value)) {
                continue;
            }

            $trimmed = trim($value);

            if (preg_match('/^\d+\.\d{1,2}$/', $trimmed) === 1) {
                $sawDecimal = true;
            } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $trimmed) === 1) {
                $sawThousands = true;
            }
        }

        return $sawDecimal && ! $sawThousands ? 'dot_decimal' : 'dot_thousands';
    }

    /** Trims, strips a leading `#`, and collapses internal whitespace runs to a single space. */
    public static function normalizeText(?string $value): ?string
    {
        if (self::blank($value)) {
            return null;
        }

        $value = ltrim(trim($value), '#');
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return $value === '' ? null : $value;
    }

    private const DATE_FORMATS = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'd/m/y', 'd-m-y', 'm/d/Y'];

    /**
     * Tries each known format in turn, round-tripping the result back through
     * the same format to reject ambiguous matches (e.g. "13/05/2026" can
     * never be m/d/Y since there's no 13th month) instead of guessing.
     */
    public static function normalizeDate(?string $value): ?string
    {
        if (self::blank($value)) {
            return null;
        }

        $value = trim($value);

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public static function blankToNull(mixed $value): mixed
    {
        return self::blank($value) ? null : $value;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
