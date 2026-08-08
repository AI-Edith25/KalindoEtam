<?php

namespace App\Services;

use App\Models\JournalEntryLine;
use App\Repositories\JournalListRepository;
use Illuminate\Support\Arr;

/**
 * Groups cash/bank journal lines by transaction type (e.g. "Petty
 * Cash-Receipt", "Cash Book-Receipt") with a subtotal per group — a
 * presentation layer over the same journal_entries/journal_entry_lines
 * tables every other Accounting Report reads, never written to here.
 */
class JournalListService
{
    public function __construct(protected JournalListRepository $journalListRepository) {}

    /**
     * @return array{groups: array<int, array{key: string, label: string, rows: array, subtotal: array{debit: float, credit: float}}>, grand_total: array{debit: float, credit: float}}
     */
    public function summarize(array $filters): array
    {
        $lines = $this->journalListRepository->cashBankLines($filters);

        $rows = $lines->map(fn (JournalEntryLine $line) => $this->buildRow($line));

        $groups = $rows
            ->groupBy('group_key')
            ->map(function ($groupRows, string $groupKey) {
                $first = $groupRows->first();

                return [
                    'key' => $groupKey,
                    'label' => $first['group_label'],
                    'sort' => $first['group_sort'],
                    'rows' => $groupRows->map(fn (array $row) => Arr::except($row, ['group_key', 'group_label', 'group_sort']))->values()->all(),
                    'subtotal' => [
                        'debit' => (float) $groupRows->sum('debit'),
                        'credit' => (float) $groupRows->sum('credit'),
                    ],
                ];
            })
            ->sortBy('sort')
            ->values()
            ->map(fn (array $group) => Arr::except($group, ['sort']))
            ->all();

        return [
            'groups' => $groups,
            'grand_total' => [
                'debit' => (float) $rows->sum('debit'),
                'credit' => (float) $rows->sum('credit'),
            ],
        ];
    }

    /**
     * A manual transfer between two cash/bank accounts (e.g. Dr Bank Mandiri /
     * Cr Kas) produces two rows here, one per side — each lands in a
     * *different* group (the debited account's Receipt group, the credited
     * account's Payment group), showing the other as its Particulars. This
     * is correct double-entry reporting, not a double-count: no single
     * group's subtotal ever sums both sides of the same transfer.
     */
    protected function buildRow(JournalEntryLine $line): array
    {
        $account = $line->chartOfAccount;
        $journalEntry = $line->journalEntry;
        $isReceipt = (float) $line->debit > 0;

        $category = $account->cash_bank_category?->label() ?? $account->name;
        $direction = $isReceipt ? 'Receipt' : 'Payment';

        $particulars = $journalEntry->lines
            ->reject(fn (JournalEntryLine $sibling) => $sibling->id === $line->id)
            ->map(fn (JournalEntryLine $sibling) => $sibling->chartOfAccount->name . ($sibling->description ? " ({$sibling->description})" : ''))
            ->implode('; ');

        return [
            'group_key' => "{$category}-{$direction}",
            'group_label' => "{$category}-{$direction}",
            // Receipt groups before Payment groups, matching the stakeholder reference's own
            // ordering (Petty Cash-Receipt, Cash Book-Receipt, ..., Petty Cash-Payment, ...).
            'group_sort' => ($isReceipt ? 0 : 1) . $category,
            'transaction' => $journalEntry->referenceDocument?->document_number ?? $journalEntry->document_number,
            'date' => $journalEntry->posting_date?->format('Y-m-d'),
            'ref_no' => $journalEntry->document_number,
            'particulars' => $particulars,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
        ];
    }
}
