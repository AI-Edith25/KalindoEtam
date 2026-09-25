<?php

namespace App\Repositories;

use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplierRepository extends BaseRepository
{
    public function __construct(Supplier $model)
    {
        parent::__construct($model);
    }

    public function paginate(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->model->query();

        if ($search) {
            $query->where(fn ($q) => $q->where('supplier_code', 'like', "%{$search}%")->orWhere('supplier_name', 'like', "%{$search}%"));
        }

        return $query->paginate($perPage);
    }
}
