<?php

namespace App\Repositories;

use App\Models\Fleet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FleetRepository extends BaseRepository
{
    public function __construct(Fleet $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with('location')->paginate($perPage);
    }
}
