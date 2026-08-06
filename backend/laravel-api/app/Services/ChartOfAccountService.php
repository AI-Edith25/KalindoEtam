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

    /**
     * Default raised from the app-wide 15 — Chart of Accounts is a small,
     * bounded reference list (same "dozens at most" reasoning
     * ChartOfAccountRepository::allOrderedByCode() already documents for
     * this exact table), and fetchLookupList() (shared/services/lookupApi.ts)
     * only ever reads page 1 — a real account past position 15 (e.g. this
     * sprint's new Expense categories) would otherwise silently disappear
     * from every dropdown built on this endpoint.
     */
    public function list(int $perPage = 100): LengthAwarePaginator
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
