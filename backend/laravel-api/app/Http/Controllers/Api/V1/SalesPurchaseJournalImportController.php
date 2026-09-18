<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exceptions\JournalTypeMismatchException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesPurchaseJournalImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessSalesPurchaseJournalImportJob;
use App\Models\ImportBatch;
use App\Services\Import\SalesPurchaseJournalImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One-click Sales/Purchase Journal import — see SalesPurchaseJournalImportService for why this
 * posts raw Journal Entries rather than fabricating Invoice/CreditNote/PurchaseInvoice/
 * PurchaseReturn documents. One synchronous gate before anything is queued, mirroring
 * CashBookImportController::store()'s "are you sure" posture rather than a hard failure: a
 * section-label mismatch (JournalTypeMismatchException, same as Cash Book) — cheap, it only reads
 * a small peek regardless of file size.
 *
 * The duplicate-document policy (skip vs. create anyway — see the service's own docblock) is a
 * plain upfront request field, not a reactive "N duplicates found, choose one" gate: an early
 * design scanned the whole file for duplicates before queuing, but that full pass measured ~50s on
 * the real ~25k-row Purchase sample alone — far too slow for a synchronous upload request on the
 * ~170k-row Sales one. Asking the policy upfront (defaulting to "skip", same safe default either
 * design would use) gets the same one-decision-for-the-whole-batch outcome the ticket asks for
 * without that cost; the actual duplicate check still happens per-group during real processing
 * (SalesPurchaseJournalImportService::processGroup()), it just isn't previewed before queuing.
 */
class SalesPurchaseJournalImportController extends Controller
{
    use ApiResponse;

    public function __construct(protected SalesPurchaseJournalImportService $importService) {}

    public function store(StoreSalesPurchaseJournalImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $view = $request->string('view')->value();
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        $peeked = $this->importService->peek($absolutePath);
        $expectedLabel = $this->importService->groupLabel($view);

        if ($peeked['label'] !== null && $peeked['label'] !== $expectedLabel && ! $request->boolean('confirm_journal_type')) {
            Storage::disk('local')->delete($path);

            throw new JournalTypeMismatchException(
                "File ini sepertinya untuk \"{$peeked['label']}\", tapi Journal Type yang sedang dipilih adalah \"{$expectedLabel}\". Lanjutkan impor sebagai \"{$expectedLabel}\"?"
            );
        }

        $batch = ImportBatch::query()->create([
            'module' => 'sales-purchase-journal',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['view' => $view],
            'write_mode' => $request->string('duplicate_policy')->value() ?: 'skip',
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessSalesPurchaseJournalImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'sales-purchase-journal', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
