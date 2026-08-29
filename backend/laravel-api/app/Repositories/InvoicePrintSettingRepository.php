<?php

namespace App\Repositories;

use App\Models\InvoicePrintSetting;

class InvoicePrintSettingRepository extends BaseRepository
{
    public function __construct(InvoicePrintSetting $model)
    {
        parent::__construct($model);
    }

    /** The one row — seeded by its own migration, so this never returns null in practice. firstOrCreate is a safety net only. */
    public function current(): InvoicePrintSetting
    {
        /** @var InvoicePrintSetting */
        return $this->model->query()->firstOrCreate([], [
            'visible_columns' => ['itemCode', 'description', 'sales', 'qty', 'uom', 'unitCost', 'lineAmt'],
            'margins' => [
                'a4' => ['top' => 12, 'bottom' => 12, 'left' => 12, 'right' => 12],
                'continuous' => ['top' => 6, 'bottom' => 6, 'left' => 6, 'right' => 6],
                'half' => ['top' => 6, 'bottom' => 6, 'left' => 6, 'right' => 6],
            ],
        ]);
    }
}
