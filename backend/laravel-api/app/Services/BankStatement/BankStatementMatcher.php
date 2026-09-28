<?php

namespace App\Services\BankStatement;

/**
 * Nominal-only matching between Cash Book (OR/PV) rows and mutasi bank statement lines for one
 * day and one account -- names are deliberately never compared (a transfer's sender name never
 * matches the customer/supplier name in the system; that's why the aggregate-only comparison in
 * BankReconciliationService exists at all). 1:1 pairing only; many-to-one/one-to-many grouping
 * is a deferred phase-2 per spec.
 */
class BankStatementMatcher
{
    private const TOLERANCE = 2000.0;

    /** ponytail: keyword heuristic, not a parsed fee flag -- widen this list if a bank's admin-fee wording doesn't contain any of these. */
    private const FEE_KEYWORDS = ['biaya', 'adm', 'fee'];

    /**
     * Folds an admin-fee line (e.g. "BIAYA TXN") into the immediately preceding line when both
     * are on the same side (debit/debit or kredit/kredit) -- a transfer split into "transfer" +
     * "fee" by the bank becomes one combined amount, matchable against the PV/OR's single total.
     *
     * @param array<int, array{description: string, debit: float, kredit: float}> $mutasiRows
     * @return array<int, array{description: string, debit: float, kredit: float}>
     */
    public function mergeAdminFees(array $mutasiRows): array
    {
        $merged = [];

        foreach ($mutasiRows as $row) {
            $previous = $merged === [] ? null : $merged[count($merged) - 1];

            if ($previous !== null && $this->looksLikeFee($row['description']) && $this->sameDirection($previous, $row)) {
                $lastIndex = count($merged) - 1;
                $merged[$lastIndex]['debit'] += $row['debit'];
                $merged[$lastIndex]['kredit'] += $row['kredit'];

                continue;
            }

            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * Greedy 1:1 pairing within each direction (debit-vs-debit, kredit-vs-kredit), smallest
     * amount difference first, tie-broken by original order (both inputs already sorted
     * chronologically) -- deterministic given the same day's data. Rows left over on either side
     * are reported as their own 'tidak_cocok' row with the other side null.
     *
     * @param array<int, array{debit: float, kredit: float}> $jlRows
     * @param array<int, array{debit: float, kredit: float}> $mutasiRows
     * @return array<int, array{jl: ?array, mutasi: ?array, status: 'cocok'|'tidak_cocok', selisih: float}>
     */
    public function match(array $jlRows, array $mutasiRows): array
    {
        return [
            ...$this->matchDirection($jlRows, $mutasiRows, 'debit'),
            ...$this->matchDirection($jlRows, $mutasiRows, 'kredit'),
        ];
    }

    /** @return array<int, array{jl: ?array, mutasi: ?array, status: 'cocok'|'tidak_cocok', selisih: float}> */
    private function matchDirection(array $jlRows, array $mutasiRows, string $field): array
    {
        $jlSide = $this->indexed($jlRows, $field);
        $mutasiSide = $this->indexed($mutasiRows, $field);

        $candidates = [];
        foreach ($jlSide as $jlIndex => $jlRow) {
            foreach ($mutasiSide as $mutasiIndex => $mutasiRow) {
                $diff = abs($jlRow[$field] - $mutasiRow[$field]);
                if ($diff <= self::TOLERANCE) {
                    $candidates[] = ['jl' => $jlIndex, 'mutasi' => $mutasiIndex, 'diff' => $diff];
                }
            }
        }

        // Stable sort (PHP 8's usort is stable) -- smallest diff first, original index order breaks ties.
        usort($candidates, fn ($a, $b) => $a['diff'] <=> $b['diff']);

        $usedJl = [];
        $usedMutasi = [];
        $results = [];

        foreach ($candidates as $candidate) {
            if (isset($usedJl[$candidate['jl']]) || isset($usedMutasi[$candidate['mutasi']])) {
                continue;
            }

            $usedJl[$candidate['jl']] = true;
            $usedMutasi[$candidate['mutasi']] = true;

            $results[] = [
                'jl' => $jlSide[$candidate['jl']],
                'mutasi' => $mutasiSide[$candidate['mutasi']],
                'status' => 'cocok',
                'selisih' => round($jlSide[$candidate['jl']][$field] - $mutasiSide[$candidate['mutasi']][$field], 2),
            ];
        }

        foreach ($jlSide as $jlIndex => $jlRow) {
            if (! isset($usedJl[$jlIndex])) {
                $results[] = ['jl' => $jlRow, 'mutasi' => null, 'status' => 'tidak_cocok', 'selisih' => 0.0];
            }
        }

        foreach ($mutasiSide as $mutasiIndex => $mutasiRow) {
            if (! isset($usedMutasi[$mutasiIndex])) {
                $results[] = ['jl' => null, 'mutasi' => $mutasiRow, 'status' => 'tidak_cocok', 'selisih' => 0.0];
            }
        }

        return $results;
    }

    /** @return array<int, array{debit: float, kredit: float}> Rows with a nonzero amount on $field, re-indexed from 0. */
    private function indexed(array $rows, string $field): array
    {
        return array_values(array_filter($rows, fn ($row) => (float) $row[$field] > 0));
    }

    private function looksLikeFee(string $description): bool
    {
        $lower = strtolower($description);

        foreach (self::FEE_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function sameDirection(array $a, array $b): bool
    {
        return ((float) $a['debit'] > 0) === ((float) $b['debit'] > 0);
    }
}
