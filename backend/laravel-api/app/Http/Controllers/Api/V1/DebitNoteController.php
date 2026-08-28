<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\ExportsSalesList;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexDebitNoteRequest;
use App\Http\Requests\StoreDebitNoteRequest;
use App\Http\Requests\UpdateDebitNoteRequest;
use App\Http\Resources\DebitNoteResource;
use App\Models\DebitNote;
use App\Services\DebitNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DebitNoteController extends Controller
{
    use ApiResponse, ExportsSalesList;

    protected const EAGER = ['invoice', 'customer', 'items.invoiceItem', 'items.item'];

    /** Ordered [columnKey => label] — mirrors DebitNoteListPage.tsx's own table columns. */
    protected const COLUMNS = [
        'debit_note_date' => 'Date',
        'document_number' => 'Debit Note No',
        'invoice' => 'Invoice',
        'customer_name' => 'Customer',
        'reason' => 'Reason',
        'total_amount' => 'Amount',
        'status' => 'Status',
    ];

    public function __construct(protected DebitNoteService $debitNoteService) {}

    public function index(IndexDebitNoteRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(DebitNoteResource::collection(
            $this->debitNoteService->list($filters, $perPage)
        ));
    }

    public function store(StoreDebitNoteRequest $request): JsonResponse
    {
        $debitNote = $this->debitNoteService->create($request->validated());

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note created.', 201);
    }

    public function show(DebitNote $debitNote): JsonResponse
    {
        return $this->success(new DebitNoteResource($debitNote->load(self::EAGER)));
    }

    public function update(UpdateDebitNoteRequest $request, DebitNote $debitNote): JsonResponse
    {
        $debitNote = $this->debitNoteService->update($debitNote, $request->validated());

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note updated.');
    }

    public function destroy(DebitNote $debitNote): JsonResponse
    {
        $this->debitNoteService->delete($debitNote);

        return $this->success(null, 'Debit Note deleted.');
    }

    public function submit(DebitNote $debitNote): JsonResponse
    {
        $debitNote = $this->debitNoteService->submit($debitNote);

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note submitted.');
    }

    public function reverse(DebitNote $debitNote): JsonResponse
    {
        $debitNote = $this->debitNoteService->reverse($debitNote);

        return $this->success(new DebitNoteResource($debitNote), 'Debit Note reversed.');
    }

    /** Bulk export — same contract as SalesOrderController::export(). */
    public function export(IndexDebitNoteRequest $request): BinaryFileResponse
    {
        $extra = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'mode' => ['sometimes', Rule::in(['detail', 'summary'])],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys(self::COLUMNS))],
        ]);

        $filters = $request->validated();
        unset($filters['per_page']);

        if (($extra['mode'] ?? 'detail') === 'summary') {
            return $this->exportSalesSummary(
                $this->debitNoteService->summaryExportRows($filters, $extra['ids'] ?? null),
                'DebitNoteToCustomer',
                $filters['date_from'] ?? null,
                $filters['date_to'] ?? null,
                $extra['format'] ?? 'xlsx',
            );
        }

        $rows = $this->debitNoteService->listAll($filters, $extra['ids'] ?? null);

        return $this->exportSalesList(
            $rows,
            self::COLUMNS,
            $extra['columns'] ?? null,
            fn (DebitNote $row, string $key) => match ($key) {
                'debit_note_date' => $row->debit_note_date?->format('Y-m-d'),
                'document_number' => $row->document_number,
                'invoice' => $row->invoice?->document_number,
                'customer_name' => $row->customer?->customer_name,
                'reason' => ucfirst(str_replace('_', ' ', $row->reason?->value ?? '')),
                'total_amount' => (float) $row->total_amount,
                'status' => ucfirst($row->status?->value ?? ''),
                default => null,
            },
            'debit_notes',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $extra['format'] ?? 'xlsx',
        );
    }
}
