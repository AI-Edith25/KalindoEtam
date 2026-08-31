<?php

namespace App\Services\Import;

/**
 * Finds the real header row in a file that has preamble rows above it
 * (title, company name, marker rows like "AmendYN") and/or a type-spec row
 * right after the header (e.g. "40 (t)", "12 (n)") — both common in legacy
 * exports.
 *
 * Signal: every column any row below a real header actually populates must
 * already be one of the header's own columns (the header may have extra,
 * never-used optional columns, but never fewer than what the data uses) —
 * among rows that satisfy this, the one with the *fewest* such unused extra
 * columns, earliest first on a tie, is the real header. This needs no
 * assumption about numeric vs text columns, so it works for FK-less
 * text-only master data too, not just Items' numeric price column.
 *
 * // ponytail: a small, fixed lookahead + minimum-window heuristic, not a
 * general table-detection algorithm. Ceilings: (1) a header row missing a
 * column label that its own data actually fills would be rejected outright
 * (union-not-subset check) — real headers always label every column they
 * use, so this hasn't come up; (2) fewer than MIN_WINDOW data rows below a
 * genuine header falls back to row 1 — acceptable, single/near-empty-row
 * files are already a documented edge case elsewhere in this module.
 */
final class HeaderDetector
{
    private const SCAN_LIMIT = 15;

    private const LOOKAHEAD = 10;

    // Need at least this many rows below a candidate to trust its shape —
    // otherwise a single data row trivially "matches itself" and can
    // outscore the real header (which may have unused optional columns).
    private const MIN_WINDOW = 2;

    private const SPEC_ROW_PATTERN = '/^\d+\s*\(\s*[tn]\s*\)$/i';

    /** @param  array<int, array<int, mixed>>  $rawRows @return array{header_row: int, data_start_row: int} */
    public static function detect(array $rawRows): array
    {
        $sample = array_slice($rawRows, 0, self::SCAN_LIMIT);
        $count = count($sample);
        $best = null;

        for ($i = 0; $i < $count; $i++) {
            $signature = self::signature($sample[$i]);

            if (count($signature) < 2) {
                continue;
            }

            $window = array_slice($sample, $i + 1, self::LOOKAHEAD);

            if (count($window) < self::MIN_WINDOW) {
                continue;
            }

            $union = [];
            foreach ($window as $row) {
                $union = array_values(array_unique([...$union, ...self::signature($row)]));
            }

            if (array_diff($union, $signature) !== []) {
                continue;
            }

            $extra = count($signature) - count($union);

            if ($best === null || $extra < $best['extra']) {
                $best = ['index' => $i, 'extra' => $extra];
            }
        }

        if ($best === null) {
            return ['header_row' => 1, 'data_start_row' => 2];
        }

        $headerRow = $best['index'] + 1;
        $dataStartRow = $headerRow + 1;

        if (self::looksLikeSpecRow($sample[$best['index'] + 1] ?? [])) {
            $dataStartRow++;
        }

        return ['header_row' => $headerRow, 'data_start_row' => $dataStartRow];
    }

    /** @return int[] column indices with a non-blank value */
    private static function signature(array $row): array
    {
        $indices = [];

        foreach (array_values($row) as $i => $value) {
            if (DataCleaner::blankToNull($value) !== null) {
                $indices[] = $i;
            }
        }

        return $indices;
    }

    private static function looksLikeSpecRow(array $row): bool
    {
        $nonBlank = array_filter($row, fn ($v) => DataCleaner::blankToNull($v) !== null);

        if ($nonBlank === []) {
            return false;
        }

        $matching = array_filter($nonBlank, fn ($v) => preg_match(self::SPEC_ROW_PATTERN, trim((string) $v)) === 1);

        return count($matching) / count($nonBlank) >= 0.5;
    }
}
