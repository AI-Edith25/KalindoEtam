<?php

namespace App\Repositories;

use App\Models\ReceiptStockItem;

class ReceiptStockItemRepository extends BaseRepository
{
    public function __construct(ReceiptStockItem $model)
    {
        parent::__construct($model);
    }
}
