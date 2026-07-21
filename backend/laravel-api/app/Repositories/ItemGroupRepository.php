<?php

namespace App\Repositories;

use App\Models\ItemGroup;

class ItemGroupRepository extends BaseRepository
{
    public function __construct(ItemGroup $model)
    {
        parent::__construct($model);
    }
}
