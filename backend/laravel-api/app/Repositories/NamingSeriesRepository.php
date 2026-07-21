<?php

namespace App\Repositories;

use App\Models\NamingSeries;

class NamingSeriesRepository extends BaseRepository
{
    public function __construct(NamingSeries $model)
    {
        parent::__construct($model);
    }

    public function lockDefaultForType(string $documentType): NamingSeries
    {
        return $this->model->query()
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
