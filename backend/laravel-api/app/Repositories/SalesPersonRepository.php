<?php

namespace App\Repositories;

use App\Models\SalesPerson;

class SalesPersonRepository extends BaseRepository
{
    public function __construct(SalesPerson $model)
    {
        parent::__construct($model);
    }
}
