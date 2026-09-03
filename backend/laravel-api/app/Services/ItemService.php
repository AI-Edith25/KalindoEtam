<?php

namespace App\Services;

use App\Enums\QtyCategory;
use App\Enums\WarehouseType;
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
    ) {}

    public function list(
        int $perPage = 15,
        ?string $warehouseId = null,
        ?string $search = null,
        ?string $itemGroupId = null,
    ): LengthAwarePaginator {
        $mainWarehouseId = $warehouseId !== null
            ? Warehouse::query()->where('warehouse_type', WarehouseType::MAIN)->value('id')
            : null;

        $items = $this->itemRepository->paginate($perPage, $warehouseId, $mainWarehouseId, $search, $itemGroupId);
        $this->itemPriceResolver->apply($items, $warehouseId, $mainWarehouseId);

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
                ($value ? 'Enabled' : 'Disabled')." \"Sync to Main WH\" for ".count($items).' item(s).',
                ['item_ids' => $itemIds, 'value' => $value],
            );
        });
    }

    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            // The 'unit' DB default only applies once the row is re-read — an in-memory
            // model from a bare create() would otherwise see qty_category as null.
            $data['qty_category'] ??= QtyCategory::UNIT->value;

            $item = $this->itemRepository->create($data);
            $this->auditLogService->record('created', 'item', "Created item \"{$item->item_name}\".");

            return $item;
        });
    }

    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            $item = $this->itemRepository->update($item, $data);
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
}
