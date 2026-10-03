<?php

namespace App\Services;

use App\Models\Branch;
use App\Repositories\BranchRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BranchService
{
    public function __construct(
        protected BranchRepository $branchRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->branchRepository->paginate($perPage);
    }

    /** `code` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $trashed = Branch::onlyTrashed()->where('code', $data['code'])->first();

            if ($trashed) {
                $trashed->restore();
                $branch = $this->branchRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'branch', "Created branch \"{$branch->name}\" (restored from an archived branch with the same code).");

                return $branch;
            }

            $branch = $this->branchRepository->create($data);
            $this->auditLogService->record('created', 'branch', "Created branch \"{$branch->name}\".");

            return $branch;
        });
    }

    public function update(Branch $branch, array $data): Branch
    {
        return DB::transaction(function () use ($branch, $data) {
            $branch = $this->branchRepository->update($branch, $data);
            $this->auditLogService->record('updated', 'branch', "Updated branch \"{$branch->name}\".");

            return $branch;
        });
    }

    public function delete(Branch $branch): void
    {
        DB::transaction(function () use ($branch) {
            $name = $branch->name;
            $this->branchRepository->delete($branch);
            $this->auditLogService->record('deleted', 'branch', "Deleted branch \"{$name}\".");
        });
    }
}
