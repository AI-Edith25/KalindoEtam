<?php

namespace App\Services\Import;

use App\Enums\DiscountType;
use App\Enums\ImportBatchStatus;
use App\Enums\InvoiceType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Item;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orchestrates the Sales Invoice historical import — see SalesInvoiceHistoryParser for the file
 * shape. Creates real, submitted Invoice/InvoiceItem rows (Goods or Transportation, per document
 * number prefix) so historical data shows up in item/customer-level reports (Sales Listing,
 * Product Sales Report, AR Detail, Sales Journal). Deliberately builds the Invoice directly via
 * InvoiceRepository/InvoiceItemRepository rather than InvoiceService::create() — that method
 * always recomputes each line's tax from the Item's *current* default tax rate
 * (TaxService::resolveLineTax()), which would silently discard this file's own historical TAX
 * column; here the file's numbers are authoritative and frozen, not recomputed.
 *
 * import_source_type = 'historical_invoice' is set on every row created here — InvoiceService::
 * submit()/cancel() key off it to skip stock/AR/GL entirely (confirmed with the user: AR/GL
 * balances are already backfilled by a separate Customer Outstanding import; this import is for
 * populating Invoice/InvoiceItem rows for reporting only). Invoice::submit() still flips status
 * to Submitted, so an imported row looks and behaves like a real one everywhere else.
 *
 * Customer/Item codes that don't resolve are map-to-existing-or-skip only — no auto-create of
 * master data (confirmed with the user), unlike Supplier in PurchaseHistoryImportService.
 */
class SalesInvoiceImportService
{
    public function __construct(
        protected SalesInvoiceHistoryParser $parser,
        protected FkResolver $fkResolver,
        protected InvoiceRepository $invoiceRepository,
        protected InvoiceItemRepository $invoiceItemRepository,
        protected InvoiceService $invoiceService,
    ) {}

    /**
     * @return array{error: string}|array{total_rows: int, valid_count: int, skipped_count: int, warnings: array<int,string>, needs_resolution: array<int, array{category: string, value: string, status: string, suggestions: array}>}
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $title = strtoupper(trim((string) ($rawRows[0][0] ?? '')));

        if (! str_contains($title, SalesInvoiceHistoryParser::TITLE)) {
            return ['error' => 'File ini tidak dikenali sebagai Sales Invoice Listing - Detail — periksa kembali formatnya.'];
        }

        $parsed = $this->parser->parse($rawRows);

        [$customerClassification, $itemClassification, $duplicates] = $this->classify($parsed['invoices']);

        return [
            'total_rows' => count($parsed['invoices']),
            'valid_count' => count($parsed['invoices']),
            'skipped_count' => count($parsed['warnings']),
            'warnings' => [
                ...$parsed['warnings'],
                'Import ini tidak membuat entri Accounts Receivable maupun jurnal GL — AR/GL historis sudah diisi lewat import Customer Outstanding terpisah. Stock juga tidak berubah.',
            ],
            'needs_resolution' => $this->buildResolutionList($customerClassification, $itemClassification, $duplicates),
        ];
    }

    /** @return array{0: array, 1: array, 2: array<int,string>} [customerClassification, itemClassification, duplicateDocumentNumbers] */
    private function classify(array $invoices): array
    {
        $customerCodes = collect($invoices)->pluck('customer_code')->all();
        $itemCodes = collect($invoices)
            ->filter(fn ($invoice) => $invoice['type'] === 'goods')
            ->flatMap(fn ($invoice) => collect($invoice['items'])->pluck('item_code'))
            ->all();
        $documentNumbers = collect($invoices)->pluck('document_number')->all();

        $customerClassification = $this->fkResolver->classify(Customer::class, 'customer_code', $customerCodes);
        $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', $itemCodes);
        $duplicates = Invoice::query()->whereIn('source_document_number', $documentNumbers)->pluck('source_document_number')->unique()->all();

        return [$customerClassification, $itemClassification, $duplicates];
    }

    /** @return array<int, array{category: string, value: string, status: string, suggestions: array}> */
    private function buildResolutionList(array $customerClassification, array $itemClassification, array $duplicates): array
    {
        $entries = [];

        foreach ($customerClassification as $value => $candidate) {
            if ($candidate['status'] !== 'match') {
                $entries[] = ['category' => 'customer', 'value' => $value, 'status' => $candidate['status'], 'suggestions' => $candidate['suggestions']];
            }
        }

        foreach ($itemClassification as $value => $candidate) {
            if ($candidate['status'] !== 'match') {
                $entries[] = ['category' => 'item', 'value' => $value, 'status' => $candidate['status'], 'suggestions' => $candidate['suggestions']];
            }
        }

        foreach ($duplicates as $value) {
            $entries[] = ['category' => 'duplicate', 'value' => $value, 'status' => 'duplicate', 'suggestions' => []];
        }

        return $entries;
    }

    public function import(ImportBatch $batch): void
    {
        $mapping = $batch->mapping ?? [];
        $warehouseId = $mapping['warehouse_id'] ?? null;
        $resolutions = $batch->fk_resolutions ?? ['customer' => [], 'item' => [], 'duplicate' => []];

        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $parsed = $this->parser->parse($rawRows);

        [$customerClassification, $itemClassification] = $this->classify($parsed['invoices']);

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['invoices'])]);

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $report = array_map(fn ($w) => ['document_number' => 'WARNING', 'status' => 'needs_review', 'reason' => $w], $parsed['warnings']);
        $customerCache = [];
        $itemCache = [];

        foreach ($parsed['invoices'] as $group) {
            $batch->increment('processed_rows');

            $outcome = $this->importOne($group, $warehouseId, $customerClassification, $itemClassification, $resolutions, $customerCache, $itemCache);
            $report[] = $outcome;

            match ($outcome['status']) {
                'success' => $success++,
                'failed' => $failed++,
                default => $skipped++,
            };
        }

        $batch->update([
            'success_rows' => $success,
            'failed_rows' => $failed,
            'preview_summary' => ['needs_review_rows' => $skipped, 'vouchers' => $report],
            'status' => ImportBatchStatus::COMPLETED,
        ]);
    }

    private function importOne(array $group, ?string $warehouseId, array $customerClassification, array $itemClassification, array $resolutions, array &$customerCache, array &$itemCache): array
    {
        $base = ['document_number' => $group['document_number']];

        if (Invoice::query()->where('source_document_number', $group['document_number'])->exists()) {
            $decision = $resolutions['duplicate'][$group['document_number']]['action'] ?? 'skip';

            if ($decision !== 'proceed') {
                return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
            }
        }

        $customerId = $this->resolveId($group['customer_code'], $customerClassification, $resolutions['customer'] ?? [], $customerCache);

        if ($customerId === null) {
            return [...$base, 'status' => 'needs_review', 'reason' => "Customer \"{$group['customer_code']}\" tidak di-resolve — dokumen dilewati."];
        }

        try {
            if ($group['type'] === 'transportation') {
                $invoice = $this->createTransportationInvoice($group, $customerId);
            } else {
                if ($warehouseId === null) {
                    return [...$base, 'status' => 'failed', 'reason' => 'Warehouse belum dipilih untuk import ini.'];
                }

                $resolvedItemIds = [];
                foreach ($group['items'] as $itemRow) {
                    $itemId = $this->resolveId($itemRow['item_code'], $itemClassification, $resolutions['item'] ?? [], $itemCache);

                    if ($itemId === null) {
                        return [...$base, 'status' => 'needs_review', 'reason' => "Item \"{$itemRow['item_code']}\" tidak di-resolve — dokumen dilewati."];
                    }

                    $resolvedItemIds[] = $itemId;
                }

                $invoice = $this->createGoodsInvoice($group, $customerId, $warehouseId, $resolvedItemIds);
            }

            $mismatchWarning = $this->crossCheckAmount($group, $invoice);
            $this->invoiceService->submit($invoice);
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        return [...$base, 'status' => 'success', 'reason' => $mismatchWarning];
    }

    private function crossCheckAmount(array $group, Invoice $invoice): ?string
    {
        $diff = abs((float) $invoice->grand_total - $group['header_amount']);

        if ($diff <= 1.0) {
            return null;
        }

        return sprintf(
            'Total dihitung dari baris item (%s) berbeda dari kolom AMOUNT di file (%s).',
            number_format((float) $invoice->grand_total, 2),
            number_format($group['header_amount'], 2),
        );
    }

    private function createGoodsInvoice(array $group, string $customerId, string $warehouseId, array $resolvedItemIds): Invoice
    {
        $itemsById = Item::query()->with('uom')->whereIn('id', array_unique($resolvedItemIds))->get()->keyBy('id');

        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];

        foreach ($group['items'] as $i => $itemRow) {
            $item = $itemsById->get($resolvedItemIds[$i]);

            if ($item === null) {
                throw new BusinessException("Item master tidak ditemukan untuk salah satu baris pada dokumen \"{$group['document_number']}\".");
            }

            $qty = (float) $itemRow['qty'];
            $rate = (float) $itemRow['rate'];
            $amount = $qty * $rate;
            $subtotal += $amount;
            $taxTotal += $itemRow['tax'];
            $lines[] = ['item' => $item, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'tax' => $itemRow['tax']];
        }

        [$discountAmount, $taxTotal, $grandTotal] = $this->totals($group, $subtotal, $taxTotal);

        return DB::transaction(function () use ($group, $customerId, $warehouseId, $lines, $subtotal, $discountAmount, $taxTotal, $grandTotal) {
            $invoice = $this->invoiceRepository->create([
                'delivery_id' => null,
                'sales_order_id' => null,
                'warehouse_id' => $warehouseId,
                'customer_id' => $customerId,
                'invoice_type' => InvoiceType::GOODS->value,
                'invoice_date' => $group['date'],
                'due_date' => $group['date'],
                'subtotal' => round($subtotal, 2),
                'discount_amount' => $discountAmount,
                'discount_type' => DiscountType::AMOUNT->value,
                'discount_percentage' => null,
                'tax_id' => null,
                'tax_amount' => $taxTotal,
                'grand_total' => $grandTotal,
                'remarks' => 'Diimpor dari Sales Invoice Listing - Detail.',
                'reference_1' => $group['reference_1'],
                'reference_2' => $group['reference_2'],
                'source_document_number' => $group['document_number'],
                'import_source_type' => 'historical_invoice',
                'import_extra' => ['customer_code' => $group['customer_code'], 'header_amount' => $group['header_amount']],
            ]);

            foreach ($lines as $line) {
                $item = $line['item'];

                $this->invoiceItemRepository->create([
                    'invoice_id' => $invoice->id,
                    'delivery_item_id' => null,
                    'item_id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'uom' => $item->uom?->name,
                    'rate' => $line['rate'],
                    'qty' => (int) round($line['qty']),
                    'amount' => round($line['amount'], 2),
                    'tax_id' => null,
                    'tax_amount' => round($line['tax'], 2),
                ]);
            }

            return $invoice->fresh(['items']);
        });
    }

    private function createTransportationInvoice(array $group, string $customerId): Invoice
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];

        foreach ($group['items'] as $itemRow) {
            $qty = (float) $itemRow['qty'];
            $rate = (float) $itemRow['rate'];
            $amount = $qty * $rate;
            $subtotal += $amount;
            $taxTotal += $itemRow['tax'];
            $lines[] = ['description' => $itemRow['description'], 'qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'tax' => $itemRow['tax']];
        }

        [$discountAmount, $taxTotal, $grandTotal] = $this->totals($group, $subtotal, $taxTotal);

        return DB::transaction(function () use ($group, $customerId, $lines, $subtotal, $discountAmount, $taxTotal, $grandTotal) {
            $invoice = $this->invoiceRepository->create([
                'delivery_id' => null,
                'sales_order_id' => null,
                'customer_id' => $customerId,
                'invoice_type' => InvoiceType::TRANSPORTATION->value,
                'invoice_date' => $group['date'],
                'due_date' => $group['date'],
                'subtotal' => round($subtotal, 2),
                'discount_amount' => $discountAmount,
                'discount_type' => DiscountType::AMOUNT->value,
                'discount_percentage' => null,
                'tax_id' => null,
                'tax_amount' => $taxTotal,
                'grand_total' => $grandTotal,
                'remarks' => 'Diimpor dari Sales Invoice Listing - Detail.',
                'reference_1' => $group['reference_1'],
                'reference_2' => $group['reference_2'],
                'source_document_number' => $group['document_number'],
                'import_source_type' => 'historical_invoice',
                'import_extra' => ['customer_code' => $group['customer_code'], 'header_amount' => $group['header_amount']],
            ]);

            foreach ($lines as $line) {
                $this->invoiceItemRepository->create([
                    'invoice_id' => $invoice->id,
                    'delivery_item_id' => null,
                    'item_id' => null,
                    'item_code' => null,
                    'item_name' => $line['description'],
                    'uom' => null,
                    'rate' => $line['rate'],
                    'qty' => (int) round($line['qty']),
                    'amount' => round($line['amount'], 2),
                    'tax_id' => null,
                    'tax_amount' => round($line['tax'], 2),
                ]);
            }

            return $invoice->fresh(['items']);
        });
    }

    /** @return array{0: float, 1: float, 2: float} [discountAmount, taxTotal, grandTotal] */
    private function totals(array $group, float $subtotal, float $taxTotal): array
    {
        $discountAmount = round($group['header_disc'], 2);
        $taxTotal = round($taxTotal, 2);
        $grandTotal = round($subtotal - $discountAmount + $taxTotal, 2);

        if ($grandTotal < 0) {
            throw new BusinessException("Dokumen \"{$group['document_number']}\": grand total negatif — periksa data DISC/TAX di file.");
        }

        return [$discountAmount, $taxTotal, $grandTotal];
    }

    /** Map-to-existing-or-skip only, no 'create' — see this class's own docblock. */
    private function resolveId(string $code, array $classification, array $resolutions, array &$cache): ?string
    {
        if (isset($cache[$code])) {
            return $cache[$code];
        }

        $candidate = $classification[$code] ?? null;

        if ($candidate !== null && $candidate['status'] === 'match') {
            return $cache[$code] = $candidate['id'];
        }

        $resolution = $resolutions[$code] ?? null;

        if ($resolution === null || $resolution['action'] === 'skip') {
            return $cache[$code] = null;
        }

        return $cache[$code] = $resolution['target_id'] ?? null; // 'map'
    }
}
