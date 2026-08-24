<?php

namespace App\Services;

use App\Repositories\JournalListRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves Journal List's export query + group label — the journal-line-level
 * shape JournalListExport walks. Screen data lives in CashBookService instead
 * (document-level, a different query entirely — see both classes' docblocks).
 */
class JournalListService
{
    protected const GROUP_LABELS = [
        'all' => 'Cash Book Transaction',
        'receipt' => 'Cash Book-Receipt',
        'payment' => 'Cash Book-Payment',
    ];

    public function __construct(protected JournalListRepository $journalListRepository) {}

    public function exportQuery(array $filters, string $view): Builder
    {
        return $this->journalListRepository->cashBookJournalEntries($filters, $view);
    }

    public function groupLabel(string $view): string
    {
        return self::GROUP_LABELS[$view] ?? self::GROUP_LABELS['all'];
    }
}
