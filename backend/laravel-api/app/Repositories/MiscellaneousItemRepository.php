<?php

namespace App\Repositories;

use App\Models\MiscellaneousItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MiscellaneousItemRepository extends BaseRepository
{
    public function __construct(MiscellaneousItem $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(['uom', 'salesAccount', 'purchaseAccount'])->paginate($perPage);
    }
}
