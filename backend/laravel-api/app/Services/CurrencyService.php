<?php

namespace App\Services;

use App\Models\Currency;
use App\Repositories\CurrencyRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CurrencyService
{
    public function __construct(
        protected CurrencyRepository $currencyRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->currencyRepository->paginate($perPage);
    }

    /** `code` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): Currency
    {
        return DB::transaction(function () use ($data) {
            $trashed = Currency::onlyTrashed()->where('code', $data['code'])->first();

            if ($trashed) {
                $trashed->restore();
                $currency = $this->currencyRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'currency', "Created currency \"{$currency->name}\" (restored from an archived currency with the same code).");

                return $currency;
            }

            $currency = $this->currencyRepository->create($data);
            $this->auditLogService->record('created', 'currency', "Created currency \"{$currency->name}\".");

            return $currency;
        });
    }

    public function update(Currency $currency, array $data): Currency
    {
        return DB::transaction(function () use ($currency, $data) {
            $currency = $this->currencyRepository->update($currency, $data);
            $this->auditLogService->record('updated', 'currency', "Updated currency \"{$currency->name}\".");

            return $currency;
        });
    }

    public function delete(Currency $currency): void
    {
        DB::transaction(function () use ($currency) {
            $name = $currency->name;
            $this->currencyRepository->delete($currency);
            $this->auditLogService->record('deleted', 'currency', "Deleted currency \"{$name}\".");
        });
    }
}
