<?php

namespace App\Services\Import;

use App\Enums\DiscountType;
use App\Enums\DocumentStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\InvoiceType;
use App\Exceptions\BusinessException;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\MiscellaneousItem;
use App\Models\Warehouse;
use App\Repositories\InvoiceItemRepository;
use App\Repositories\InvoiceRepository;
use App\Services\InvoiceService;
use App\Support\DocumentDuplicateChecker;
use App\Support\DocumentKeyNormalizer;
use App\Support\DuplicateKeyViolation;
use App\Support\ImportErrorReportWriter;
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
 * submit()/cancel() key off it to skip stock and GL (that account's balance already comes from
 * the Trial Balance import as one aggregate journal entry — a per-invoice entry here would double
 * it). AccountsReceivable IS still created per invoice, same as a live Invoice — fixed 2026-10-02,
 * see [[project_erp_sales_invoice_ar_backfill]]: without it, a real Official Receipt against one of
 * these customers can never be allocated (PaymentAllocationService only ever queries that table),
 * and the (separate, standalone, never-synced) Customer Outstanding Bills snapshot report would
 * keep showing the invoice as unpaid forever regardless of any later payment. Invoice::submit()
 * still flips status to Submitted, so an imported row looks and behaves like a real one everywhere
 * else.
 *
 * Customer/Item codes that don't resolve are map-to-existing-or-skip only — no auto-create of
 * master data (confirmed with the user), unlike Supplier in PurchaseHistoryImportService.
 *
 * A Goods invoice's "ITEM" column doesn't only hold Item master codes — a line can also be a
 * Miscellaneous charge billed alongside real items (e.g. "TRANSPORT" on an SI document, not its
 * own TR document). classifyItemsOrMisc() tries Item.item_code first, then MiscellaneousItem.
 * misc_code for whatever didn't match; a misc-matched line is written the same freeform way
 * Transportation invoices already are (item_id/item_code null, item_name = description) — see
 * createGoodsInvoice().
 *
 * Location (SalesInvoiceHistoryParser's per-invoice majority-voted LOCATION code, e.g. "BPP") is
 * resolved against Warehouse the same way, but unlike customer/item it's optional - an unresolved
 * code never rejects the document, it just leaves Invoice.location_warehouse_id null. Never written
 * to Invoice.warehouse_id, which is Direct-Goods-only and drives real FIFO stock consumption
 * (Invoice::isDirectGoods()) - confirmed with the user that a single file legitimately mixes
 * multiple real-world locations, so there is no single "the" warehouse for a whole import batch.
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

        [$customerClassification, $itemClassification, $locationClassification, $duplicates] = $this->classify($parsed['invoices']);

        return [
            'total_rows' => count($parsed['invoices']),
            'valid_count' => count($parsed['invoices']),
            'skipped_count' => count($parsed['warnings']),
            'warnings' => [
                ...$parsed['warnings'],
                'Import ini tidak membuat entri Accounts Receivable maupun jurnal GL — AR/GL historis sudah diisi lewat import Customer Outstanding terpisah. Stock juga tidak berubah.',
            ],
            'needs_resolution' => $this->buildResolutionList($customerClassification, $itemClassification, $locationClassification, $duplicates),
        ];
    }

    /**
     * @return array{0: array, 1: array, 2: array, 3: array<int,string>} [customerClassification, itemClassification,
     *                                                           locationClassification, fileDocumentNumbersMatchingACancelledInvoice]
     *
     * A duplicate against a *live* (non-cancelled) Invoice is always rejected outright in
     * importOne() — see duplicateKeyField()/cancel() on Invoice, which frees its normalized slot
     * so only a live row can ever match there, nothing to resolve. The only duplicate that still
     * needs an operator decision is one against a *cancelled* Invoice (resolvable via "proceed"),
     * which is what this method surfaces for the preflight/resolution UI.
     */
    private function classify(array $invoices): array
    {
        $customerCodes = collect($invoices)->pluck('customer_code')->all();
        $itemCodes = collect($invoices)
            ->filter(fn ($invoice) => $invoice['type'] === 'goods')
            ->flatMap(fn ($invoice) => collect($invoice['items'])->pluck('item_code'))
            ->all();

        $customerClassification = $this->fkResolver->classify(Customer::class, 'customer_code', $customerCodes);
        $itemClassification = $this->classifyItemsOrMisc($itemCodes);
        $locationCodes = collect($invoices)->pluck('location_code')->filter()->unique()->values()->all();
        $locationClassification = $this->classifyLocations($locationCodes);

        $cancelledNormalizedNumbers = Invoice::query()
            ->where('status', DocumentStatus::CANCELLED)
            ->whereNotNull('source_document_number')
            ->pluck('source_document_number')
            ->map(fn ($value) => DocumentKeyNormalizer::normalize($value))
            ->filter()
            ->unique()
            ->flip();

        $cancelledDuplicates = collect($invoices)
            ->pluck('document_number')
            ->unique()
            ->filter(fn ($raw) => $cancelledNormalizedNumbers->has(DocumentKeyNormalizer::normalize($raw)))
            ->values()
            ->all();

        return [$customerClassification, $itemClassification, $locationClassification, $cancelledDuplicates];
    }

    /**
     * Item master first; whatever doesn't match there is retried against MiscellaneousItem — see
     * this class's own docblock. Each entry gets a 'kind' key ('item'|'misc') alongside FkResolver's
     * usual status/id/suggestions shape, so downstream code knows which master a match came from.
     *
     * @param  array<int, string>  $itemCodes
     * @return array<string, array{status: string, id: string|null, suggestions: array, kind: string}>
     */
    private function classifyItemsOrMisc(array $itemCodes): array
    {
        $itemMatches = $this->fkResolver->classify(Item::class, 'item_code', $itemCodes);

        $unresolvedCodes = collect($itemMatches)
            ->filter(fn ($candidate) => $candidate['status'] !== 'match')
            ->keys()
            ->all();

        $miscMatches = $this->fkResolver->classify(MiscellaneousItem::class, 'misc_code', $unresolvedCodes);

        $result = [];
        foreach ($itemMatches as $code => $candidate) {
            if ($candidate['status'] === 'match') {
                $result[$code] = [...$candidate, 'kind' => 'item'];

                continue;
            }

            $miscCandidate = $miscMatches[$code] ?? null;
            $result[$code] = $miscCandidate !== null && $miscCandidate['status'] === 'match'
                ? [...$miscCandidate, 'kind' => 'misc']
                : [...$candidate, 'kind' => 'item'];
        }

        return $result;
    }

    /**
     * Prefix match only (confirmed with the user) — a file's LOCATION code (e.g. "BPP") is a prefix
     * of an existing Warehouse's own code or name (e.g. "BPP" / "BPP - Gudang Utama"), not an exact
     * or fuzzy match like FkResolver::classify() uses for customer/item. Kept separate from
     * FkResolver rather than adding a mode flag there — a distinct matching strategy for a single
     * caller doesn't belong in that shared, already-used-elsewhere contract.
     *
     * @param  array<int, string>  $codes
     * @return array<string, array{status: string, id: string|null, suggestions: array<int, array{id: string, value: string, score: float}>}>
     */
    private function classifyLocations(array $codes): array
    {
        $warehouses = Warehouse::query()->get(['id', 'code', 'name']);
        $result = [];

        foreach ($codes as $code) {
            $needle = mb_strtolower($code);
            $match = $warehouses->first(
                fn ($warehouse) => str_starts_with(mb_strtolower($warehouse->code), $needle) || str_starts_with(mb_strtolower($warehouse->name), $needle)
            );

            if ($match) {
                $result[$code] = ['status' => 'match', 'id' => $match->id, 'suggestions' => []];

                continue;
            }

            $result[$code] = [
                'status' => 'no_match',
                'id' => null,
                'suggestions' => $warehouses->map(fn ($warehouse) => ['id' => $warehouse->id, 'value' => "{$warehouse->code} — {$warehouse->name}", 'score' => 0.0])->take(5)->all(),
            ];
        }

        return $result;
    }

    /** @return array<int, array{category: string, value: string, status: string, suggestions: array}> */
    private function buildResolutionList(array $customerClassification, array $itemClassification, array $locationClassification, array $duplicates): array
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

        foreach ($locationClassification as $value => $candidate) {
            if ($candidate['status'] !== 'match') {
                $entries[] = ['category' => 'location', 'value' => $value, 'status' => $candidate['status'], 'suggestions' => $candidate['suggestions']];
            }
        }

        foreach ($duplicates as $value) {
            $entries[] = ['category' => 'duplicate', 'value' => $value, 'status' => 'duplicate', 'suggestions' => []];
        }

        return $entries;
    }

    public function import(ImportBatch $batch): void
    {
        $resolutions = $batch->fk_resolutions ?? ['customer' => [], 'item' => [], 'location' => [], 'duplicate' => []];

        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $parsed = $this->parser->parse($rawRows);

        [$customerClassification, $itemClassification, $locationClassification] = $this->classify($parsed['invoices']);

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['invoices'])]);

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $report = array_map(fn ($w) => ['document_number' => 'WARNING', 'status' => 'needs_review', 'reason' => $w], $parsed['warnings']);
        $customerCache = [];
        $itemCache = [];
        $locationCache = [];
        $seenNormalizedNumbers = [];

        foreach ($parsed['invoices'] as $group) {
            $batch->increment('processed_rows');

            $outcome = $this->importOne($group, $customerClassification, $itemClassification, $locationClassification, $resolutions, $customerCache, $itemCache, $locationCache, $batch, $seenNormalizedNumbers);
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

        ImportErrorReportWriter::attachRejectedRows($batch, $report);
    }

    private function importOne(array $group, array $customerClassification, array $itemClassification, array $locationClassification, array $resolutions, array &$customerCache, array &$itemCache, array &$locationCache, ImportBatch $batch, array &$seenNormalizedNumbers): array
    {
        $base = ['document_number' => $group['document_number']];
        $rejection = DocumentDuplicateChecker::reject(Invoice::class, $group['document_number'], $resolutions, $seenNormalizedNumbers, ['reference_1', 'reference_2']);

        if ($rejection !== null) {
            return [...$base, 'status' => 'needs_review', 'reason' => $rejection];
        }

        $customerId = $this->resolveId($group['customer_code'], $customerClassification, $resolutions['customer'] ?? [], $customerCache);

        if ($customerId === null) {
            return [...$base, 'status' => 'needs_review', 'reason' => "Customer \"{$group['customer_code']}\" tidak di-resolve — dokumen dilewati."];
        }

        // Cosmetic only (Invoice.location_warehouse_id) — an unresolved/blank code never rejects
        // the document, unlike customer/item above. See this class's own docblock.
        $locationWarehouseId = $group['location_code'] !== null
            ? $this->resolveId($group['location_code'], $locationClassification, $resolutions['location'] ?? [], $locationCache)
            : null;

        try {
            if ($group['type'] === 'transportation') {
                $invoice = $this->createTransportationInvoice($group, $customerId, $locationWarehouseId);
            } else {
                $resolvedItems = [];
                foreach ($group['items'] as $itemRow) {
                    $resolved = $this->resolveItemOrMisc($itemRow['item_code'], $itemClassification, $resolutions['item'] ?? [], $itemCache);

                    if ($resolved === null) {
                        return [...$base, 'status' => 'needs_review', 'reason' => "Item \"{$itemRow['item_code']}\" tidak di-resolve — dokumen dilewati."];
                    }

                    $resolvedItems[] = $resolved;
                }

                $invoice = $this->createGoodsInvoice($group, $customerId, $locationWarehouseId, $resolvedItems);
            }

            $invoice->update(['source' => 'import', 'import_batch_id' => $batch->id, 'imported_at' => now()]);
            $mismatchWarning = $this->crossCheckAmount($group, $invoice);
            $this->invoiceService->submit($invoice);
        } catch (Throwable $e) {
            if (DuplicateKeyViolation::detected($e)) {
                return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah ada pada data yang aktif — ditolak.'];
            }

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

    private function createGoodsInvoice(array $group, string $customerId, ?string $locationWarehouseId, array $resolvedItems): Invoice
    {
        $itemIds = collect($resolvedItems)->where('kind', 'item')->pluck('id')->unique()->all();
        $miscIds = collect($resolvedItems)->where('kind', 'misc')->pluck('id')->unique()->all();

        $itemsById = Item::query()->with('uom')->whereIn('id', $itemIds)->get()->keyBy('id');
        $miscById = MiscellaneousItem::query()->whereIn('id', $miscIds)->get()->keyBy('id');

        $subtotal = 0.0;
        $taxTotal = 0.0;
        $lines = [];

        foreach ($group['items'] as $i => $itemRow) {
            $resolved = $resolvedItems[$i];
            $item = $resolved['kind'] === 'item' ? $itemsById->get($resolved['id']) : null;
            $misc = $resolved['kind'] === 'misc' ? $miscById->get($resolved['id']) : null;

            if ($item === null && $misc === null) {
                throw new BusinessException("Item master tidak ditemukan untuk salah satu baris pada dokumen \"{$group['document_number']}\".");
            }

            $qty = (float) $itemRow['qty'];
            $rate = (float) $itemRow['rate'];
            $amount = $qty * $rate;
            $subtotal += $amount;
            $taxTotal += $itemRow['tax'];
            $lines[] = ['item' => $item, 'description' => $misc?->description ?? $itemRow['description'], 'qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'tax' => $itemRow['tax']];
        }

        [$discountAmount, $taxTotal, $grandTotal] = $this->totals($group, $subtotal, $taxTotal);

        return DB::transaction(function () use ($group, $customerId, $locationWarehouseId, $lines, $subtotal, $discountAmount, $taxTotal, $grandTotal) {
            $invoice = $this->invoiceRepository->create([
                'delivery_id' => null,
                'sales_order_id' => null,
                // Never warehouse_id — that column is Direct-Goods-only and drives real FIFO stock
                // consumption (Invoice::isDirectGoods()); a historical import never moves stock.
                'location_warehouse_id' => $locationWarehouseId,
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
                    'item_id' => $item?->id,
                    'item_code' => $item?->item_code,
                    'item_name' => $item?->item_name ?? $line['description'],
                    'uom' => $item?->uom?->name,
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

    private function createTransportationInvoice(array $group, string $customerId, ?string $locationWarehouseId): Invoice
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

        return DB::transaction(function () use ($group, $customerId, $locationWarehouseId, $lines, $subtotal, $discountAmount, $taxTotal, $grandTotal) {
            $invoice = $this->invoiceRepository->create([
                'delivery_id' => null,
                'sales_order_id' => null,
                'location_warehouse_id' => $locationWarehouseId,
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

    /**
     * Like resolveId(), but the classification carries a 'kind' (item|misc) that the caller needs
     * to pick the right master when building the InvoiceItem row. A manual resolution's target_id
     * is always an Item id — the resolution UI only ever surfaces Item suggestions, never Misc ones.
     *
     * @return array{kind: string, id: string}|null
     */
    private function resolveItemOrMisc(string $code, array $classification, array $resolutions, array &$cache): ?array
    {
        if (array_key_exists($code, $cache)) {
            return $cache[$code];
        }

        $candidate = $classification[$code] ?? null;

        if ($candidate !== null && $candidate['status'] === 'match') {
            return $cache[$code] = ['kind' => $candidate['kind'], 'id' => $candidate['id']];
        }

        $resolution = $resolutions[$code] ?? null;

        if ($resolution === null || $resolution['action'] === 'skip' || ($resolution['target_id'] ?? null) === null) {
            return $cache[$code] = null;
        }

        return $cache[$code] = ['kind' => 'item', 'id' => $resolution['target_id']];
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
