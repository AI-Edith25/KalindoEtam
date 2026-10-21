<?php

namespace App\Repositories;

use App\Models\DriverCommissionRate;

class DriverCommissionRateRepository extends BaseRepository
{
    public function __construct(DriverCommissionRate $model)
    {
        parent::__construct($model);
    }
}
