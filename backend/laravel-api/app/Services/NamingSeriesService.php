<?php

namespace App\Services;

use App\Models\NamingSeries;
use App\Repositories\NamingSeriesRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class NamingSeriesService
{
    public function __construct(
        protected NamingSeriesRepository $namingSeriesRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->namingSeriesRepository->paginate($perPage);
    }

    public function create(array $data): NamingSeries
    {
        return DB::transaction(function () use ($data) {
            $namingSeries = $this->namingSeriesRepository->create($data);
            $this->auditLogService->record('created', 'naming_series', "Created naming series \"{$namingSeries->module}/{$namingSeries->document_type}\".");

            return $namingSeries;
        });
    }

    public function update(NamingSeries $namingSeries, array $data): NamingSeries
    {
        return DB::transaction(function () use ($namingSeries, $data) {
            $namingSeries = $this->namingSeriesRepository->update($namingSeries, $data);
            $this->auditLogService->record('updated', 'naming_series', "Updated naming series \"{$namingSeries->module}/{$namingSeries->document_type}\".");

            return $namingSeries;
        });
    }

    public function delete(NamingSeries $namingSeries): void
    {
        DB::transaction(function () use ($namingSeries) {
            $label = "{$namingSeries->module}/{$namingSeries->document_type}";
            $this->namingSeriesRepository->delete($namingSeries);
            $this->auditLogService->record('deleted', 'naming_series', "Deleted naming series \"{$label}\".");
        });
    }
}
