<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Exceptions\JournalTypeMismatchException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCashBookImportBatchRequest;
use App\Http\Resources\ImportBatchResource;
use App\Jobs\ProcessCashBookImportJob;
use App\Models\ImportBatch;
use App\Services\Import\CashBookImportService;
use App\Services\JournalListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One-click Cash Book import — re-imports this system's own Journal List >
 * Cash Book export (see CashBookImportService). Upload synchronously checks
 * the file's own section-label row against the selected Journal Type before
 * anything is queued (JournalTypeMismatchException, a 409 "are you sure"
 * gate the frontend resubmits past with confirm_journal_type=true) — cheap,
 * since it only reads the first few rows, and gives the user an answer
 * before committing to a queued job for a file that may be the wrong one.
 */
class CashBookImportController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CashBookImportService $cashBookImportService,
        protected JournalListService $journalListService,
    ) {}

    public function store(StoreCashBookImportBatchRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $view = $request->string('view')->value();
        $path = $file->store('imports', 'local');

        $foundLabel = $this->cashBookImportService->detectGroupLabel(Storage::disk('local')->path($path), $file->getClientOriginalExtension());
        $expectedLabel = $this->journalListService->groupLabel($view);

        if ($foundLabel !== null && $foundLabel !== $expectedLabel && ! $request->boolean('confirm_journal_type')) {
            Storage::disk('local')->delete($path);

            throw new JournalTypeMismatchException(
                "File ini sepertinya untuk \"{$foundLabel}\", tapi Journal Type yang sedang dipilih adalah \"{$expectedLabel}\". Lanjutkan impor sebagai \"{$expectedLabel}\"?"
            );
        }

        $batch = ImportBatch::query()->create([
            'module' => 'cash-book',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'queued_at' => now(),
            'created_by' => Auth::id(),
        ]);

        ProcessCashBookImportJob::dispatch($batch->id);

        return $this->success(new ImportBatchResource($batch), 'Import queued.', 201);
    }

    public function show(ImportBatch $batch): JsonResponse
    {
        abort_unless($batch->module === 'cash-book', 404);

        return $this->success(new ImportBatchResource($batch));
    }
}
