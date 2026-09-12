<?php

namespace App\Repositories;

use App\Models\PaymentEntryExpenseLine;

class PaymentEntryExpenseLineRepository extends BaseRepository
{
    public function __construct(PaymentEntryExpenseLine $model)
    {
        parent::__construct($model);
    }
}
