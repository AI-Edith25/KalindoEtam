<?php

namespace App\Repositories;

use App\Models\ImportBatch;

class ImportBatchRepository extends BaseRepository
{
    public function __construct(ImportBatch $model)
    {
        parent::__construct($model);
    }
}
