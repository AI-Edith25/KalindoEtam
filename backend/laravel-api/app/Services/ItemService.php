<?php

namespace App\Services;

use App\Models\Item;
use App\Repositories\ItemRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ItemService
{
    public function __construct(
        protected ItemRepository $itemRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->itemRepository->paginate($perPage);
    }

    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
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
