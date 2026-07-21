<?php

namespace App\Repositories;

use App\Models\StockIn;

class StockInRepository extends BaseRepository
{
    public function __construct(StockIn $model)
    {
        parent::__construct($model);
    }
}
