<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A Weight-category item's qty exceeds the configured over-receipt
 * tolerance — not a hard block (truck-scale weight legitimately varies
 * from the ordered qty), just a "are you sure" gate. Status 409, plus a
 * `requires_confirmation` flag in `data`, distinguishes this from a plain
 * BusinessException so the frontend shows a confirm dialog instead of a
 * toast. The caller resubmits with confirm_over_receipt=true to proceed.
 */
class OverReceiptConfirmationRequiredException extends BusinessException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => ['requires_confirmation' => true],
        ], 409);
    }
}
