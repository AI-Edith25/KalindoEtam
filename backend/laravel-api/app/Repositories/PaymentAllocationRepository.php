<?php

namespace App\Repositories;

use App\Models\PaymentAllocation;

class PaymentAllocationRepository extends BaseRepository
{
    public function __construct(PaymentAllocation $model)
    {
        parent::__construct($model);
    }
}
