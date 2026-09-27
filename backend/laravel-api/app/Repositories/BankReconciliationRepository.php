<?php

namespace App\Repositories;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Collection;

/**
 * "System side" of the reconciliation -- every Cash Book Transaction (Official Receipt/Payment
 * Voucher) journal entry, grouped by day, regardless of which cash/bank account they were paid
 * from/received into (Bank Reconciliation combines every account into one bucket -- see the
 * migration that dropped bank_account_id from this feature). Reuses
 * JournalListRepository::cashBookJournalEntries() -- the same read xlsJournalList*.xlsx exports
 * from -- rather than reading receipt_entries/payment_entries directly, so this and the Journal
 * List/General Ledger reports can never disagree, and so this correctly reflects what actually
 * posted (status=SUBMITTED via the Accounting Engine), not just what was saved.
 *
 * Amounts use bank-statement convention (debit = uang keluar, credit = uang masuk -- see
 * BcaStatementParser/MandiriStatementParser), the opposite of ledger/asset convention, so a
 * Payment Voucher's cash leg (a ledger credit) lands in 'debit' here and an Official Receipt's
 * cash leg (a ledger debit) lands in 'credit' -- this is what makes these totals directly
 * comparable to BankStatementLine's debit_amount/credit_amount without inversion.
 */
class BankReconciliationRepository
{
    public function __construct(private JournalListRepository $journalListRepository) {}

    /**
     * One row per Cash Book journal entry in range, reduced to its cash/bank leg only (the
     * contra Piutang/Hutang/expense leg is dropped -- it doesn't represent bank movement).
     *
     * @return array<int, array{document_number: ?string, date: string, keterangan: ?string, tipe: 'masuk'|'keluar', debit: float, kredit: float}>
     */
    public function cashBookRows(string $dateFrom, string $dateTo): array
    {
        $entries = $this->journalListRepository
            ->cashBookJournalEntries(['date_from' => $dateFrom, 'date_to' => $dateTo], 'all')
            ->get();

        return $entries
            ->map(function (JournalEntry $entry) {
                $bankLine = $entry->lines->first(fn (JournalEntryLine $line) => $line->chartOfAccount?->is_cash_bank);

                if ($bankLine === null) {
                    return null; // shouldn't happen for a real Cash Book entry, but never fabricate a row without one
                }

                $isReceipt = $entry->reference_type === 'receipt_entry';
                $amount = $isReceipt ? (float) $bankLine->debit : (float) $bankLine->credit;

                return [
                    'document_number' => $entry->resolved_document_number,
                    'date' => $entry->posting_date->format('Y-m-d'),
                    'keterangan' => $this->remark($bankLine, $entry->lines),
                    'tipe' => $isReceipt ? 'masuk' : 'keluar',
                    'debit' => $isReceipt ? 0.0 : $amount,
                    'kredit' => $isReceipt ? $amount : 0.0,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<string, array{debit: float, credit: float}> keyed by Y-m-d */
    public function systemTotalsByDate(string $dateFrom, string $dateTo): array
    {
        return collect($this->cashBookRows($dateFrom, $dateTo))
            ->groupBy('date')
            ->map(fn (Collection $rows) => [
                'debit' => $rows->sum('debit'),
                'credit' => $rows->sum('kredit'),
            ])
            ->all();
    }

    /**
     * The bank leg's own description, falling back to summarizing sibling lines -- same
     * "who/what is this cash movement for" reconstruction JournalListExport::particulars() uses,
     * minus its "{code} - {name} -" prefix (not needed here, only the remark itself).
     */
    private function remark(JournalEntryLine $line, Collection $siblings): ?string
    {
        if ($line->description) {
            return $line->description;
        }

        $summary = $siblings
            ->reject(fn (JournalEntryLine $sibling) => $sibling->id === $line->id)
            ->map(fn (JournalEntryLine $sibling) => $sibling->chartOfAccount->name.($sibling->description ? " ({$sibling->description})" : ''))
            ->implode('; ');

        return $summary !== '' ? $summary : null;
    }
}
