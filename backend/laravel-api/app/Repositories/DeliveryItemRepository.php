<?php

namespace App\Repositories;

use App\Models\DeliveryItem;

class DeliveryItemRepository extends BaseRepository
{
    public function __construct(DeliveryItem $model)
    {
        parent::__construct($model);
    }
}
