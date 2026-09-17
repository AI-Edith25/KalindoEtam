<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * The uploaded Cash Book file's own section-label row (row 6 — "Cash Book
 * Transaction"/"Cash Book-Receipt"/"Cash Book-Payment", see
 * JournalListExport::registerEvents()) doesn't match the Journal Type
 * selected in the UI — not a hard block (the user may genuinely want to
 * import it as that type anyway), just an "are you sure" gate. Same 409 +
 * `requires_confirmation` shape as OverReceiptConfirmationRequiredException,
 * so the frontend shows one confirm dialog instead of a toast. The caller
 * resubmits with confirm_journal_type=true to proceed.
 */
class JournalTypeMismatchException extends BusinessException
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
