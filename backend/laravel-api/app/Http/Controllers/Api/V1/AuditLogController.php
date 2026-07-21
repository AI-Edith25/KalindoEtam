<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;

/** Read-only — no store/update/destroy. An audit entry is never written by a user directly, only recorded by AuditLogService::record() from elsewhere in the app. See docs/ADMINISTRATION_DESIGN.md §6. */
class AuditLogController extends Controller
{
    use ApiResponse;

    public function __construct(protected AuditLogService $auditLogService) {}

    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->success(AuditLogResource::collection(
            $this->auditLogService->search($data, $data['per_page'] ?? 15)
        ));
    }
}
