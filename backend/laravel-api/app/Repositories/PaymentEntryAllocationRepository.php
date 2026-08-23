<?php

namespace App\Repositories;

use App\Models\PaymentEntryAllocation;

class PaymentEntryAllocationRepository extends BaseRepository
{
    public function __construct(PaymentEntryAllocation $model)
    {
        parent::__construct($model);
    }
}
