<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\ResourceCollection;

trait ApiResponse
{
    /** $extraMeta merges into the response's meta block (e.g. a filtered aggregate alongside a paginated list) — additive only, every existing 3-arg call site is unaffected. */
    protected function success(mixed $data = null, string $message = '', int $status = 200, array $extraMeta = []): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $paginator = $data->resource;

            $payload['meta'] = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        }

        if ($extraMeta !== []) {
            $payload['meta'] = array_merge($payload['meta'] ?? [], $extraMeta);
        }

        return response()->json($payload, $status);
    }
}
