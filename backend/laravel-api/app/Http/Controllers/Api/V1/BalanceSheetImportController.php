<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportConfirmationRequiredException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBalanceSheetImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessBalanceSheetImportJob;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\BalanceSheetImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One-click Balance Sheet import — see BalanceSheetImportService for why this posts a raw Journal
 * Entry rather than writing to any report table directly (Balance Sheet has no writable state of
 * its own). Same posture as TrialBalanceImportController/IncomeStatementImportController: the file
 * is small, so a full synchronous parse before queuing is cheap, and there's just one confirmation
 * gate (duplicate reference) — this file format has no "OUT OF BALANCE BY" row either.
 */
class BalanceSheetImportController extends Controller
{
    use ApiResponse;

    public function __construct(protected BalanceSheetImportService $importService) {}

    public function store(StoreBalanceSheetImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);
        $extension = $file->getClientOriginalExtension();

        $parsed = $this->importService->parse($absolutePath, $extension);

        if (isset($parsed['error'])) {
            Storage::disk('local')->delete($path);
            abort(422, $parsed['error']);
        }

        if ($parsed['period_label'] === null) {
            Storage::disk('local')->delete($path);
            abort(422, 'Periode laporan (mis. "01/01/2022 - 31/12/2025") tidak ditemukan di baris judul file — tidak bisa menentukan tanggal posting.');
        }

        $reference = $this->importService->referenceFor($parsed['period_label']);
        $duplicatePolicy = $request->string('duplicate_policy')->value() ?: null;

        if ($duplicatePolicy === null && JournalEntry::query()->where('source_document_number', $reference)->exists()) {
            Storage::disk('local')->delete($path);

            throw new ImportConfirmationRequiredException(
                "Periode \"{$parsed['period_label']}\" sudah pernah diimpor sebelumnya. Lewati, atau tetap buat sebagai entry tambahan?",
                'duplicate',
            );
        }

        $batch = ImportBatch::query()->create([
            'module' => 'balance-sheet',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => $duplicatePolicy ?? 'skip',
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessBalanceSheetImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'balance-sheet', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
