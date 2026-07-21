<?php

namespace App\Repositories;

use App\Models\PaymentEntryItem;

class PaymentEntryItemRepository extends BaseRepository
{
    public function __construct(PaymentEntryItem $model)
    {
        parent::__construct($model);
    }
}
