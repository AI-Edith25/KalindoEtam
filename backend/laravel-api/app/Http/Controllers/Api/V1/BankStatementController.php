<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBankStatementRequest;
use App\Http\Resources\BankStatementResource;
use App\Models\BankStatement;
use App\Services\BankStatement\BankStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BankStatementController extends Controller
{
    use ApiResponse;

    public function __construct(private BankStatementService $bankStatementService) {}

    /** Uploads + parses synchronously, returns an unsaved preview for the user to confirm. */
    public function store(StoreBankStatementRequest $request): JsonResponse
    {
        $result = $this->bankStatementService->upload(
            $request->validated('bank_account_id'),
            $request->validated('format_template'),
            $request->file('file'),
            Auth::id(),
        );

        if (! $result['ok']) {
            return $this->success(null, $result['message'], 422);
        }

        return $this->success([
            'batch' => new BankStatementResource($result['batch']),
            'preview_rows' => $result['preview_rows'],
        ], 'File uploaded.', 201);
    }

    /** Persists the previewed rows and runs matching/summary for the affected period. */
    public function confirm(BankStatement $bankStatement): JsonResponse
    {
        $bankStatement = $this->bankStatementService->confirm($bankStatement);

        return $this->success(new BankStatementResource($bankStatement), 'Bank statement saved.');
    }

    public function show(BankStatement $bankStatement): JsonResponse
    {
        return $this->success(new BankStatementResource($bankStatement->load('bankAccount')));
    }

    /** Point 2a's "View" -- the originally uploaded file, same StreamedResponse pattern as ImportController::failedRows(). */
    public function download(BankStatement $bankStatement): StreamedResponse
    {
        return Storage::disk($bankStatement->disk)->download($bankStatement->file_path, $bankStatement->original_filename);
    }
}
