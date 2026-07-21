<?php

namespace App\Repositories;

use App\Models\JournalEntryLine;

class JournalEntryLineRepository extends BaseRepository
{
    public function __construct(JournalEntryLine $model)
    {
        parent::__construct($model);
    }
}
