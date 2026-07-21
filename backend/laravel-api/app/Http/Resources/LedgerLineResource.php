<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One Ledger Detail row — a journal_entry_line joined back to its parent Journal Entry, plus the running_balance GeneralLedgerService attaches. */
class LedgerLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $journalEntry = $this->journalEntry;

        return [
            'id' => $this->id,
            'posting_date' => $journalEntry->posting_date?->format('Y-m-d'),
            'journal_entry_id' => $journalEntry->id,
            'journal_number' => $journalEntry->document_number,
            // Same short stable key + derived label convention as JournalEntryResource — never a raw class name.
            'reference_type' => $journalEntry->reference_type,
            'reference_label' => $journalEntry->reference_type ? ucwords(str_replace('_', ' ', $journalEntry->reference_type)) : null,
            'reference_id' => $journalEntry->reference_id,
            'reference_document_number' => $journalEntry->referenceDocument?->document_number,
            'description' => $this->description,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'running_balance' => $this->running_balance,
        ];
    }
}
