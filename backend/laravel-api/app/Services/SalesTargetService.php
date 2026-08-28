<?php

namespace App\Services;

use App\Models\SalesTarget;
use App\Repositories\SalesTargetRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SalesTargetService
{
    public function __construct(
        protected SalesTargetRepository $salesTargetRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->salesTargetRepository->paginate($perPage);
    }

    public function create(array $data): SalesTarget
    {
        return DB::transaction(function () use ($data) {
            $salesTarget = $this->salesTargetRepository->create($data);
            $this->auditLogService->record(
                'created',
                'sales_target',
                "Created sales target for period {$salesTarget->period_month}/{$salesTarget->period_year}."
            );

            return $salesTarget;
        });
    }

    public function update(SalesTarget $salesTarget, array $data): SalesTarget
    {
        return DB::transaction(function () use ($salesTarget, $data) {
            $salesTarget = $this->salesTargetRepository->update($salesTarget, $data);
            $this->auditLogService->record(
                'updated',
                'sales_target',
                "Updated sales target for period {$salesTarget->period_month}/{$salesTarget->period_year}."
            );

            return $salesTarget;
        });
    }

    public function delete(SalesTarget $salesTarget): void
    {
        DB::transaction(function () use ($salesTarget) {
            $period = "{$salesTarget->period_month}/{$salesTarget->period_year}";
            $this->salesTargetRepository->delete($salesTarget);
            $this->auditLogService->record('deleted', 'sales_target', "Deleted sales target for period {$period}.");
        });
    }
}
