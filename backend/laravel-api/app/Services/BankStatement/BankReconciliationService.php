<?php

namespace App\Services\BankStatement;

use App\Enums\BankReconciliationStatus;
use App\Enums\BankStatementStatus;
use App\Models\BankReconciliationSummary;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Repositories\BankReconciliationRepository;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Combines every cash/bank account into one bucket, deliberately -- confirmed with the user that
 * a per-account split doesn't match reality here: the mutasi/journal-list files this compares
 * against have no reliable structured "which account" field to hang one on, and only one physical
 * source has ever actually been reconciled through this feature. See the migration that dropped
 * bank_account_id from bank_statements/bank_reconciliation_summaries for the full rationale.
 */
class BankReconciliationService
{
    public function __construct(private BankReconciliationRepository $repository) {}

    public function recomputeForStatement(BankStatement $statement): void
    {
        if ($statement->period_start === null || $statement->period_end === null) {
            return; // empty statement, nothing to summarize
        }

        $this->recomputeSummary($statement->period_start->format('Y-m-d'), $statement->period_end->format('Y-m-d'));
    }

    /** Runs recompute only when at least one statement has ever been uploaded -- a no-op everywhere else. */
    public function recomputeIfTracked(string $date): void
    {
        if (! BankStatement::query()->exists()) {
            return;
        }

        $this->recomputeSummary($date, $date);
    }

    /**
     * Upserts one BankReconciliationSummary row per day in range. A day with no
     * PROCESSED statement covering it is `not_uploaded`, regardless of whether the
     * system side has activity that day -- never fabricated as balanced/zero.
     */
    public function recomputeSummary(string $dateFrom, string $dateTo): void
    {
        $systemTotals = $this->repository->systemTotalsByDate($dateFrom, $dateTo);
        $coveredDates = $this->coveredDates($dateFrom, $dateTo);

        // GROUP BY DATE(transaction_date), not the raw column -- a bank statement line's date
        // carries a real time-of-day from the source file (e.g. BCA's PostDate), so two lines on
        // the same calendar day but different times would otherwise land in separate groups and
        // silently lose one one another once keyed by day below.
        $statementTotals = BankStatementLine::query()
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->selectRaw('DATE(transaction_date) as date, SUM(debit_amount) as debit_total, SUM(credit_amount) as credit_total')
            ->groupBy(DB::raw('DATE(transaction_date)'))
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->format('Y-m-d'));

        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $key = $date->format('Y-m-d');

            if (! isset($coveredDates[$key])) {
                $this->upsertSummary($key, 0, 0, 0, 0, BankReconciliationStatus::NOT_UPLOADED);

                continue;
            }

            $system = $systemTotals[$key] ?? ['debit' => 0.0, 'credit' => 0.0];
            $statement = $statementTotals[$key] ?? null;
            $statementDebit = (float) ($statement->debit_total ?? 0);
            $statementCredit = (float) ($statement->credit_total ?? 0);

            $varianceDebit = round($system['debit'] - $statementDebit, 2);
            $varianceCredit = round($system['credit'] - $statementCredit, 2);
            $status = (abs($varianceDebit) < 0.01 && abs($varianceCredit) < 0.01)
                ? BankReconciliationStatus::BALANCED
                : BankReconciliationStatus::UNBALANCED;

            $this->upsertSummary($key, $system['debit'], $system['credit'], $statementDebit, $statementCredit, $status, $varianceDebit, $varianceCredit);
        }
    }

    /**
     * One row per day in range -- a day no write path has ever recomputed (no PV/OR submitted, no
     * statement uploaded, nothing to trigger recomputeSummary()) has no persisted
     * BankReconciliationSummary row at all, but that's itself a "not_uploaded" day, not nothing to
     * show. Missing dates are synthesized here at read time rather than requiring some scheduled
     * job to have pre-created them -- exactly the gap that would otherwise hide the Dashboard's
     * whole "you forgot to upload today's statement" alert on a day with zero other activity.
     */
    public function getDailyBalancingSummary(string $dateFrom, string $dateTo): Collection
    {
        $existing = BankReconciliationSummary::query()
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->get();

        $existingKeys = $existing->map(fn ($s) => $s->date->format('Y-m-d'))->flip();

        $synthesized = collect();
        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $key = $date->format('Y-m-d');

            if ($existingKeys->has($key)) {
                continue;
            }

            $summary = new BankReconciliationSummary([
                'date' => $key,
                'system_debit_total' => 0,
                'system_credit_total' => 0,
                'statement_debit_total' => 0,
                'statement_credit_total' => 0,
                'variance_debit' => 0,
                'variance_credit' => 0,
                'status' => BankReconciliationStatus::NOT_UPLOADED,
                'generated_at' => now(),
            ]);
            $summary->id = (string) Str::uuid();
            $synthesized->push($summary);
        }

        return $existing->concat($synthesized)->sortBy('date')->values();
    }

    /** "See the file" on a day's row -- the uploaded file(s) covering that day (the "folder" contents). */
    public function dayDetail(string $date): array
    {
        $statementIds = BankStatementLine::query()
            ->whereDate('transaction_date', $date)
            ->pluck('bank_statement_id')
            ->unique();

        $files = BankStatement::query()
            ->whereIn('id', $statementIds)
            ->with('creator')
            ->orderBy('created_at')
            ->get()
            ->map(fn (BankStatement $statement) => [
                'id' => $statement->id,
                'original_filename' => $statement->original_filename,
                'uploaded_at' => $statement->created_at?->toIso8601String(),
                'uploaded_by' => $statement->creator?->name,
            ])
            ->all();

        return ['files' => $files];
    }

    /**
     * Detail tab: Cash Book (Official Receipt/Payment Voucher, via
     * BankReconciliationRepository::cashBookRows() -- the same read recomputeSummary() uses) for
     * one day, compared against that day's uploaded bank statement at the aggregate level only.
     * Deliberately never row-by-row: a transfer's sender name never matches the customer/supplier
     * name in the system, so per-line matching only ever produced false "Unbalanced" results.
     * Balanced within Rp 1.000 per category (admin fees/rounding), not just exact-zero.
     */
    public function comparisonRows(string $date): array
    {
        $rows = $this->repository->cashBookRows($date, $date);
        $totalDebit = round(array_sum(array_column($rows, 'debit')), 2);
        $totalKredit = round(array_sum(array_column($rows, 'kredit')), 2);

        // orderBy(id) tie-breaks same-timestamp lines (Mandiri has no intraday time) by insertion
        // order -- HasUuids' ordered UUIDs sort the same way the source file's rows were parsed.
        $dayLines = BankStatementLine::query()
            ->whereDate('transaction_date', $date)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $totalKeluar = round((float) $dayLines->sum('debit_amount'), 2);
        $totalMasuk = round((float) $dayLines->sum('credit_amount'), 2);

        $previousLine = BankStatementLine::query()
            ->whereDate('transaction_date', '<', $date)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->first();

        return [
            'rows' => $rows,
            'totals' => [
                'debit' => $totalDebit,
                'kredit' => $totalKredit,
                'selisih' => round($totalKredit - $totalDebit, 2),
            ],
            'bank_mutasi' => [
                'saldo_awal' => $previousLine?->running_balance !== null ? (float) $previousLine->running_balance : null,
                'total_masuk' => $totalMasuk,
                'total_keluar' => $totalKeluar,
                'saldo_akhir' => $dayLines->last()?->running_balance !== null ? (float) $dayLines->last()->running_balance : null,
            ],
            'comparison' => [
                'debit' => $this->categoryComparison($totalDebit, $totalKeluar),
                'kredit' => $this->categoryComparison($totalKredit, $totalMasuk),
            ],
        ];
    }

    /** @return array{cash_book: float, bank: float, variance: float, status: BankReconciliationStatus} */
    private function categoryComparison(float $cashBook, float $bank): array
    {
        $variance = round($cashBook - $bank, 2);

        return [
            'cash_book' => $cashBook,
            'bank' => $bank,
            'variance' => $variance,
            'status' => abs($variance) <= 1000 ? BankReconciliationStatus::BALANCED : BankReconciliationStatus::UNBALANCED,
        ];
    }

    /** @return array<string, true> Y-m-d dates covered by at least one PROCESSED statement. */
    private function coveredDates(string $dateFrom, string $dateTo): array
    {
        $statements = BankStatement::query()
            ->where('status', BankStatementStatus::PROCESSED)
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereDate('period_start', '<=', $dateTo)
            ->whereDate('period_end', '>=', $dateFrom)
            ->get(['period_start', 'period_end']);

        $covered = [];
        foreach ($statements as $statement) {
            foreach (CarbonPeriod::create($statement->period_start, $statement->period_end) as $date) {
                $covered[$date->format('Y-m-d')] = true;
            }
        }

        return $covered;
    }

    private function upsertSummary(
        string $date,
        float $systemDebit,
        float $systemCredit,
        float $statementDebit,
        float $statementCredit,
        BankReconciliationStatus $status,
        float $varianceDebit = 0,
        float $varianceCredit = 0,
    ): void {
        $attributes = [
            'system_debit_total' => $systemDebit,
            'system_credit_total' => $systemCredit,
            'statement_debit_total' => $statementDebit,
            'statement_credit_total' => $statementCredit,
            'variance_debit' => $varianceDebit,
            'variance_credit' => $varianceCredit,
            'status' => $status,
            'generated_at' => now(),
        ];

        // Not updateOrCreate(['date' => $date], ...) -- its lookup builds a raw where()
        // with $date as given ('Y-m-d'), but a 'date'-cast column is stored as a full
        // "Y-m-d 00:00:00" string, so that lookup would never match an existing row and
        // would violate the date unique constraint on the second run.
        $existing = BankReconciliationSummary::query()->whereDate('date', $date)->first();

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            BankReconciliationSummary::query()->create(array_merge(['date' => $date], $attributes));
        }
    }
}
