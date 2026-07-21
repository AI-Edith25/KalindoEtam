<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Repositories\ChartOfAccountRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ChartOfAccountService
{
    public function __construct(
        protected ChartOfAccountRepository $chartOfAccountRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->chartOfAccountRepository->paginate($perPage);
    }

    public function create(array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($data) {
            $chartOfAccount = $this->chartOfAccountRepository->create($data);
            $this->auditLogService->record('created', 'chart_of_account', "Created chart of account \"{$chartOfAccount->code} {$chartOfAccount->name}\".");

            return $chartOfAccount;
        });
    }

    public function update(ChartOfAccount $chartOfAccount, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($chartOfAccount, $data) {
            $chartOfAccount = $this->chartOfAccountRepository->update($chartOfAccount, $data);
            $this->auditLogService->record('updated', 'chart_of_account', "Updated chart of account \"{$chartOfAccount->code} {$chartOfAccount->name}\".");

            return $chartOfAccount;
        });
    }

    public function delete(ChartOfAccount $chartOfAccount): void
    {
        DB::transaction(function () use ($chartOfAccount) {
            $label = "{$chartOfAccount->code} {$chartOfAccount->name}";
            $this->chartOfAccountRepository->delete($chartOfAccount);
            $this->auditLogService->record('deleted', 'chart_of_account', "Deleted chart of account \"{$label}\".");
        });
    }
}
