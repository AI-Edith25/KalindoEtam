<?php

namespace App\Repositories;

use App\Models\ImportMappingPreset;

class ImportMappingPresetRepository extends BaseRepository
{
    public function __construct(ImportMappingPreset $model)
    {
        parent::__construct($model);
    }

    public function forModule(string $module): \Illuminate\Database\Eloquent\Collection
    {
        return $this->model->query()->where('module', $module)->latest()->get();
    }
}
