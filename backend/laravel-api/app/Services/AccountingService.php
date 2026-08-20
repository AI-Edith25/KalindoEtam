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
        $counterparty = $this->counterpartyName($referenceDocument);
        $description = $counterparty ? "{$description} - {$counterparty}" : $description;

        $journalEntry = $this->journalEntryService->create([
            'posting_date' => $postingDate ?? now()->toDateString(),
            'description' => $description,
            'reference_type' => $referenceDocument->getMorphClass(),
            'reference_id' => $referenceDocument->getKey(),
            'lines' => $this->resolveLines($lines, $description),
        ]);

        return $this->journalEntryService->post($journalEntry);
    }

    /**
     * Customer/Supplier name for the document, so Journal List / General
     * Ledger rows read as "Receipt RC-0001 - TOKO RESTU BUMI" instead of a
     * generic account purpose repeated across every same-type row. Duck-typed
     * on the two relation names business documents actually use, rather than
     * an interface every future document type would have to implement.
     */
    protected function counterpartyName(Model $referenceDocument): ?string
    {
        foreach (['customer', 'supplier'] as $relation) {
            if (method_exists($referenceDocument, $relation)) {
                $related = $referenceDocument->{$relation};

                if ($related !== null) {
                    return $related->customer_name ?? $related->supplier_name ?? null;
                }
            }
        }

        return null;
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

    /**
     * Indonesian description of what each hardcoded account code (see the
     * various Models' journalLines()) is used for — not the CoA's own
     * `name` field, which a company may customize or, as here, omit
     * entirely. Only used to make resolveLines()' error actionable.
     */
    protected const ACCOUNT_PURPOSE = [
        '1100' => 'Kas/Bank',
        '1150' => 'Uang Muka Pelanggan',
        '1200' => 'Piutang Usaha',
        '2100' => 'Pajak',
        '4000' => 'Pendapatan Penjualan',
        '4050' => 'Retur Penjualan',
        '4100' => 'Pendapatan Lain-lain',
        '4900' => 'Diskon',
    ];

    /** Maps journalLines()' {account: code, type: debit|credit, amount} shape onto {chart_of_account_id, debit, credit}. */
    protected function resolveLines(array $lines, ?string $defaultDescription = null): array
    {
        return array_map(function (array $line) use ($defaultDescription) {
            $account = $this->chartOfAccountRepository->findActiveByCode($line['account']);

            if ($account === null) {
                $purpose = self::ACCOUNT_PURPOSE[$line['account']] ?? null;
                $purposeText = $purpose ? "untuk {$purpose} " : '';

                throw new BusinessException(
                    "Dokumen ini tidak bisa disimpan karena akun COA {$purposeText}(kode {$line['account']}) tidak ditemukan atau nonaktif. ".
                    'Silakan tambahkan atau aktifkan akun tersebut di halaman Master Data > Chart of Accounts.'
                );
            }

            return [
                'chart_of_account_id' => $account->id,
                'debit' => $line['type'] === 'debit' ? $line['amount'] : 0,
                'credit' => $line['type'] === 'credit' ? $line['amount'] : 0,
                'description' => $line['description'] ?? $defaultDescription,
            ];
        }, $lines);
    }
}
