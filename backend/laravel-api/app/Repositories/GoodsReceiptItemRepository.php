<?php

namespace App\Repositories;

use App\Models\GoodsReceiptItem;

class GoodsReceiptItemRepository extends BaseRepository
{
    public function __construct(GoodsReceiptItem $model)
    {
        parent::__construct($model);
    }
}
