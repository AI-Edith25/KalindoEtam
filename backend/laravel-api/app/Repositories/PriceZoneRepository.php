<?php

namespace App\Repositories;

use App\Models\PriceZone;

class PriceZoneRepository extends BaseRepository
{
    public function __construct(PriceZone $model)
    {
        parent::__construct($model);
    }
}
