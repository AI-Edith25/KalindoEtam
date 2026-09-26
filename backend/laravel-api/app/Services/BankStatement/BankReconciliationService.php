<?php

namespace App\Services\BankStatement;

use App\Enums\BankReconciliationStatus;
use App\Enums\BankStatementLineMatchStatus;
use App\Enums\BankStatementStatus;
use App\Enums\DocumentStatus;
use App\Models\BankReconciliationSummary;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\PaymentEntry;
use App\Models\ReceiptEntry;
use App\Repositories\BankReconciliationRepository;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankReconciliationService
{
    public function __construct(private BankReconciliationRepository $repository) {}

    public function recomputeForStatement(BankStatement $statement): void
    {
        if ($statement->period_start === null || $statement->period_end === null) {
            return; // empty statement, nothing to match/summarize
        }

        $this->match($statement->bank_account_id, $statement->period_start->format('Y-m-d'), $statement->period_end->format('Y-m-d'));
        $this->recomputeSummary($statement->bank_account_id, $statement->period_start->format('Y-m-d'), $statement->period_end->format('Y-m-d'));
    }

    /** Runs recompute only for bank accounts that have ever had a statement uploaded -- a no-op everywhere else. */
    public function recomputeIfTracked(string $bankAccountId, string $date): void
    {
        if (! BankStatement::query()->where('bank_account_id', $bankAccountId)->exists()) {
            return;
        }

        $this->match($bankAccountId, $date, $date);
        $this->recomputeSummary($bankAccountId, $date, $date);
    }

    /**
     * Exact date + exact amount match against an unclaimed Payment Voucher (credit
     * side) or Official Receipt (debit side) on the same bank account. Tolerance
     * widens the date window without loosening the amount match.
     */
    public function match(string $bankAccountId, string $dateFrom, string $dateTo, int $toleranceDays = 0): void
    {
        $lines = BankStatementLine::query()
            ->whereHas('bankStatement', fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->where('match_status', BankStatementLineMatchStatus::UNMATCHED)
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->get();

        foreach ($lines as $line) {
            $document = $this->findCandidate($bankAccountId, $line, $toleranceDays);

            if ($document !== null) {
                $line->update([
                    'matched_document_type' => $document->getMorphClass(),
                    'matched_document_id' => $document->id,
                    'match_status' => BankStatementLineMatchStatus::MATCHED,
                ]);
            }
        }
    }

    public function manualMatch(BankStatementLine $line, string $documentType, string $documentId): void
    {
        $line->update([
            'matched_document_type' => $documentType,
            'matched_document_id' => $documentId,
            'match_status' => BankStatementLineMatchStatus::MANUAL_MATCHED,
        ]);
    }

    private function findCandidate(string $bankAccountId, BankStatementLine $line, int $toleranceDays): PaymentEntry|ReceiptEntry|null
    {
        $from = Carbon::parse($line->transaction_date)->subDays($toleranceDays)->format('Y-m-d');
        $to = Carbon::parse($line->transaction_date)->addDays($toleranceDays)->format('Y-m-d');

        if ((float) $line->credit_amount > 0) {
            $claimed = BankStatementLine::query()->where('matched_document_type', (new PaymentEntry())->getMorphClass())->pluck('matched_document_id');

            return PaymentEntry::query()
                ->where('cash_account_id', $bankAccountId)
                ->where('status', DocumentStatus::SUBMITTED)
                ->whereDate('payment_date', '>=', $from)
                ->whereDate('payment_date', '<=', $to)
                ->where('total_amount', $line->credit_amount)
                ->whereNotIn('id', $claimed)
                ->first();
        }

        if ((float) $line->debit_amount > 0) {
            $claimed = BankStatementLine::query()->where('matched_document_type', (new ReceiptEntry())->getMorphClass())->pluck('matched_document_id');

            return ReceiptEntry::query()
                ->where('cash_account_id', $bankAccountId)
                ->where('status', DocumentStatus::SUBMITTED)
                ->whereDate('receipt_date', '>=', $from)
                ->whereDate('receipt_date', '<=', $to)
                ->where('total_amount', $line->debit_amount)
                ->whereNotIn('id', $claimed)
                ->first();
        }

        return null;
    }

    /**
     * Upserts one BankReconciliationSummary row per day in range. A day with no
     * PROCESSED statement covering it is `not_uploaded`, regardless of whether the
     * system side has activity that day -- never fabricated as balanced/zero.
     */
    public function recomputeSummary(string $bankAccountId, string $dateFrom, string $dateTo): void
    {
        $systemTotals = $this->repository->systemTotalsByDate($bankAccountId, $dateFrom, $dateTo);
        $coveredDates = $this->coveredDates($bankAccountId, $dateFrom, $dateTo);

        // GROUP BY DATE(transaction_date), not the raw column -- a bank statement line's date
        // carries a real time-of-day from the source file (e.g. BCA's PostDate), so two lines on
        // the same calendar day but different times would otherwise land in separate groups and
        // silently lose one one another once keyed by day below.
        $statementTotals = BankStatementLine::query()
            ->whereHas('bankStatement', fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->selectRaw('DATE(transaction_date) as date, SUM(debit_amount) as debit_total, SUM(credit_amount) as credit_total')
            ->groupBy(DB::raw('DATE(transaction_date)'))
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->date)->format('Y-m-d'));

        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $key = $date->format('Y-m-d');

            if (! isset($coveredDates[$key])) {
                $this->upsertSummary($bankAccountId, $key, 0, 0, 0, 0, BankReconciliationStatus::NOT_UPLOADED);

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

            $this->upsertSummary($bankAccountId, $key, $system['debit'], $system['credit'], $statementDebit, $statementCredit, $status, $varianceDebit, $varianceCredit);
        }
    }

    /**
     * Every is_cash_bank account gets a row for every day in range -- a day no write path has
     * ever recomputed (no PV/OR submitted, no statement uploaded, nothing to trigger
     * recomputeSummary()) has no persisted BankReconciliationSummary row at all, but that's
     * itself a "not_uploaded" day, not nothing to show. Missing (account, date) pairs are
     * synthesized here at read time rather than requiring some scheduled job to have pre-created
     * them -- exactly the gap that would otherwise hide the Dashboard's whole "you forgot to
     * upload today's statement" alert on a day with zero other activity on that account.
     */
    public function getDailyBalancingSummary(?string $bankAccountId, string $dateFrom, string $dateTo): Collection
    {
        $existing = BankReconciliationSummary::query()
            ->when($bankAccountId, fn ($q) => $q->where('bank_account_id', $bankAccountId))
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateTo)
            ->with('bankAccount')
            ->get();

        $bankAccounts = ChartOfAccount::query()
            ->where('is_cash_bank', true)
            ->when($bankAccountId, fn ($q) => $q->where('id', $bankAccountId))
            ->get();

        $existingKeys = $existing->map(fn ($s) => $s->bank_account_id.'|'.$s->date->format('Y-m-d'))->flip();

        $synthesized = collect();
        foreach ($bankAccounts as $account) {
            foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
                $key = $account->id.'|'.$date->format('Y-m-d');

                if ($existingKeys->has($key)) {
                    continue;
                }

                $summary = new BankReconciliationSummary([
                    'bank_account_id' => $account->id,
                    'date' => $date->format('Y-m-d'),
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
                $summary->setRelation('bankAccount', $account);
                $synthesized->push($summary);
            }
        }

        return $existing->concat($synthesized)
            ->sortBy([['bank_account_id', 'asc'], ['date', 'asc']])
            ->values();
    }

    /**
     * Import view: one row per uploaded statement line in range, "System" = its matched
     * document's amount (null if unmatched). Null $bankAccountId means every bank account.
     *
     * @return array<int, array>
     */
    public function importRows(?string $bankAccountId, string $dateFrom, string $dateTo): array
    {
        $lines = BankStatementLine::query()
            ->when($bankAccountId, fn ($q) => $q->whereHas('bankStatement', fn ($q2) => $q2->where('bank_account_id', $bankAccountId)))
            ->whereDate('transaction_date', '>=', $dateFrom)
            ->whereDate('transaction_date', '<=', $dateTo)
            ->with(['matchedDocument' => function ($morphTo) {
                $morphTo->morphWith([
                    ReceiptEntry::class => ['customer'],
                    PaymentEntry::class => ['supplier', 'expenseAccount'],
                ]);
            }])
            ->orderBy('transaction_date')
            ->get();

        return $lines->map(function (BankStatementLine $line) {
            $statementAmount = (float) $line->credit_amount > 0 ? (float) $line->credit_amount : (float) $line->debit_amount;
            $document = $line->matchedDocument;
            $systemAmount = $document?->total_amount !== null ? (float) $document->total_amount : null;

            return [
                'id' => $line->id,
                'date' => $line->transaction_date->format('Y-m-d'),
                'customer' => $this->partyNameForDocument($document),
                'system_amount' => $systemAmount,
                'statement_amount' => $statementAmount,
                'selisih' => $systemAmount !== null ? round($systemAmount - $statementAmount, 2) : $statementAmount,
                'status' => $line->match_status->value,
                'description' => $line->description,
                'bank_statement_line_id' => $line->id,
                // Credit line -> Payment Voucher (outflow), debit line -> Official Receipt (inflow) --
                // same direction findCandidate() itself matches on. Lets the manual-match picker
                // default to the right document type instead of always guessing Payment Voucher.
                'direction' => (float) $line->credit_amount > 0 ? 'credit' : 'debit',
            ];
        })->all();
    }

    /**
     * System view: one row per Payment Voucher (credit)/Official Receipt (debit) in range,
     * "Statement" = the statement line it's matched to (null if unmatched) -- the mirror image
     * of importRows(), same six-column shape, browsing from the other side. Null $bankAccountId
     * means every bank account.
     *
     * @return array<int, array>
     */
    public function systemRows(?string $bankAccountId, string $dateFrom, string $dateTo): array
    {
        $receipts = ReceiptEntry::query()
            ->when($bankAccountId, fn ($q) => $q->where('cash_account_id', $bankAccountId))
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereDate('receipt_date', '>=', $dateFrom)
            ->whereDate('receipt_date', '<=', $dateTo)
            ->with('customer')
            ->get();

        $payments = PaymentEntry::query()
            ->when($bankAccountId, fn ($q) => $q->where('cash_account_id', $bankAccountId))
            ->where('status', DocumentStatus::SUBMITTED)
            ->whereDate('payment_date', '>=', $dateFrom)
            ->whereDate('payment_date', '<=', $dateTo)
            ->with('supplier', 'expenseAccount')
            ->get();

        $rows = $receipts->map(fn (ReceiptEntry $r) => $this->systemRow($r, $r->receipt_date, $r->customer?->customer_name))
            ->concat($payments->map(fn (PaymentEntry $p) => $this->systemRow($p, $p->payment_date, $p->supplier?->supplier_name ?? $p->expenseAccount?->name)));

        return $rows->sortBy('date')->values()->all();
    }

    private function systemRow(PaymentEntry|ReceiptEntry $document, Carbon $date, ?string $partyName): array
    {
        $matchedLine = BankStatementLine::query()
            ->where('matched_document_type', $document->getMorphClass())
            ->where('matched_document_id', $document->id)
            ->first();
        $statementAmount = $matchedLine !== null
            ? (float) ((float) $matchedLine->credit_amount > 0 ? $matchedLine->credit_amount : $matchedLine->debit_amount)
            : null;
        $systemAmount = (float) $document->total_amount;

        return [
            'id' => $document->id,
            'date' => $date->format('Y-m-d'),
            'customer' => $partyName,
            'system_amount' => $systemAmount,
            'statement_amount' => $statementAmount,
            'selisih' => $statementAmount !== null ? round($systemAmount - $statementAmount, 2) : $systemAmount,
            'status' => $matchedLine?->match_status->value ?? 'unmatched',
            'document_number' => $document->document_number,
        ];
    }

    private function partyNameForDocument(PaymentEntry|ReceiptEntry|null $document): ?string
    {
        return match (true) {
            $document instanceof ReceiptEntry => $document->customer?->customer_name,
            $document instanceof PaymentEntry => $document->supplier?->supplier_name ?? $document->expenseAccount?->name,
            default => null,
        };
    }

    /** @return array<string, true> Y-m-d dates covered by at least one PROCESSED statement. */
    private function coveredDates(string $bankAccountId, string $dateFrom, string $dateTo): array
    {
        $statements = BankStatement::query()
            ->where('bank_account_id', $bankAccountId)
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
        string $bankAccountId,
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
        // would violate the (bank_account_id, date) unique constraint on the second run.
        $existing = BankReconciliationSummary::query()
            ->where('bank_account_id', $bankAccountId)
            ->whereDate('date', $date)
            ->first();

        if ($existing !== null) {
            $existing->update($attributes);
        } else {
            BankReconciliationSummary::query()->create(array_merge(['bank_account_id' => $bankAccountId, 'date' => $date], $attributes));
        }
    }
}
