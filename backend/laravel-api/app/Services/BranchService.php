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

    public function create(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
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
