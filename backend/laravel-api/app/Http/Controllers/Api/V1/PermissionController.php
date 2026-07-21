<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(protected PermissionService $permissionService) {}

    public function index(): JsonResponse
    {
        return $this->success(PermissionResource::collection($this->permissionService->list()));
    }
}
