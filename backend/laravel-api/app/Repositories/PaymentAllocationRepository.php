<?php

namespace App\Repositories;

use App\Models\PaymentAllocation;
use Carbon\Carbon;

class PaymentAllocationRepository extends BaseRepository
{
    public function __construct(PaymentAllocation $model)
    {
        parent::__construct($model);
    }

    /** AR Aging report's Summary footer "MTD/YTD COLLECTION" figures — company-wide, ignores every report filter/selection. */
    public function collectionTotal(Carbon $from, Carbon $to): float
    {
        return (float) $this->model->query()
            ->where('is_reversed', false)
            ->whereDate('allocation_date', '>=', $from)
            ->whereDate('allocation_date', '<=', $to)
            ->sum('allocated_amount');
    }
}
