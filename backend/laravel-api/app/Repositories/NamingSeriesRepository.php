<?php

namespace App\Repositories;

use App\Models\NamingSeries;

class NamingSeriesRepository extends BaseRepository
{
    public function __construct(NamingSeries $model)
    {
        parent::__construct($model);
    }

    /** Null, not firstOrFail() — an unconfigured document type is a business condition the caller must report clearly, not a 404. */
    public function lockDefaultForType(string $documentType): ?NamingSeries
    {
        return $this->model->query()
            ->where('document_type', $documentType)
            ->where('is_default', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
    }
}
