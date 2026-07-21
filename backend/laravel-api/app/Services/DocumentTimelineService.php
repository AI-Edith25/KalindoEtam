<?php

namespace App\Services;

use App\Models\DocumentTimeline;
use App\Repositories\DocumentTimelineRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentTimelineService
{
    public function __construct(protected DocumentTimelineRepository $documentTimelineRepository) {}

    public function record(Model $subject, string $action, ?string $description = null, array $properties = []): DocumentTimeline
    {
        return DB::transaction(fn () => $this->documentTimelineRepository->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'description' => $description,
            'properties' => $properties,
        ]));
    }

    public function forSubject(string $subjectType, string $subjectId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->documentTimelineRepository->forSubject($subjectType, $subjectId, $perPage);
    }
}
