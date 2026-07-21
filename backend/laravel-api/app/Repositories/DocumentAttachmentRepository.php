<?php

namespace App\Repositories;

use App\Models\DocumentAttachment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DocumentAttachmentRepository extends BaseRepository
{
    public function __construct(DocumentAttachment $model)
    {
        parent::__construct($model);
    }

    public function forAttachable(string $attachableType, string $attachableId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->where('attachable_type', $attachableType)
            ->where('attachable_id', $attachableId)
            ->latest()
            ->paginate($perPage);
    }
}
