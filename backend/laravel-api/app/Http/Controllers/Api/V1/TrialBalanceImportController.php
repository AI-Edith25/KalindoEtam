<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exceptions\ImportConfirmationRequiredException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTrialBalanceImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessTrialBalanceImportJob;
use App\Models\ImportBatch;
use App\Models\JournalEntry;
use App\Services\Import\TrialBalanceImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One-click Trial Balance import — see TrialBalanceImportService for why this posts a raw Journal
 * Entry rather than writing to Trial Balance directly (it has no writable state of its own). The
 * file is small (one row per Chart of Account), so — unlike the Sales/Purchase Journal
 * importer — a full synchronous parse before queuing is cheap, which is what lets this controller
 * offer two real confirmation gates instead of asking upfront in the UI: a duplicate period
 * already imported, and the file's own declared "OUT OF BALANCE BY" figure. Both use the same
 * generic ImportConfirmationRequiredException (409, `reason` field distinguishes the two so the
 * frontend shows the right dialog).
 */
class TrialBalanceImportController extends Controller
{
    use ApiResponse;

    public function __construct(protected TrialBalanceImportService $importService) {}

    public function store(StoreTrialBalanceImportBatchRequest $request): JsonResponse
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

        if ($parsed['out_of_balance_amount'] !== null && ! $request->boolean('confirm_out_of_balance')) {
            Storage::disk('local')->delete($path);

            throw new ImportConfirmationRequiredException(
                sprintf('File asli tidak balance sebesar Rp %s. Lanjutkan import?', number_format(abs($parsed['out_of_balance_amount']), 0, ',', '.')),
                'out_of_balance',
                ['amount' => abs($parsed['out_of_balance_amount'])],
            );
        }

        $batch = ImportBatch::query()->create([
            'module' => 'trial-balance',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'write_mode' => $duplicatePolicy ?? 'skip',
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessTrialBalanceImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'trial-balance', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
