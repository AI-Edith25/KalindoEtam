<?php

namespace App\Services;

use App\Enums\QtyCategory;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\Item;
use App\Models\Warehouse;
use App\Repositories\ItemRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ItemService
{
    public function __construct(
        protected ItemRepository $itemRepository,
        protected AuditLogService $auditLogService,
        protected ItemPriceResolver $itemPriceResolver,
        protected ItemStockResolver $itemStockResolver,
    ) {}

    public function list(
        int $perPage = 15,
        ?string $warehouseId = null,
        ?string $search = null,
        ?string $itemGroupId = null,
        ?array $itemIds = null,
    ): LengthAwarePaginator {
        $mainWarehouseId = $warehouseId !== null
            ? Warehouse::query()->where('warehouse_type', WarehouseType::MAIN)->value('id')
            : null;

        $items = $this->itemRepository->paginate($perPage, $warehouseId, $mainWarehouseId, $search, $itemGroupId, $itemIds);
        $this->itemPriceResolver->apply($items, $warehouseId, $mainWarehouseId);
        $this->itemStockResolver->apply($items, $warehouseId);

        return $items;
    }

    /**
     * One path for both the header "select all" and a single-row toggle. Loops model saves
     * (not a raw query-builder update) so AuditableObserver still stamps updated_by per row.
     *
     * @param  string[]  $itemIds
     */
    public function bulkSetSyncToMainWh(array $itemIds, bool $value): void
    {
        DB::transaction(function () use ($itemIds, $value) {
            $items = Item::query()->whereIn('id', $itemIds)->get();

            foreach ($items as $item) {
                $this->itemRepository->update($item, ['sync_to_main_wh' => $value]);
            }

            $this->auditLogService->record(
                'sync_to_main_wh_changed',
                'item',
                ($value ? 'Enabled' : 'Disabled').' "Sync to Main WH" for '.count($items).' item(s).',
                ['item_ids' => $itemIds, 'value' => $value],
            );
        });
    }

    /** `item_code` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            // The 'unit' DB default only applies once the row is re-read — an in-memory
            // model from a bare create() would otherwise see qty_category as null.
            $data['qty_category'] ??= QtyCategory::UNIT->value;

            $uoms = $this->pullUoms($data);
            $trashed = Item::onlyTrashed()->where('item_code', $data['item_code'])->first();

            if ($trashed) {
                $trashed->restore();
                $item = $this->itemRepository->update($trashed, $data);
                if ($uoms !== null) {
                    $this->syncUoms($item, $uoms);
                }
                $this->auditLogService->record('created', 'item', "Created item \"{$item->item_name}\" (restored from an archived item with the same code).");

                return $item;
            }

            $item = $this->itemRepository->create($data);
            if ($uoms !== null) {
                $this->syncUoms($item, $uoms);
            }
            $this->auditLogService->record('created', 'item', "Created item \"{$item->item_name}\".");

            return $item;
        });
    }

    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            $uoms = $this->pullUoms($data);

            // Extras are factors relative to the *current* base — moving the base under them
            // would silently re-scale what they mean. Remove the extras first, then re-add.
            $baseChanging = isset($data['uom_id']) && $data['uom_id'] !== $item->uom_id;
            $keepsExtras = $uoms === null ? $item->itemUoms()->exists() : $uoms !== [];
            if ($baseChanging && $keepsExtras) {
                throw new BusinessException('Cannot change the base UOM while the item has extra UOMs. Remove the extra UOMs first.');
            }

            $item = $this->itemRepository->update($item, $data);
            if ($uoms !== null) {
                $this->syncUoms($item, $uoms);
            }
            $this->auditLogService->record('updated', 'item', "Updated item \"{$item->item_name}\".");

            return $item;
        });
    }

    public function delete(Item $item): void
    {
        DB::transaction(function () use ($item) {
            $name = $item->item_name;
            $this->itemRepository->delete($item);
            $this->auditLogService->record('deleted', 'item', "Deleted item \"{$name}\".");
        });
    }

    /** Splits the `uoms` key off the item attributes: null = the caller didn't send it (leave as is). */
    protected function pullUoms(array &$data): ?array
    {
        if (! array_key_exists('uoms', $data)) {
            return null;
        }

        $uoms = $data['uoms'] ?? [];
        unset($data['uoms']);

        return $uoms;
    }

    /** Replaces the item's extra UOMs with $uoms (same delete-and-recreate as PO replaceItems). */
    protected function syncUoms(Item $item, array $uoms): void
    {
        foreach ($uoms as $row) {
            if ($row['uom_id'] === $item->uom_id) {
                throw new BusinessException("An extra UOM cannot be the same as the item's base UOM.");
            }
        }

        // Per-model deletes/creates (not raw queries) so AuditableObserver still stamps the rows.
        $item->itemUoms()->get()->each->delete();

        foreach ($uoms as $row) {
            $item->itemUoms()->create(['uom_id' => $row['uom_id'], 'conversion_factor' => $row['conversion_factor']]);
        }

        $item->unsetRelation('itemUoms');
    }
}
