<?php

namespace App\Repositories;

use App\Models\DebitNoteItem;

/**
 * No "remaining totals" lookup here, unlike CreditNoteItemRepository —
 * a Debit Note line has no ceiling to check against (see
 * docs/DEBIT_NOTE_DESIGN.md §0/§4). Plain CRUD via BaseRepository.
 */
class DebitNoteItemRepository extends BaseRepository
{
    public function __construct(DebitNoteItem $model)
    {
        parent::__construct($model);
    }
}
