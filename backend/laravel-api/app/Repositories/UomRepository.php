<?php

namespace App\Repositories;

use App\Models\UnitOfMeasurement;

class UomRepository extends BaseRepository
{
    public function __construct(UnitOfMeasurement $model)
    {
        parent::__construct($model);
    }
}
