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

    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->model->query()->with(['uom', 'salesAccount', 'purchaseAccount']);

        if ($search) {
            $query->where(fn ($q) => $q->where('misc_code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }

        return $query->paginate($perPage);
    }
}
