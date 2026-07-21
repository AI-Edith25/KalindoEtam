<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDocumentTimelineRequest;
use App\Http\Resources\DocumentTimelineResource;
use App\Services\DocumentTimelineService;
use Illuminate\Http\JsonResponse;

class DocumentTimelineController extends Controller
{
    use ApiResponse;

    public function __construct(protected DocumentTimelineService $documentTimelineService) {}

    public function index(IndexDocumentTimelineRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success(DocumentTimelineResource::collection(
            $this->documentTimelineService->forSubject($data['subject_type'], $data['subject_id'])
        ));
    }
}
