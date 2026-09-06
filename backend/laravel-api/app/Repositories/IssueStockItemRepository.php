<?php

namespace App\Repositories;

use App\Models\IssueStockItem;

class IssueStockItemRepository extends BaseRepository
{
    public function __construct(IssueStockItem $model)
    {
        parent::__construct($model);
    }
}
