<?php

namespace App\Services;

use App\Repositories\PurchaseJournalRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Resolves Purchase Journal's screen pagination + export collection + labels for both sub-tabs (Purchase Invoice, Purchase Return). */
class PurchaseJournalService
{
    protected const GROUP_LABELS = ['purchase_invoice' => 'Purchase Journal', 'purchase_return' => 'Purchase Return Journal'];

    protected const FILE_NAME_SEGMENTS = ['purchase_invoice' => 'PurchaseInvoice', 'purchase_return' => 'PurchaseReturn'];

    public function __construct(protected PurchaseJournalRepository $purchaseJournalRepository) {}

    public function list(array $filters, string $view, int $perPage = 25): LengthAwarePaginator
    {
        return $view === 'purchase_return'
            ? $this->purchaseJournalRepository->paginateReturns($filters, $perPage)
            : $this->purchaseJournalRepository->paginateInvoices($filters, $perPage);
    }

    public function exportQuery(array $filters, string $view): Builder
    {
        return $view === 'purchase_return'
            ? $this->purchaseJournalRepository->exportReturnQuery($filters)
            : $this->purchaseJournalRepository->exportInvoiceQuery($filters);
    }

    public function groupLabel(string $view): string
    {
        return self::GROUP_LABELS[$view] ?? self::GROUP_LABELS['purchase_invoice'];
    }

    public function fileNameSegment(string $view): string
    {
        return self::FILE_NAME_SEGMENTS[$view] ?? self::FILE_NAME_SEGMENTS['purchase_invoice'];
    }
}
