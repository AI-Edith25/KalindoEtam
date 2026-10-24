<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
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

    /**
     * UpdateChartOfAccountRequest's unique rule excludes trashed rows, so a code can be
     * validated as "free" while a trashed row still physically occupies that string in
     * the raw DB unique index. Unlike create()'s restore-the-trashed-row handling, there's
     * no single row to restore into here — the live row being updated is a different
     * physical row — so the trashed one is force-deleted instead, which is safe: it's
     * already excluded from the UI/list (SoftDeletingScope) and its own code is about to
     * be freed up anyway. Still guarded by assertNeverUsed() — a trashed row soft-deleted
     * before 2026-10-24's hard-delete-by-default change can carry real (possibly
     * voided-only) Journal Entry Lines, and forceDelete() would hit the exact FK violation
     * delete() below is guarding against.
     */
    public function update(ChartOfAccount $chartOfAccount, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($chartOfAccount, $data) {
            if (isset($data['code'])) {
                ChartOfAccount::onlyTrashed()
                    ->where('code', $data['code'])
                    ->where('id', '!=', $chartOfAccount->id)
                    ->get()
                    ->each(function (ChartOfAccount $trashed) {
                        $this->assertNeverUsed($trashed);
                        $trashed->forceDelete();
                    });
            }

            $chartOfAccount = $this->chartOfAccountRepository->update($chartOfAccount, $data);
            $this->auditLogService->record('updated', 'chart_of_account', "Updated chart of account \"{$chartOfAccount->code} {$chartOfAccount->name}\".");

            return $chartOfAccount;
        });
    }

    /**
     * Hard delete, not soft — a deleted account is gone for good, no restore-by-code-reuse
     * path (see create()'s trashed-row branch, which now only ever fires for rows soft-
     * deleted before this change). withTrashed() on the usage check is deliberate: a plain
     * `journalEntryLines()->exists()` only sees non-deleted JournalEntryLine rows, but a
     * soft-deleted/voided line still physically exists and still holds the
     * journal_entry_lines_chart_of_account_id_foreign FK — forceDelete() would hit that FK
     * violation as a raw SQL error with no indication of why. Checking withTrashed() first
     * turns that into a clear, actionable message instead.
     */
    public function delete(ChartOfAccount $chartOfAccount): void
    {
        DB::transaction(function () use ($chartOfAccount) {
            $label = "{$chartOfAccount->code} {$chartOfAccount->name}";
            $this->assertNeverUsed($chartOfAccount);

            $chartOfAccount->forceDelete();
            $this->auditLogService->record('deleted', 'chart_of_account', "Deleted chart of account \"{$label}\".");
        });
    }

    /**
     * withTrashed() is deliberate: a plain journalEntryLines()->exists() only sees
     * non-deleted JournalEntryLine rows, but a soft-deleted/voided line still physically
     * exists and still holds the journal_entry_lines_chart_of_account_id_foreign FK —
     * forceDelete() would hit that as a raw SQL error with no indication of why. Checking
     * this first turns it into a clear, actionable message instead.
     */
    private function assertNeverUsed(ChartOfAccount $chartOfAccount): void
    {
        if (JournalEntryLine::withTrashed()->where('chart_of_account_id', $chartOfAccount->id)->exists()) {
            throw new BusinessException("Akun \"{$chartOfAccount->code} {$chartOfAccount->name}\" sudah pernah digunakan dalam Journal Entry dan tidak bisa dihapus. Nonaktifkan akun ini jika tidak ingin digunakan lagi.");
        }
    }
}
