<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

abstract class BaseRepository
{
    public function __construct(protected Model $model) {}

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()->paginate($perPage);
    }

    public function all(): Collection
    {
        return $this->model->query()->get();
    }

    public function findOrFail(string $id): Model
    {
        return $this->model->query()->findOrFail($id);
    }

    public function create(array $data): Model
    {
        return $this->model->query()->create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->update($data);

        return $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }
}
