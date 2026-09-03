<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\Warehouse;
use App\Repositories\ItemWarehousePriceRepository;
use App\Services\Import\ImportFileReader;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemWarehousePriceService
{
    public function __construct(
        protected ItemWarehousePriceRepository $itemWarehousePriceRepository,
        protected ItemService $itemService,
        protected AuditLogService $auditLogService,
    ) {}

    /** All overrides — small table (item x warehouse), no pagination. Frontend crosses this with its own Items/Warehouses lookups to render the matrix. */
    public function list(): Collection
    {
        return $this->itemWarehousePriceRepository->allWithRelations();
    }

    /**
     * Every write — including a single-cell edit — goes through this one bulk endpoint (a
     * 1-cell array is still a valid batch). Atomic: every cell is already shape/range-validated
     * by BulkUpdateItemWarehousePriceRequest before this runs, so a runtime failure here can
     * only be systemic, not a legitimate per-cell business rule — see PaymentAllocationService::
     * allocateBatch() for the same lock-then-apply-then-one-audit-entry shape.
     *
     * @param  array<int, array{item_id: string, warehouse_id: string, rate: ?float}>  $cells  rate null = delete override
     * @return array<int, array{item_id: string, warehouse_id: string, rate: ?float, status: string}>
     */
    public function bulkUpdate(array $cells): array
    {
        return DB::transaction(function () use ($cells) {
            $this->assertNoDuplicateCells($cells);

            $locked = $this->itemWarehousePriceRepository->lockManyForBatch(
                array_column($cells, 'item_id'),
                array_column($cells, 'warehouse_id'),
            );

            [$results, $changeCount] = $this->applyCells($locked, $cells);

            if ($changeCount > 0) {
                $this->auditLogService->record(
                    'warehouse_price_changed',
                    'item_warehouse_prices',
                    "{$changeCount} cell(s) changed in one batch.",
                    ['cells' => $cells],
                );
            }

            return $results;
        });
    }

    /** Shared by bulkUpdate() and importCommit() — callers own the transaction and the audit-log entry. */
    private function applyCells(Collection $locked, array $cells): array
    {
        $results = [];
        $changeCount = 0;

        foreach ($cells as $cell) {
            $key = "{$cell['item_id']}:{$cell['warehouse_id']}";
            $row = $locked->get($key);
            $oldRate = $row?->rate;
            $newRate = $cell['rate'] ?? null;

            if ($newRate === null) {
                if ($row) {
                    $this->itemWarehousePriceRepository->delete($row);
                }
            } elseif ($row) {
                $this->itemWarehousePriceRepository->update($row, ['rate' => $newRate]);
            } else {
                $this->itemWarehousePriceRepository->create([
                    'item_id' => $cell['item_id'],
                    'warehouse_id' => $cell['warehouse_id'],
                    'rate' => $newRate,
                ]);
            }

            if ((string) $oldRate !== (string) $newRate) {
                $changeCount++;
            }

            $results[] = ['item_id' => $cell['item_id'], 'warehouse_id' => $cell['warehouse_id'], 'rate' => $newRate, 'status' => 'saved'];
        }

        return [$results, $changeCount];
    }

    private function assertNoDuplicateCells(array $cells): void
    {
        if (count($cells) < 1) {
            throw new BusinessException('At least one cell is required.');
        }

        $keys = array_map(fn ($c) => "{$c['item_id']}:{$c['warehouse_id']}", $cells);

        if (count($keys) !== count(array_unique($keys))) {
            throw new BusinessException('The same item/warehouse cell cannot appear twice in one batch.');
        }
    }

    public function export(): StreamedResponse
    {
        $warehouses = Warehouse::query()->orderBy('code')->get();

        return response()->streamDownload(function () use ($warehouses) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['item_code', 'item_name', 'standard_rate', ...$warehouses->pluck('code'), 'sync_to_main_wh']);

            Item::query()->with('itemWarehousePrices')->orderBy('item_code')->chunk(500, function ($items) use ($handle, $warehouses) {
                foreach ($items as $item) {
                    $overridesByWarehouse = $item->itemWarehousePrices->keyBy('warehouse_id');

                    $row = [$item->item_code, $item->item_name, $item->standard_rate];
                    foreach ($warehouses as $warehouse) {
                        // Blank = no override (round-trip fidelity), not standard_rate.
                        $row[] = $overridesByWarehouse->get($warehouse->id)?->rate ?? '';
                    }
                    $row[] = $item->sync_to_main_wh ? '1' : '0';

                    fputcsv($handle, $row);
                }
            });

            fclose($handle);
        }, 'item-warehouse-prices-export.csv');
    }

    /**
     * @return array{
     *     to_create: int, to_update: int, to_delete: int, sync_changes: int, unchanged: int,
     *     errors: array<int, array{row: int, reason: string}>,
     * }
     */
    public function importPreview(UploadedFile $file): array
    {
        $parsed = $this->parseWideFile($file);

        return [
            'to_create' => $parsed['to_create'],
            'to_update' => $parsed['to_update'],
            'to_delete' => $parsed['to_delete'],
            'sync_changes' => $parsed['sync_changes'],
            'unchanged' => $parsed['unchanged'],
            'errors' => $parsed['errors'],
        ];
    }

    /** @return array{cells_applied: int, sync_changes: int, errors: array<int, array{row: int, reason: string}>} */
    public function importCommit(UploadedFile $file): array
    {
        $parsed = $this->parseWideFile($file);

        return DB::transaction(function () use ($parsed) {
            $cells = $parsed['cells'];

            if (count($cells) > 0) {
                $locked = $this->itemWarehousePriceRepository->lockManyForBatch(
                    array_column($cells, 'item_id'),
                    array_column($cells, 'warehouse_id'),
                );
                [, $changeCount] = $this->applyCells($locked, $cells);
            } else {
                $changeCount = 0;
            }

            foreach ($parsed['syncByValue'] as $value => $itemIds) {
                if (count($itemIds) > 0) {
                    $this->itemService->bulkSetSyncToMainWh($itemIds, (bool) $value);
                }
            }

            if ($changeCount > 0 || count($parsed['syncByValue'][0] ?? []) > 0 || count($parsed['syncByValue'][1] ?? []) > 0) {
                $this->auditLogService->record(
                    'warehouse_price_imported',
                    'item_warehouse_prices',
                    "Import applied: {$changeCount} cell(s) changed, {$parsed['sync_changes']} sync flag(s) changed.",
                );
            }

            return ['cells_applied' => $changeCount, 'sync_changes' => $parsed['sync_changes'], 'errors' => $parsed['errors']];
        });
    }

    /**
     * Fixed-shape wide CSV/XLSX (item_code, item_name, standard_rate, <one column per warehouse
     * code>, sync_to_main_wh) — a small dedicated importer, not the 5-step Import Wizard
     * (composite item+warehouse key). item_name/standard_rate are read-only context for the
     * user in Excel, never written back.
     *
     * @return array{
     *     cells: array<int, array{item_id: string, warehouse_id: string, rate: ?float}>,
     *     syncByValue: array<int, string[]>,
     *     to_create: int, to_update: int, to_delete: int, sync_changes: int, unchanged: int,
     *     errors: array<int, array{row: int, reason: string}>,
     * }
     */
    private function parseWideFile(UploadedFile $file): array
    {
        $path = $file->store('imports', 'local');
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = ImportFileReader::readRaw(Storage::disk('local')->path($path), $extension);
        Storage::disk('local')->delete($path);

        $empty = ['cells' => [], 'syncByValue' => [0 => [], 1 => []], 'to_create' => 0, 'to_update' => 0, 'to_delete' => 0, 'sync_changes' => 0, 'unchanged' => 0, 'errors' => []];

        $header = array_map(fn ($h) => trim((string) $h), $rows[0] ?? []);
        $itemCodeCol = array_search('item_code', $header, true);
        $rateCol = array_search('standard_rate', $header, true);
        $syncCol = array_search('sync_to_main_wh', $header, true);

        if ($itemCodeCol === false || $rateCol === false || $syncCol === false) {
            $empty['errors'][] = ['row' => 0, 'reason' => 'Header must include item_code, item_name, standard_rate, one column per warehouse code, and sync_to_main_wh.'];

            return $empty;
        }

        // Warehouse columns are everything strictly between standard_rate and sync_to_main_wh.
        $warehouseColumns = [];
        for ($col = $rateCol + 1; $col < $syncCol; $col++) {
            $code = $header[$col] ?? '';
            if ($code === '') {
                continue;
            }

            $warehouse = Warehouse::query()->where('code', $code)->first();
            if (! $warehouse) {
                $empty['errors'][] = ['row' => 0, 'reason' => "Column \"{$code}\" does not match any warehouse code."];

                return $empty;
            }

            $warehouseColumns[$col] = $warehouse->id;
        }

        $existing = $this->itemWarehousePriceRepository->allWithRelations()
            ->keyBy(fn ($row) => "{$row->item_id}:{$row->warehouse_id}");

        $cells = [];
        $syncByValue = [0 => [], 1 => []];
        [$toCreate, $toUpdate, $toDelete, $syncChanges, $unchanged, $errors] = [0, 0, 0, 0, 0, []];

        foreach (array_slice($rows, 1) as $i => $line) {
            $rowNumber = $i + 2;
            $itemCode = trim((string) ($line[$itemCodeCol] ?? ''));

            if ($itemCode === '') {
                continue;
            }

            $item = Item::query()->where('item_code', $itemCode)->first();
            if (! $item) {
                $errors[] = ['row' => $rowNumber, 'reason' => "Item code \"{$itemCode}\" not found."];

                continue;
            }

            $rowHadError = false;
            foreach ($warehouseColumns as $col => $warehouseId) {
                $raw = trim((string) ($line[$col] ?? ''));
                $existingRow = $existing->get("{$item->id}:{$warehouseId}");

                if ($raw === '') {
                    if ($existingRow) {
                        $cells[] = ['item_id' => $item->id, 'warehouse_id' => $warehouseId, 'rate' => null];
                        $toDelete++;
                    } else {
                        $unchanged++;
                    }

                    continue;
                }

                if (! is_numeric($raw) || (float) $raw < 0) {
                    $errors[] = ['row' => $rowNumber, 'reason' => "Rate \"{$raw}\" for warehouse column is not a valid non-negative number."];
                    $rowHadError = true;

                    continue;
                }

                $rate = (float) $raw;
                if ($existingRow && (string) $existingRow->rate === (string) $rate) {
                    $unchanged++;

                    continue;
                }

                $cells[] = ['item_id' => $item->id, 'warehouse_id' => $warehouseId, 'rate' => $rate];
                $existingRow ? $toUpdate++ : $toCreate++;
            }

            if ($rowHadError) {
                continue;
            }

            $syncRaw = strtolower(trim((string) ($line[$syncCol] ?? '')));
            $syncValue = in_array($syncRaw, ['1', 'true', 'yes'], true);

            if ($syncValue !== (bool) $item->sync_to_main_wh) {
                $syncByValue[$syncValue ? 1 : 0][] = $item->id;
                $syncChanges++;
            }
        }

        return [
            'cells' => $cells,
            'syncByValue' => $syncByValue,
            'to_create' => $toCreate,
            'to_update' => $toUpdate,
            'to_delete' => $toDelete,
            'sync_changes' => $syncChanges,
            'unchanged' => $unchanged,
            'errors' => $errors,
        ];
    }
}
