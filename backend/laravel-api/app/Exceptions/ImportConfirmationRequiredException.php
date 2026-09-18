<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A generic 409 "are you sure" gate for an import controller that needs more than one distinct
 * confirmation in the same upload flow (e.g. TrialBalanceImportController: "this period was
 * already imported" and "the file itself doesn't balance" are two different questions, each with
 * its own dialog and its own resubmit field) — JournalTypeMismatchException/
 * DuplicateImportDocumentsException-style single-purpose exceptions don't scale to that without
 * duplicating this exact shape per reason, so this one takes the reason as data instead.
 */
class ImportConfirmationRequiredException extends BusinessException
{
    /** @param array<string, mixed> $data extra fields merged alongside reason/requires_confirmation, e.g. ['amount' => 17456724.50] */
    public function __construct(string $message, protected string $reason, protected array $data = [])
    {
        parent::__construct($message, 409);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => ['requires_confirmation' => true, 'reason' => $this->reason, ...$this->data],
        ], 409);
    }
}
