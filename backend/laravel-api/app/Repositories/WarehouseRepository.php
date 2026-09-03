<?php

namespace App\Repositories;

use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class WarehouseRepository extends BaseRepository
{
    public function __construct(Warehouse $model)
    {
        parent::__construct($model);
    }

    /** Ordered by code — the Item Prices matrix renders one column per warehouse in this order. */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->orderBy('code')->paginate($perPage);
    }
}
