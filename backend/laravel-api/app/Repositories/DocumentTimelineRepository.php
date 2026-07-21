<?php

namespace App\Repositories;

use App\Models\DocumentTimeline;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DocumentTimelineRepository extends BaseRepository
{
    public function __construct(DocumentTimeline $model)
    {
        parent::__construct($model);
    }

    public function forSubject(string $subjectType, string $subjectId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->latest()
            ->paginate($perPage);
    }
}
