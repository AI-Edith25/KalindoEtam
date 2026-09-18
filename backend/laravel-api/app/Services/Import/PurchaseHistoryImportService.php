<?php

namespace App\Services\Import;

use App\Enums\ImportBatchStatus;
use App\Models\GoodsReceipt;
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
 * Orchestrates the Purchase Report import — see docs plan for why this posts real Purchase
 * Order/Goods Receipt documents (unlike every other importer this session, which posts a raw
 * Journal Entry): these 3 report tabs (By Supplier/By Item/PO Tracking) are computed straight
 * from submitted PO/GRN, confirmed by reading their own repositories, no report table exists.
 *
 * Two source file shapes, auto-detected from row 1's title (ProductPurchaseReportParser::TITLE /
 * PurchaseOrderTrackingParser::TITLE), each producing a different document chain:
 * - Product Purchase Report -> one Direct (no-PO) Goods Receipt per document-number group.
 * - Purchase Order Tracking -> one real Purchase Order (single placeholder line item, auto-
 *   approved) per row, plus a PO-linked Goods Receipt when the row has GRN info.
 *
 * Both files are small (hundreds of rows at most) so this runs a full synchronous parse on
 * upload (preflight()) to build the resolve list, unlike the streaming approach an earlier
 * ~170k-row importer needed. If nothing needs resolving, the caller queues the real job
 * immediately and no resolve screen ever appears — the wizard-only-when-something's-wrong
 * posture the ticket asked for.
 */
class PurchaseHistoryImportService
{
    private const SUPPLIER_MATCH_THRESHOLD = 70.0;

    public function __construct(
        protected ProductPurchaseReportParser $productPurchaseReportParser,
        protected PurchaseOrderTrackingParser $purchaseOrderTrackingParser,
        protected FkResolver $fkResolver,
        protected GoodsReceiptService $goodsReceiptService,
        protected PurchaseOrderService $purchaseOrderService,
        protected ApprovalService $approvalService,
    ) {}

    /** @return 'product_purchase_report'|'purchase_order_tracking'|null */
    public function detectFileType(array $rawRows): ?string
    {
        $title = strtoupper(trim((string) ($rawRows[0][0] ?? '')));

        if (str_contains($title, ProductPurchaseReportParser::TITLE)) {
            return 'product_purchase_report';
        }

        if (str_contains($title, PurchaseOrderTrackingParser::TITLE)) {
            return 'purchase_order_tracking';
        }

        // Fallback cross-check by column-5 header shape, in case the title row was hand-edited —
        // Product Purchase Report has 11 columns (DATE..NET QTY.), Purchase Order Tracking 14
        // (PO DATE..OUTSTD PO). Never guesses below either shape; returns null (rejected) instead.
        $header = array_map(fn ($c) => is_string($c) ? strtoupper(trim($c)) : $c, $rawRows[4] ?? []);

        if (in_array('DOCUMENT #', $header, true) && in_array('NET QTY.', $header, true)) {
            return 'product_purchase_report';
        }

        if (in_array('PO NO', $header, true) && in_array('OUTSTD PO', $header, true)) {
            return 'purchase_order_tracking';
        }

        return null;
    }

    /**
     * @return array{error: string}|array{type: string, total_rows: int, warnings: array<int,string>, needs_resolution: array<int, array{category: string, value: string, status: string, suggestions: array}>}
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);
        $type = $this->detectFileType($rawRows);

        if ($type === null) {
            return ['error' => 'File ini tidak dikenali sebagai Product Purchase Report maupun Purchase Order Tracking — periksa kembali formatnya.'];
        }

        if ($type === 'product_purchase_report') {
            $parsed = $this->productPurchaseReportParser->parse($rawRows);
            $supplierNames = collect($parsed['groups'])->pluck('supplier_name')->all();
            $itemCodes = collect($parsed['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('item_code'))->all();
            $documentNumbers = collect($parsed['groups'])->pluck('document_number')->all();

            $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);
            $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', $itemCodes);
            $duplicates = GoodsReceipt::query()->whereIn('source_document_number', $documentNumbers)->pluck('source_document_number')->unique()->all();

            return [
                'type' => $type,
                'total_rows' => count($parsed['groups']),
                'warnings' => $parsed['warnings'],
                'needs_resolution' => $this->buildResolutionList($supplierClassification, $itemClassification, $duplicates),
            ];
        }

        $parsed = $this->purchaseOrderTrackingParser->parse($rawRows);
        $supplierNames = collect($parsed['rows'])->pluck('supplier_name')->all();
        $poNumbers = collect($parsed['rows'])->pluck('po_no')->all();

        $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);
        $duplicates = PurchaseOrder::query()->whereIn('source_document_number', $poNumbers)->pluck('source_document_number')->unique()->all();

        return [
            'type' => $type,
            'total_rows' => count($parsed['rows']),
            'warnings' => $parsed['warnings'],
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

        if (! in_array($type, ['product_purchase_report', 'purchase_order_tracking'], true)) {
            $batch->update(['status' => ImportBatchStatus::FAILED, 'failure_reason' => 'Jenis file tidak dikenali untuk batch ini.']);

            return;
        }

        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);
        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        $supplierCache = [];

        if ($type === 'product_purchase_report') {
            $this->importProductPurchaseReport($batch, $rawRows, $warehouseId, $resolutions, $supplierCache);
        } else {
            // The importing user must act as the approver for every auto-approved Purchase
            // Order below — a queued job has no HTTP session, so there's no "current user"
            // otherwise. Set once for the whole batch, not per row.
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

            $this->importPurchaseOrderTracking($batch, $rawRows, $warehouseId, $placeholderItemId, $resolutions, $supplierCache, $importingUser);
        }
    }

    private function importProductPurchaseReport(ImportBatch $batch, array $rawRows, ?string $warehouseId, array $resolutions, array &$supplierCache): void
    {
        $parsed = $this->productPurchaseReportParser->parse($rawRows);

        $supplierNames = collect($parsed['groups'])->pluck('supplier_name')->all();
        $itemCodes = collect($parsed['groups'])->flatMap(fn ($g) => collect($g['items'])->pluck('item_code'))->all();
        $supplierClassification = $this->fkResolver->classify(Supplier::class, 'supplier_name', $supplierNames);
        $itemClassification = $this->fkResolver->classify(Item::class, 'item_code', $itemCodes);

        $batch->update(['status' => ImportBatchStatus::PROCESSING, 'started_at' => now(), 'total_rows' => count($parsed['groups'])]);

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $report = array_map(fn ($w) => ['document_number' => 'WARNING', 'status' => 'needs_review', 'reason' => $w], $parsed['warnings']);

        foreach ($parsed['groups'] as $group) {
            $batch->increment('processed_rows');

            $outcome = $this->createDirectReceiptFromGroup($group, $warehouseId, $supplierClassification, $itemClassification, $resolutions, $supplierCache);
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

    private function createDirectReceiptFromGroup(array $group, ?string $warehouseId, array $supplierClassification, array $itemClassification, array $resolutions, array &$supplierCache): array
    {
        $base = ['document_number' => $group['document_number']];

        if (GoodsReceipt::query()->where('source_document_number', $group['document_number'])->exists()) {
            $decision = $resolutions['duplicate'][$group['document_number']]['action'] ?? 'skip';

            if ($decision !== 'proceed') {
                return [...$base, 'status' => 'needs_review', 'reason' => 'Nomor dokumen ini sudah pernah diimpor sebelumnya — dilewati.'];
            }
        }

        try {
            $supplier = $this->resolveSupplier($group['supplier_name'], $supplierClassification, $resolutions['supplier'] ?? [], $supplierCache);
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        if ($supplier === null) {
            return [...$base, 'status' => 'needs_review', 'reason' => "Supplier \"{$group['supplier_name']}\" tidak di-resolve — baris dilewati."];
        }

        $lines = [];
        $skippedItems = [];

        foreach ($group['items'] as $item) {
            $itemId = $this->resolveItemId($item['item_code'], $itemClassification, $resolutions['item'] ?? []);

            if ($itemId === null) {
                $skippedItems[] = "{$item['item_code']} ({$item['item_name']})";

                continue;
            }

            $lines[] = ['item_id' => $itemId, 'qty' => $item['qty'], 'rate' => $item['rate']];
        }

        if ($lines === []) {
            return [...$base, 'status' => 'needs_review', 'reason' => 'Semua item pada dokumen ini tidak di-resolve — tidak ada baris yang bisa diimpor.'];
        }

        try {
            $receipt = $this->goodsReceiptService->create([
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouseId,
                'receipt_date' => $group['date'],
                'due_date' => $group['date'],
                'remarks' => "Diimpor dari Product Purchase Report — {$group['document_number']}",
                'source_document_number' => $group['document_number'],
                'items' => $lines,
            ]);
            $this->goodsReceiptService->submit($receipt);
        } catch (Throwable $e) {
            return [...$base, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        $reason = $skippedItems !== [] ? 'Item tidak di-resolve, dilewati: '.implode(', ', $skippedItems).'.' : null;

        return [...$base, 'status' => $reason === null ? 'success' : 'needs_review', 'reason' => $reason];
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
