<?php

namespace App\Services\Import;

use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Orchestrates the Purchase Report import. Three source file shapes, auto-detected (title row,
 * falling back to header-column shape — see detectFileType()):
 *
 * - Supplier Purchase Listing -> one real Purchase Order (single placeholder line item,
 *   auto-approved), import_source_type = 'historical_invoice'. By Supplier/By Item never see it
 *   (both are Goods-Receipt-only) — flagged explicitly in preflight()'s warnings so nobody expects
 *   otherwise. Not a real Purchase Invoice — no AP/GL entry is created.
 * - Product Purchase Report -> no document/date/supplier exists in this file at all (a pure
 *   per-item period aggregate), so it creates nothing — only a per-item snapshot in
 *   preview_summary['item_snapshot'] for the By Item tab's separate "Data Import Historis"
 *   section.
 * - Purchase Order Tracking -> one real Purchase Order (single placeholder line item,
 *   auto-approved) per row, import_source_type = 'po_tracking_amount', plus a PO-linked Goods
 *   Receipt when the row has GRN info.
 *
 * Supplier Purchase Listing and Purchase Order Tracking both fabricate a PO with a single
 * placeholder line (qty=1) so PurchaseOrderService::create()'s total_amount math has something to
 * sum — the placeholder's fake qty must never be shown as real downstream (see
 * PoTrackingRepository), and the two are never conflated despite sharing this pattern: they carry
 * distinct import_source_type values and distinct badge labels in the UI.
 *
 * All 3 files are small (hundreds of rows at most) so this runs a full synchronous parse on
 * upload (preflight()) to build the resolve list, unlike the streaming approach an earlier
 * ~170k-row importer needed. The pre-import summary (ticket requirement) always shows before
 * anything is queued — store() never auto-dispatches, resolve() is the universal confirm step
 * even when there's nothing to resolve.
 */
class PurchaseHistoryImportService
{
    private const SUPPLIER_MATCH_THRESHOLD = 70.0;

    public const TYPE_LABELS = [
        'supplier_purchase_listing' => 'Supplier Purchase Listing',
        'product_purchase_report' => 'Product Purchase Report',
        'purchase_order_tracking' => 'Purchase Order Tracking',
    ];

    public function __construct(
        protected SupplierPurchaseListingParser $supplierPurchaseListingParser,
        protected ProductPurchaseReportParser $productPurchaseReportParser,
        protected PurchaseOrderTrackingParser $purchaseOrderTrackingParser,
        protected FkResolver $fkResolver,
        protected GoodsReceiptService $goodsReceiptService,
        protected PurchaseOrderService $purchaseOrderService,
        protected ApprovalService $approvalService,
    ) {}

    /** @return 'supplier_purchase_listing'|'product_purchase_report'|'purchase_order_tracking'|null */
    public function detectFileType(array $rawRows): ?string
    {
        $title = strtoupper(trim((string) ($rawRows[0][0] ?? '')));

        if (str_contains($title, SupplierPurchaseListingParser::TITLE)) {
            return 'supplier_purchase_listing';
        }

        if (str_contains($title, ProductPurchaseReportParser::TITLE)) {
            return 'product_purchase_report';
        }

        if (str_contains($title, PurchaseOrderTrackingParser::TITLE)) {
            return 'purchase_order_tracking';
        }

        // Fallback cross-check by row 5's header shape, in case the title row was hand-edited —
        // each shape has columns the other two never have. Never guesses below any shape; returns
        // null (rejected) instead.
        $header = array_map(fn ($c) => is_string($c) ? strtoupper(trim($c)) : $c, $rawRows[4] ?? []);

        if (in_array('DOCUMENT #', $header, true) && in_array('SUPPLIER NAME', $header, true) && in_array('AMOUNT (INCLUDE TAX)', $header, true)) {
            return 'supplier_purchase_listing';
        }

        if (in_array('ITEM #', $header, true) && in_array('DESCRIPTION', $header, true) && in_array('QTY. PUR.', $header, true)) {
            return 'product_purchase_report';
        }

        if (in_array('PO NO', $header, true) && in_array('PO DATE', $header, true) && in_array('OUTSTD PO', $header, true)) {
            return 'purchase_order_tracking';
        }

        return null;
    }

    /**
     * @return array{error: string}|array{type: string, type_label: string, total_rows: int, valid_count: int, skipped_count: int, computed_defaults: array<int,string>, warnings: array<int,string>, needs_resolution: array<int, array{category: string, value: string, status: string, suggestions: array}>}
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $type = $this->detectFileType($rawRows);

        if ($type === null) {
            return ['error' => 'File ini tidak dikenali sebagai Supplier Purchase Listing, Product Purchase Report, maupun Purchase Order Tracking — periksa kembali formatnya.'];
        }

        if ($type === 'supplier_purchase_listing') {
            $parsed = $this->supplierPurchaseListingParser->parse($rawRows);
            $skippedCount = count($parsed['warnings']);
            $supplierNames = collect($parsed['rows'])->pluck('supplier_name')->all();
            $documentNumbers = collect($parsed['rows'])->pluck('document_number')->all();

            $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);
            $duplicates = PurchaseOrder::query()->whereIn('source_document_number', $documentNumbers)->pluck('source_document_number')->unique()->all();

            return [
                'type' => $type,
                'type_label' => self::TYPE_LABELS[$type],
                'total_rows' => count($parsed['rows']),
                'valid_count' => count($parsed['rows']),
                'skipped_count' => $skippedCount,
                'computed_defaults' => ['SUPPLIER CODE / REFERENCE # / REFERENCE 2 # tidak wajib — akan dikosongkan jika tidak ada di file.'],
                'warnings' => [
                    ...$parsed['warnings'],
                    'File ini tidak akan muncul di tab By Supplier — tab itu dihitung dari Goods Receipt, bukan dari daftar dokumen ini.',
                    'Data ini bukan Purchase Invoice resmi — tidak membuat entri AP/GL, hanya representasi Purchase Order untuk keperluan tracking historis.',
                ],
                'needs_resolution' => $this->buildResolutionList($supplierClassification, [], $duplicates),
            ];
        }

        if ($type === 'product_purchase_report') {
            $parsed = $this->productPurchaseReportParser->parse($rawRows);
            $skippedCount = count($parsed['warnings']);
            $itemCodes = collect($parsed['items'])->pluck('item_code')->all();

            $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', $itemCodes);

            return [
                'type' => $type,
                'type_label' => self::TYPE_LABELS[$type],
                'total_rows' => count($parsed['items']),
                'valid_count' => count($parsed['items']),
                'skipped_count' => $skippedCount,
                'computed_defaults' => [
                    'UOM tidak ditemukan di file → akan dicocokkan dari master item.',
                    'Harga Terakhir/Terendah/Tertinggi tidak bisa dihitung dari file ini → akan diisi sama dengan Harga Rata-rata periode.',
                ],
                'warnings' => [
                    ...$parsed['warnings'],
                    'File ini tidak memiliki Document No — tidak membuat Purchase Order/Goods Receipt, hanya mengisi ringkasan Data Import Historis di tab By Item.',
                ],
                'needs_resolution' => $this->buildResolutionList([], $itemClassification, []),
            ];
        }

        $parsed = $this->purchaseOrderTrackingParser->parse($rawRows);
        $skippedCount = count($parsed['warnings']);
        $supplierNames = collect($parsed['rows'])->pluck('supplier_name')->all();
        $poNumbers = collect($parsed['rows'])->pluck('po_no')->all();

        $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);
        $duplicates = PurchaseOrder::query()->whereIn('source_document_number', $poNumbers)->pluck('source_document_number')->unique()->all();

        return [
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type],
            'total_rows' => count($parsed['rows']),
            'valid_count' => count($parsed['rows']),
            'skipped_count' => $skippedCount,
            'computed_defaults' => [],
            'warnings' => [
                ...$parsed['warnings'],
                'File ini tidak memiliki data quantity — kolom Qty Dipesan/Diterima akan ditampilkan sebagai "-" untuk baris hasil import ini.',
            ],
            'needs_resolution' => $this->buildResolutionList($supplierClassification, [], $duplicates),
        ];
    }

    /** @return array<int, array{category: string, value: string, status: string, suggestions: array}> */
    private function buildResolutionList(array $supplierClassification, array $itemClassification, array $duplicates): array
    {
        $entries = [];

        foreach ($supplierClassification as $value => $candidate) {
            if ($candidate['status'] !== 'match') {
                $entries[] = ['category' => 'supplier', 'value' => $value, 'status' => $candidate['status'], 'suggestions' => $candidate['suggestions']];
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
        $type = $mapping['type'] ?? null;
        $warehouseId = $mapping['warehouse_id'] ?? null;
        $placeholderItemId = $mapping['placeholder_item_id'] ?? null;
        $resolutions = $batch->fk_resolutions ?? ['supplier' => [], 'item' => [], 'duplicate' => []];

        if (! in_array($type, ['supplier_purchase_listing', 'product_purchase_report', 'purchase_order_tracking'], true)) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => 'Jenis file tidak dikenali untuk batch ini.']);

            return;
        }

        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if ($type === 'product_purchase_report') {
            // No document identity in this file at all — nothing to attach a Purchase Order or
            // Goods Receipt to, so this path never touches the approval flow below.
            $this->importProductPurchaseReport($batch, $rawRows);

            return;
        }

        // Both remaining types create+auto-approve a real Purchase Order — the importing user
        // must act as the approver for it (a queued job has no HTTP session, so there's no
        // "current user" otherwise). Set once for the whole batch, not per row.
        $importingUser = $batch->creator;

        if ($importingUser === null) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => 'Batch ini tidak memiliki user pembuat — tidak bisa menentukan siapa yang menyetujui Purchase Order secara otomatis.']);

            return;
        }

        Auth::setUser($importingUser);

        if (! $importingUser->can('purchase.orders.approve')) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => "User \"{$importingUser->name}\" tidak memiliki izin approve Purchase Order — import dibatalkan sebelum membuat apa pun."]);

            return;
        }

        $supplierCache = [];

        if ($type === 'supplier_purchase_listing') {
            $this->importSupplierPurchaseListing($batch, $rawRows, $placeholderItemId, $resolutions, $supplierCache, $importingUser);
        } else {
            $this->importPurchaseOrderTracking($batch, $rawRows, $warehouseId, $placeholderItemId, $resolutions, $supplierCache, $importingUser);
        }
    }

    /**
     * File B has no document/date/supplier at all — a pure per-item period aggregate — so this
     * never creates a Purchase Order or Goods Receipt. It resolves each item code against master
     * data and writes the result straight into preview_summary['item_snapshot'] for the By Item
     * tab's separate "Data Import Historis" section (never merged into that tab's live,
     * per-transaction Goods-Receipt sums — see the approved plan for why).
     */
    private function importProductPurchaseReport(ImportBatch $batch, array $rawRows): void
    {
        $parsed = $this->productPurchaseReportParser->parse($rawRows);

        $itemCodes = collect($parsed['items'])->pluck('item_code')->all();
        $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', $itemCodes);

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['items'])]);

        $itemResolutions = ($batch->fk_resolutions ?? [])['item'] ?? [];
        $success = 0;
        $needsReview = 0;
        $snapshot = [];

        foreach ($parsed['items'] as $item) {
            $batch->increment('processed_rows');

            $itemId = $this->resolveItemId($item['item_code'], $itemClassification, $itemResolutions);

            if ($itemId === null) {
                // Unresolved master data is reported as needs_review, never a hard failure — same
                // posture every other resolution path in this service already takes.
                $needsReview++;
                $snapshot[] = [...$item, 'item_id' => null, 'uom' => null, 'resolved' => false];

                continue;
            }

            $success++;
            $master = Item::query()->find($itemId);
            $snapshot[] = [...$item, 'item_id' => $itemId, 'uom' => $master?->uom?->name, 'resolved' => true];
        }

        $batch->update([
            'success_rows' => $success,
            'failed_rows' => 0,
            'preview_summary' => [
                'needs_review_rows' => $needsReview,
                'item_snapshot' => $snapshot,
                'period_from' => $parsed['period_from'],
                'period_to' => $parsed['period_to'],
                'warnings' => $parsed['warnings'],
            ],
            'status' => ImportBatchStatus::COMPLETED,
        ]);
    }

    /**
     * File A has a document total but no item-level breakdown at all — same shape problem File C
     * already solves via a single placeholder line item. Tagged import_source_type =
     * 'historical_invoice' (never 'po_tracking_amount') so the two are never conflated downstream
     * even though both are "PurchaseOrder-with-placeholder-item".
     */
    private function importSupplierPurchaseListing(ImportBatch $batch, array $rawRows, ?string $placeholderItemId, array $resolutions, array &$supplierCache, User $importingUser): void
    {
        $parsed = $this->supplierPurchaseListingParser->parse($rawRows);
        $supplierNames = collect($parsed['rows'])->pluck('supplier_name')->all();
        $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['rows'])]);

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $report = array_map(fn ($w) => ['document_number' => 'WARNING', 'status' => 'needs_review', 'reason' => $w], $parsed['warnings']);

        foreach ($parsed['rows'] as $row) {
            $batch->increment('processed_rows');

            $outcome = $this->createPurchaseOrderFromSupplierListingRow($row, $placeholderItemId, $supplierClassification, $resolutions, $supplierCache, $importingUser);
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

    private function createPurchaseOrderFromSupplierListingRow(array $row, ?string $placeholderItemId, array $supplierClassification, array $resolutions, array &$supplierCache, User $importingUser): array
    {
        $base = ['document_number' => $row['document_number']];

        if (PurchaseOrder::query()->where('source_document_number', $row['document_number'])->exists()) {
            $decision = $resolutions['duplicate'][$row['document_number']]['action'] ?? 'skip';

            if ($decision !== 'proceed') {
                return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
            }
        }

        try {
            $supplier = $this->resolveSupplier($row['supplier_name'], $supplierClassification, $resolutions['supplier'] ?? [], $supplierCache);
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        if ($supplier === null) {
            return [...$base, 'status' => 'needs_review', 'reason' => "Supplier \"{$row['supplier_name']}\" tidak di-resolve — baris dilewati."];
        }

        try {
            $purchaseOrder = $this->purchaseOrderService->create([
                'supplier_id' => $supplier->id,
                'order_date' => $row['date'],
                'remarks' => 'Diimpor dari Supplier Purchase Listing — detail item tidak tersedia dari sumber ini.',
                'source_document_number' => $row['document_number'],
                'items' => [['item_id' => $placeholderItemId, 'qty' => 1, 'rate' => $row['amount']]],
            ]);

            $purchaseOrder->update([
                'import_source_type' => 'historical_invoice',
                'import_extra' => [
                    'reference_no' => $row['reference_no'],
                    'reference_no_2' => $row['reference_no_2'],
                    'supplier_code' => $row['supplier_code'],
                ],
            ]);

            $flow = $this->approvalService->requestApproval($purchaseOrder);
            $this->approvalService->approve($flow, "Auto-approved during historical import (imported by {$importingUser?->name}).");
            $this->purchaseOrderService->submit($purchaseOrder);
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        return [...$base, 'status' => 'success', 'reason' => null];
    }

    private function importPurchaseOrderTracking(ImportBatch $batch, array $rawRows, ?string $warehouseId, ?string $placeholderItemId, array $resolutions, array &$supplierCache, User $importingUser): void
    {
        $parsed = $this->purchaseOrderTrackingParser->parse($rawRows);
        $supplierNames = collect($parsed['rows'])->pluck('supplier_name')->all();
        $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['rows'])]);

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $report = array_map(fn ($w) => ['document_number' => 'WARNING', 'status' => 'needs_review', 'reason' => $w], $parsed['warnings']);

        foreach ($parsed['rows'] as $row) {
            $batch->increment('processed_rows');

            $outcome = $this->createPurchaseOrderFromRow($row, $warehouseId, $placeholderItemId, $supplierClassification, $resolutions, $supplierCache, $importingUser);
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

    private function createPurchaseOrderFromRow(array $row, ?string $warehouseId, ?string $placeholderItemId, array $supplierClassification, array $resolutions, array &$supplierCache, User $importingUser): array
    {
        $base = ['document_number' => $row['po_no']];

        if (PurchaseOrder::query()->where('source_document_number', $row['po_no'])->exists()) {
            $decision = $resolutions['duplicate'][$row['po_no']]['action'] ?? 'skip';

            if ($decision !== 'proceed') {
                return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor PO ini sudah pernah diimpor sebelumnya — dilewati.'];
            }
        }

        try {
            $supplier = $this->resolveSupplier($row['supplier_name'], $supplierClassification, $resolutions['supplier'] ?? [], $supplierCache);
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        if ($supplier === null) {
            return [...$base, 'status' => 'needs_review', 'reason' => "Supplier \"{$row['supplier_name']}\" tidak di-resolve — baris dilewati."];
        }

        $remarksParts = array_filter([
            $row['quote_no'] !== null ? "Quote: {$row['quote_no']}" : null,
            $row['request_by'] !== null ? "Request By: {$row['request_by']}" : null,
            $row['requisition_no'] !== null ? "Requisition: {$row['requisition_no']}" : null,
        ]);

        try {
            $purchaseOrder = $this->purchaseOrderService->create([
                'supplier_id' => $supplier->id,
                'order_date' => $row['po_date'],
                'remarks' => 'Diimpor dari Purchase Order Tracking'.($remarksParts !== [] ? ' — '.implode('; ', $remarksParts) : ''),
                'source_document_number' => $row['po_no'],
                'items' => [['item_id' => $placeholderItemId, 'qty' => 1, 'rate' => $row['amount']]],
            ]);

            // PurchaseOrderService::create() only whitelists the fields every real, manually-
            // created PO shares — these import-only columns are set straight on the model here so
            // the shared service stays untouched by this importer's needs.
            $purchaseOrder->update([
                'import_source_type' => 'po_tracking_amount',
                'import_extra' => [
                    'quote_no' => $row['quote_no'],
                    'request_by' => $row['request_by'],
                    'requisition_no' => $row['requisition_no'],
                    'supplier_invoice_no' => $row['supplier_invoice_no'],
                    'supplier_do_no' => $row['grn_reference'],
                ],
                'amount_billed' => $row['amount_billed'],
                'outstanding_grn_value' => $row['outstd_grn'],
                'outstanding_po_value' => $row['outstd_po'],
            ]);

            $flow = $this->approvalService->requestApproval($purchaseOrder);
            $this->approvalService->approve($flow, "Auto-approved during historical import (imported by {$importingUser?->name}).");
            $this->purchaseOrderService->submit($purchaseOrder);

            if ($row['has_grn']) {
                $purchaseOrder->load('items');
                $poItem = $purchaseOrder->items->first();

                $receipt = $this->goodsReceiptService->create([
                    'purchase_order_id' => $purchaseOrder->id,
                    'warehouse_id' => $warehouseId,
                    'receipt_date' => $row['grn_date'],
                    'due_date' => $row['grn_date'],
                    'remarks' => "Diimpor dari Purchase Order Tracking — GRN {$row['grn_reference']}",
                    'source_document_number' => $row['grn_reference'],
                    'items' => [['purchase_order_item_id' => $poItem->id, 'qty' => 1]],
                ]);
                $this->goodsReceiptService->submit($receipt);
            }
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        return [...$base, 'status' => 'success', 'reason' => null];
    }

    /** @return array<string, mixed> supplier resolution decisions, keyed by raw name — action: 'create'|'map'|'skip', target_id: ?string */
    private function resolveSupplier(string $name, array $classification, array $resolutions, array &$cache): ?Supplier
    {
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $candidate = $classification[$name] ?? null;

        if ($candidate !== null && $candidate['status'] === 'match') {
            return $cache[$name] = Supplier::query()->find($candidate['id']);
        }

        $resolution = $resolutions[$name] ?? null;

        if ($resolution === null) {
            return null; // never resolved — caller reports it, no guessing
        }

        if ($resolution['action'] === 'skip') {
            return $cache[$name] = null;
        }

        if ($resolution['action'] === 'map') {
            return $cache[$name] = Supplier::query()->find($resolution['target_id']);
        }

        // 'create' — only supplier_code/supplier_name are actually required
        // (StoreSupplierRequest), so a bare name is enough to satisfy master data.
        return $cache[$name] = Supplier::query()->create([
            'supplier_code' => $this->generateSupplierCode($name),
            'supplier_name' => $name,
            'is_active' => true,
        ]);
    }

    private function generateSupplierCode(string $name): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? '');
        $base = 'LEGACY-'.substr($slug, 0, 12);
        $code = $base;
        $suffix = 1;

        while (Supplier::query()->where('supplier_code', $code)->exists()) {
            $code = "{$base}-{$suffix}";
            $suffix++;
        }

        return $code;
    }

    private function resolveItemId(string $code, array $classification, array $resolutions): ?string
    {
        $candidate = $classification[$code] ?? null;

        if ($candidate !== null && $candidate['status'] === 'match') {
            return $candidate['id'];
        }

        $resolution = $resolutions[$code] ?? null;

        if ($resolution === null || $resolution['action'] === 'skip') {
            return null;
        }

        return $resolution['target_id'] ?? null; // 'map' — no 'create' option for Item, see plan
    }
}
