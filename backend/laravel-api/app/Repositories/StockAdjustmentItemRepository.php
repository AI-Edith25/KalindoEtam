<?php

namespace App\Repositories;

use App\Models\StockAdjustmentItem;

class StockAdjustmentItemRepository extends BaseRepository
{
    public function __construct(StockAdjustmentItem $model)
    {
        parent::__construct($model);
    }
}
