<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CustomerOutstandingArchiveExport;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShowCustomerOutstandingArchiveRequest;
use App\Http\Requests\StoreCustomerOutstandingArchiveRequest;
use App\Models\CustomerOutstandingSnapshot;
use App\Services\CustomerOutstandingArchiveService;
use App\Services\Import\CustomerOutstandingArchiveImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Piutang Customer (Arsip Import)" -- a standalone reference notebook of imported legacy AR
 * export snapshots, not connected to the live Sales/Invoice/Customer/AR module in any way. See
 * CustomerOutstandingArchiveImportService (parsing/write) and CustomerOutstandingArchiveService
 * (query/read) for where the actual logic lives; this controller is orchestration only.
 */
class CustomerOutstandingArchiveController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CustomerOutstandingArchiveService $archiveService,
        protected CustomerOutstandingArchiveImportService $importService,
    ) {}

    public function snapshots(): JsonResponse
    {
        return $this->success($this->archiveService->snapshots());
    }

    public function store(StoreCustomerOutstandingArchiveRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $snapshot = $this->importService->import($absolutePath, $file->getClientOriginalExtension(), $file->getClientOriginalName(), Auth::id());
        } finally {
            Storage::disk('local')->delete($path);
        }

        return $this->success($snapshot, 'Snapshot berhasil diimpor.', 201);
    }

    public function show(ShowCustomerOutstandingArchiveRequest $request, CustomerOutstandingSnapshot $snapshot): JsonResponse
    {
        return $this->success([
            'snapshot' => $snapshot->load('importer:id,name'),
            ...$this->archiveService->groupedDetail($snapshot->id, $request->validated()),
        ]);
    }

    public function export(ShowCustomerOutstandingArchiveRequest $request, CustomerOutstandingSnapshot $snapshot): BinaryFileResponse
    {
        $format = $request->validate(['format' => ['sometimes', Rule::in(['xlsx', 'csv'])]])['format'] ?? 'xlsx';
        $detail = $this->archiveService->groupedDetail($snapshot->id, $request->validated());

        $export = new CustomerOutstandingArchiveExport($detail['customers'], $detail['grand_total_unpaid'], $detail['grand_total_overdue']);

        return Excel::download($export, "PiutangCustomerArsip.{$format}");
    }
}
