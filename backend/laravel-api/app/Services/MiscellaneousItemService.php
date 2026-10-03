<?php

namespace App\Services;

use App\Models\MiscellaneousItem;
use App\Repositories\MiscellaneousItemRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MiscellaneousItemService
{
    public function __construct(
        protected MiscellaneousItemRepository $miscellaneousItemRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        return $this->miscellaneousItemRepository->paginate($perPage, $search);
    }

    /** `misc_code` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): MiscellaneousItem
    {
        return DB::transaction(function () use ($data) {
            $trashed = MiscellaneousItem::onlyTrashed()->where('misc_code', $data['misc_code'])->first();

            if ($trashed) {
                $trashed->restore();
                $miscellaneousItem = $this->miscellaneousItemRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'miscellaneous_item', "Created miscellaneous item \"{$miscellaneousItem->misc_code}\" (restored from an archived miscellaneous item with the same code).");

                return $miscellaneousItem;
            }

            $miscellaneousItem = $this->miscellaneousItemRepository->create($data);
            $this->auditLogService->record('created', 'miscellaneous_item', "Created miscellaneous item \"{$miscellaneousItem->misc_code}\".");

            return $miscellaneousItem;
        });
    }

    public function update(MiscellaneousItem $miscellaneousItem, array $data): MiscellaneousItem
    {
        return DB::transaction(function () use ($miscellaneousItem, $data) {
            $miscellaneousItem = $this->miscellaneousItemRepository->update($miscellaneousItem, $data);
            $this->auditLogService->record('updated', 'miscellaneous_item', "Updated miscellaneous item \"{$miscellaneousItem->misc_code}\".");

            return $miscellaneousItem;
        });
    }

    public function delete(MiscellaneousItem $miscellaneousItem): void
    {
        DB::transaction(function () use ($miscellaneousItem) {
            $code = $miscellaneousItem->misc_code;
            $this->miscellaneousItemRepository->delete($miscellaneousItem);
            $this->auditLogService->record('deleted', 'miscellaneous_item', "Deleted miscellaneous item \"{$code}\".");
        });
    }
}
