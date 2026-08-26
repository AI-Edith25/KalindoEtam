<?php

namespace App\Repositories;

use App\Models\PurchaseInvoiceItem;

class PurchaseInvoiceItemRepository extends BaseRepository
{
    public function __construct(PurchaseInvoiceItem $model)
    {
        parent::__construct($model);
    }
}
