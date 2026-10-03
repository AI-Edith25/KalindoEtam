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
    public function list(int $perPage = 100, array $filters = []): LengthAwarePaginator
    {
        return $this->chartOfAccountRepository->paginate($perPage, $filters);
    }

    /**
     * `code` has a real DB-level unique index (not just app validation) that, same as any plain
     * unique column on a SoftDeletes table, does not know about `deleted_at` — a trashed row still
     * occupies its code forever as far as the database is concerned, even though the list/search UI
     * already hides it (global SoftDeletingScope). Store/UpdateChartOfAccountRequest's own
     * whereNull('deleted_at') makes the *validation* accept a code that only collides with a
     * trashed row, but a plain INSERT would then still hit the raw SQL unique constraint — so
     * when that's the case, restore and overwrite the trashed row instead of inserting a new one.
     * Same outcome either way from the user's perspective (the code becomes usable again); this
     * additionally keeps the account's original id/audit history intact rather than orphaning it
     * forever as an unreachable trashed duplicate.
     */
    public function create(array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($data) {
            $trashed = ChartOfAccount::onlyTrashed()->where('code', $data['code'])->first();

            if ($trashed) {
                $trashed->restore();
                $chartOfAccount = $this->chartOfAccountRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'chart_of_account', "Created chart of account \"{$chartOfAccount->code} {$chartOfAccount->name}\" (restored from an archived account with the same code).");

                return $chartOfAccount;
            }

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
