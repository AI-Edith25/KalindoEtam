<?php

namespace App\Repositories;

use App\Models\TermsOfPayment;

class TermsOfPaymentRepository extends BaseRepository
{
    public function __construct(TermsOfPayment $model)
    {
        parent::__construct($model);
    }
}
