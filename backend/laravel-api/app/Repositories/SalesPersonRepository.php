<?php

namespace App\Repositories;

use App\Models\SalesPerson;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class SalesPersonRepository extends BaseRepository
{
    public function __construct(SalesPerson $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with('warehouse')->paginate($perPage);
    }

    /** Targeted whereIn, not the full table — used by DashboardService::salesAchievement() to name exactly the sales persons that have a target or achievement this period. */
    public function findMany(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection();
        }

        return $this->model->query()->whereIn('id', $ids)->get();
    }
}
