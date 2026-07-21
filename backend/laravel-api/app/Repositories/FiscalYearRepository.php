<?php

namespace App\Repositories;

use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Collection;

class FiscalYearRepository extends BaseRepository
{
    public function __construct(FiscalYear $model)
    {
        parent::__construct($model);
    }

    /** @return Collection<int, FiscalYear> */
    public function allOrderedByStartDate(): Collection
    {
        return $this->model->query()->orderByDesc('start_date')->get();
    }
}
