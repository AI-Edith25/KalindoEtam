<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDocumentAttachmentRequest;
use App\Http\Requests\StoreDocumentAttachmentRequest;
use App\Http\Resources\DocumentAttachmentResource;
use App\Models\DocumentAttachment;
use App\Services\DocumentAttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentAttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(protected DocumentAttachmentService $documentAttachmentService) {}

    public function index(IndexDocumentAttachmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success(DocumentAttachmentResource::collection(
            $this->documentAttachmentService->listFor($data['attachable_type'], $data['attachable_id'])
        ));
    }

    /** Streams the stored file back — kept behind auth:sanctum like every other endpoint (no public file URLs), so consumers (e.g. a Logo <img>) fetch it via an authenticated request and render it as a blob. */
    public function download(DocumentAttachment $documentAttachment): StreamedResponse
    {
        return $this->documentAttachmentService->download($documentAttachment);
    }

    public function store(StoreDocumentAttachmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $attachment = $this->documentAttachmentService->upload(
            $data['attachable_type'],
            $data['attachable_id'],
            $data['file'],
        );

        return $this->success(new DocumentAttachmentResource($attachment), 'Attachment uploaded.', 201);
    }

    public function destroy(DocumentAttachment $documentAttachment): JsonResponse
    {
        $this->documentAttachmentService->delete($documentAttachment);

        return $this->success(null, 'Attachment deleted.');
    }
}
