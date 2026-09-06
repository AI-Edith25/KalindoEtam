<?php

namespace App\Repositories;

use App\Models\OpeningStockItem;

class OpeningStockItemRepository extends BaseRepository
{
    public function __construct(OpeningStockItem $model)
    {
        parent::__construct($model);
    }
}
