<?php

namespace App\Services;

use App\Models\ItemGroup;
use App\Repositories\ItemGroupRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ItemGroupService
{
    public function __construct(
        protected ItemGroupRepository $itemGroupRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->itemGroupRepository->paginate($perPage);
    }

    /** `name` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): ItemGroup
    {
        return DB::transaction(function () use ($data) {
            $trashed = ItemGroup::onlyTrashed()->where('name', $data['name'])->first();

            if ($trashed) {
                $trashed->restore();
                $itemGroup = $this->itemGroupRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'item_group', "Created item group \"{$itemGroup->name}\" (restored from an archived item group with the same name).");

                return $itemGroup;
            }

            $itemGroup = $this->itemGroupRepository->create($data);
            $this->auditLogService->record('created', 'item_group', "Created item group \"{$itemGroup->name}\".");

            return $itemGroup;
        });
    }

    public function update(ItemGroup $itemGroup, array $data): ItemGroup
    {
        return DB::transaction(function () use ($itemGroup, $data) {
            $itemGroup = $this->itemGroupRepository->update($itemGroup, $data);
            $this->auditLogService->record('updated', 'item_group', "Updated item group \"{$itemGroup->name}\".");

            return $itemGroup;
        });
    }

    public function delete(ItemGroup $itemGroup): void
    {
        DB::transaction(function () use ($itemGroup) {
            $name = $itemGroup->name;
            $this->itemGroupRepository->delete($itemGroup);
            $this->auditLogService->record('deleted', 'item_group', "Deleted item group \"{$name}\".");
        });
    }
}
