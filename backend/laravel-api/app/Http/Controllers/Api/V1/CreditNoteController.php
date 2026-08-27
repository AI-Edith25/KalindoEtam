<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Concerns\ExportsSalesList;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexCreditNoteRequest;
use App\Http\Requests\StoreCreditNoteRequest;
use App\Http\Requests\UpdateCreditNoteRequest;
use App\Http\Resources\CreditNoteResource;
use App\Models\CreditNote;
use App\Services\CreditNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CreditNoteController extends Controller
{
    use ApiResponse, ExportsSalesList;

    protected const EAGER = ['invoice.delivery', 'customer', 'items.invoiceItem', 'items.item'];

    /** Ordered [columnKey => label] — mirrors CreditNoteListPage.tsx's own table columns. */
    protected const COLUMNS = [
        'credit_note_date' => 'Date',
        'document_number' => 'Credit Note No',
        'invoice' => 'Invoice',
        'customer_name' => 'Customer',
        'reason' => 'Reason',
        'total_amount' => 'Amount',
        'status' => 'Status',
    ];

    public function __construct(protected CreditNoteService $creditNoteService) {}

    public function index(IndexCreditNoteRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        return $this->success(CreditNoteResource::collection(
            $this->creditNoteService->list($filters, $perPage)
        ));
    }

    public function store(StoreCreditNoteRequest $request): JsonResponse
    {
        $creditNote = $this->creditNoteService->create($request->validated());

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note created.', 201);
    }

    public function show(CreditNote $creditNote): JsonResponse
    {
        return $this->success(new CreditNoteResource($creditNote->load(self::EAGER)));
    }

    public function update(UpdateCreditNoteRequest $request, CreditNote $creditNote): JsonResponse
    {
        $creditNote = $this->creditNoteService->update($creditNote, $request->validated());

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note updated.');
    }

    public function destroy(CreditNote $creditNote): JsonResponse
    {
        $this->creditNoteService->delete($creditNote);

        return $this->success(null, 'Credit Note deleted.');
    }

    public function submit(CreditNote $creditNote): JsonResponse
    {
        $creditNote = $this->creditNoteService->submit($creditNote);

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note submitted.');
    }

    public function reverse(CreditNote $creditNote): JsonResponse
    {
        $creditNote = $this->creditNoteService->reverse($creditNote);

        return $this->success(new CreditNoteResource($creditNote), 'Credit Note reversed.');
    }

    /** Bulk export — same contract as SalesOrderController::export(). */
    public function export(IndexCreditNoteRequest $request): BinaryFileResponse
    {
        $extra = $request->validate([
            'format' => ['sometimes', Rule::in(['xlsx', 'csv'])],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['uuid'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => [Rule::in(array_keys(self::COLUMNS))],
        ]);

        $filters = $request->validated();
        unset($filters['per_page']);

        $rows = $this->creditNoteService->listAll($filters, $extra['ids'] ?? null);

        return $this->exportSalesList(
            $rows,
            self::COLUMNS,
            $extra['columns'] ?? null,
            fn (CreditNote $row, string $key) => match ($key) {
                'credit_note_date' => $row->credit_note_date?->format('Y-m-d'),
                'document_number' => $row->document_number,
                'invoice' => $row->invoice?->document_number,
                'customer_name' => $row->customer?->customer_name,
                'reason' => ucfirst(str_replace('_', ' ', $row->reason?->value ?? '')),
                'total_amount' => (float) $row->total_amount,
                'status' => ucfirst($row->status?->value ?? ''),
                default => null,
            },
            'credit_notes',
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $extra['format'] ?? 'xlsx',
        );
    }
}
