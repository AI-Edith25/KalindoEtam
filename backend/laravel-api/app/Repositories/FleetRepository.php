<?php

namespace App\Repositories;

use App\Models\Fleet;

class FleetRepository extends BaseRepository
{
    public function __construct(Fleet $model)
    {
        parent::__construct($model);
    }
}
