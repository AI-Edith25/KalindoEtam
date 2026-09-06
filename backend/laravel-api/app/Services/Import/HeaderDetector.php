<?php

namespace App\Services\Import;

/**
 * Finds the real header row in a file that has preamble rows above it
 * (title, company name, marker rows like "AmendYN") and/or a type-spec row
 * right after the header (e.g. "40 (t)", "12 (n)") — both common in legacy
 * exports.
 *
 * Primary signal (when the target module's fields are known): a row whose
 * cell values exactly match at least MIN_SEMANTIC_MATCHES of the template's
 * own field name/label/synonym vocabulary is the header — this is what a
 * header row actually *is* for a given import, and sidesteps the structural
 * heuristic below entirely.
 *
 * Fallback signal (fields unknown, or no row hits the semantic threshold):
 * every column any row below a real header actually populates must already
 * be one of the header's own columns (the header may have extra, never-used
 * optional columns, but never fewer than what the data uses) — among rows
 * that satisfy this, the one with the *fewest* such unused extra columns,
 * earliest first on a tie, is the real header. Needs no assumption about
 * numeric vs text columns, so it works for FK-less text-only master data
 * too, not just Items' numeric price column.
 *
 * The structural fallback alone can mis-pick a later data row over the real
 * header: a legacy export with many sparsely-used optional columns (e.g.
 * Sales Person's Address/Area/Telephone/Fax, usually blank) means the real
 * header — which must span every column — scores worse ("extra" columns)
 * than an ordinary data row that happens to share its populated-column
 * shape with the rows right below it. The semantic pass above is checked
 * first specifically to catch this case, since it doesn't care how sparse
 * the data is — only whether a row's own text matches known field names.
 *
 * // ponytail: a small, fixed lookahead + minimum-window heuristic, not a
 * general table-detection algorithm. Ceilings: (1) a header row missing a
 * column label that its own data actually fills would be rejected outright
 * by the structural fallback (union-not-subset check) — real headers always
 * label every column they use, so this hasn't come up; (2) fewer than
 * MIN_WINDOW data rows below a genuine header falls back to row 1 in the
 * structural path — acceptable, single/near-empty-row files are already a
 * documented edge case elsewhere in this module.
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

    // A real header row for a real template covers several of its fields at
    // once; a single coincidental match (e.g. a Status data cell literally
    // reading "Active", which is also an is_active synonym) isn't enough.
    private const MIN_SEMANTIC_MATCHES = 2;

    /**
     * @param  array<int, array<int, mixed>>  $rawRows
     * @param  \App\Services\Import\ImportFieldDefinition[]  $fields  known target fields for this module, used for the semantic pass — omit to run the structural heuristic only
     * @return array{header_row: int, data_start_row: int}
     */
    public static function detect(array $rawRows, array $fields = []): array
    {
        $sample = array_slice($rawRows, 0, self::SCAN_LIMIT);

        $semantic = self::bestSemanticMatch($sample, $fields);
        if ($semantic !== null) {
            return $semantic;
        }

        return self::bestStructuralMatch($sample);
    }

    /** @return array{header_row: int, data_start_row: int}|null */
    private static function bestSemanticMatch(array $sample, array $fields): ?array
    {
        if ($fields === []) {
            return null;
        }

        $vocabulary = [];
        foreach ($fields as $field) {
            foreach ([$field->name, $field->label, ...$field->synonyms] as $term) {
                $vocabulary[self::normalize($term)] = true;
            }
        }

        $best = null;

        foreach ($sample as $i => $row) {
            $matches = 0;

            foreach ($row as $cell) {
                $value = DataCleaner::blankToNull($cell);
                if ($value !== null && isset($vocabulary[self::normalize((string) $value)])) {
                    $matches++;
                }
            }

            if ($matches >= self::MIN_SEMANTIC_MATCHES && ($best === null || $matches > $best['matches'])) {
                $best = ['index' => $i, 'matches' => $matches];
            }
        }

        if ($best === null) {
            return null;
        }

        $headerRow = $best['index'] + 1;
        $dataStartRow = $headerRow + 1;

        if (self::looksLikeSpecRow($sample[$best['index'] + 1] ?? [])) {
            $dataStartRow++;
        }

        return ['header_row' => $headerRow, 'data_start_row' => $dataStartRow];
    }

    /** @return array{header_row: int, data_start_row: int} */
    private static function bestStructuralMatch(array $sample): array
    {
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

    /** Same rule as ImportBatchService::normalizeHeader() — kept independent since the two never need to change in lockstep. */
    private static function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }
}
