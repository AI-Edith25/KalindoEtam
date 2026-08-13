<?php

namespace App\Repositories;

use App\Models\StockTransferItem;

class StockTransferItemRepository extends BaseRepository
{
    public function __construct(StockTransferItem $model)
    {
        parent::__construct($model);
    }
}
