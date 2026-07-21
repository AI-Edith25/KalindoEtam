<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Thrown when a request is well-formed but violates a business rule
 * (e.g. cancelling a document that already has downstream effects).
 * Distinct from validation errors (FormRequest) and not-found errors.
 */
class BusinessException extends Exception
{
    public function __construct(string $message, protected int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
        ], $this->status);
    }
}
