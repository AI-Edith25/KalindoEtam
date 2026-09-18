<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportConfirmationRequiredException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIncomeStatementImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessIncomeStatementImportJob;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\IncomeStatementImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One-click Income Statement import — see IncomeStatementImportService for why this posts a raw
 * Journal Entry rather than writing to any report table directly (Income Statement has no
 * writable state of its own). The file is small, so a full synchronous parse before queuing is
 * cheap — same posture as TrialBalanceImportController, minus the out-of-balance gate (this file
 * format has no "OUT OF BALANCE BY" row).
 */
class IncomeStatementImportController extends Controller
{
    use ApiResponse;

    public function __construct(protected IncomeStatementImportService $importService) {}

    public function store(StoreIncomeStatementImportBatchRequest $request): JsonResponse
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
            'module' => 'profit-loss',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => $duplicatePolicy ?? 'skip',
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessIncomeStatementImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'profit-loss', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
