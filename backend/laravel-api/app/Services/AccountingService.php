<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\JournalEntry;
use App\Repositories\ChartOfAccountRepository;
use App\Repositories\JournalEntryRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * The single gateway business modules use to record accounting
 * transactions — "Business Module -> Accounting Service -> Journal
 * Entry -> Journal Detail -> Ledger". Business modules never write to
 * journal_entries/journal_entry_lines directly; they only ever call
 * postForDocument() with the debit/credit breakdown they've already
 * computed (e.g. Invoice::journalLines()), never touching a ledger table.
 */
class AccountingService
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected JournalEntryRepository $journalEntryRepository,
        protected ChartOfAccountRepository $chartOfAccountRepository,
    ) {}

    /**
     * @param  array<int, array{account: string, type: string, amount: float}>  $lines  From the business document's own journalLines() method.
     */
    public function postForDocument(Model $referenceDocument, array $lines, string $description, ?string $postingDate = null): JournalEntry
    {
        $journalEntry = $this->journalEntryService->create([
            'posting_date' => $postingDate ?? now()->toDateString(),
            'description' => $description,
            'reference_type' => $referenceDocument->getMorphClass(),
            'reference_id' => $referenceDocument->getKey(),
            'lines' => $this->resolveLines($lines),
        ]);

        return $this->journalEntryService->post($journalEntry);
    }

    /**
     * Finds and reverses the still-active posted Journal Entry for a given
     * source document, if one exists. Not called by anything this sprint —
     * a future Credit Note module is the intended caller, the same way
     * Invoice/Receipt Entry call postForDocument() from their own submit().
     */
    public function reverseForDocument(Model $referenceDocument): ?JournalEntry
    {
        $journalEntry = $this->journalEntryRepository->findActivePostedByReference(
            $referenceDocument->getMorphClass(),
            (string) $referenceDocument->getKey(),
        );

        return $journalEntry ? $this->journalEntryService->reverse($journalEntry) : null;
    }

    /** Maps journalLines()' {account: code, type: debit|credit, amount} shape onto {chart_of_account_id, debit, credit}. */
    protected function resolveLines(array $lines): array
    {
        return array_map(function (array $line) {
            $account = $this->chartOfAccountRepository->findActiveByCode($line['account']);

            if ($account === null) {
                throw new BusinessException("Unknown or inactive chart of account code: {$line['account']}.");
            }

            return [
                'chart_of_account_id' => $account->id,
                'debit' => $line['type'] === 'debit' ? $line['amount'] : 0,
                'credit' => $line['type'] === 'credit' ? $line['amount'] : 0,
            ];
        }, $lines);
    }
}
