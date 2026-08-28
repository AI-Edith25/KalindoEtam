<?php

namespace App\Repositories;

use App\Models\SalesTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class SalesTargetRepository extends BaseRepository
{
    protected const EAGER = ['salesPerson', 'branch'];

    public function __construct(SalesTarget $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->with(self::EAGER)->latest('period_year')->latest('period_month')->paginate($perPage);
    }

    public function findOrFail(string $id): SalesTarget
    {
        return $this->model->query()->with(self::EAGER)->findOrFail($id);
    }

    public function create(array $data): Model
    {
        $salesTarget = parent::create($data);

        return $salesTarget->fresh(self::EAGER);
    }

    public function update(Model $model, array $data): Model
    {
        parent::update($model, $data);

        return $model->fresh(self::EAGER);
    }

    /**
     * One row per Sales Person with a target this period, summed across
     * Branch (the achievement panel has no branch column) — a single
     * grouped aggregate, not a per-branch row pulled into PHP and summed
     * there. Used by DashboardService::salesAchievement().
     */
    public function totalsBySalesPersonForPeriod(int $month, int $year): Collection
    {
        return $this->model->query()
            ->selectRaw('sales_person_id, SUM(target_amount) as amount')
            ->where('period_month', $month)
            ->where('period_year', $year)
            ->groupBy('sales_person_id')
            ->get();
    }
}
