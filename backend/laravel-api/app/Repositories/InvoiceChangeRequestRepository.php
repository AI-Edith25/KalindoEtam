<?php

namespace App\Repositories;

use App\Enums\ApprovalStatus;
use App\Models\InvoiceChangeRequest;

class InvoiceChangeRequestRepository extends BaseRepository
{
    public function __construct(InvoiceChangeRequest $model)
    {
        parent::__construct($model);
    }

    /** The one non-terminal request for an invoice, if any: pending, or approved-but-not-yet-consumed. */
    public function activeFor(string $invoiceId): ?InvoiceChangeRequest
    {
        return $this->model->query()
            ->where('invoice_id', $invoiceId)
            ->where(fn ($query) => $query
                ->where('status', ApprovalStatus::PENDING)
                ->orWhere(fn ($sq) => $sq->where('status', ApprovalStatus::APPROVED)->whereNull('consumed_at'))
            )
            ->latest('created_at')
            ->first();
    }

    public function historyFor(string $invoiceId)
    {
        return $this->model->query()
            ->with(['requestedBy', 'decidedBy'])
            ->where('invoice_id', $invoiceId)
            ->latest('created_at')
            ->get();
    }
}
