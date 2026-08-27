<?php

namespace App\Repositories;

use App\Models\PurchaseSetting;

class PurchaseSettingRepository extends BaseRepository
{
    public function __construct(PurchaseSetting $model)
    {
        parent::__construct($model);
    }

    /** The one row — seeded by its own migration, so this never returns null in practice. firstOrCreate is a safety net only. */
    public function current(): PurchaseSetting
    {
        /** @var PurchaseSetting */
        return $this->model->query()->firstOrCreate([], ['weight_over_receipt_tolerance_percent' => 10]);
    }
}
