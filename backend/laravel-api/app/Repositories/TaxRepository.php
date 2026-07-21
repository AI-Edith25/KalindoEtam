<?php

namespace App\Repositories;

use App\Models\Tax;

class TaxRepository extends BaseRepository
{
    public function __construct(Tax $model)
    {
        parent::__construct($model);
    }
}
