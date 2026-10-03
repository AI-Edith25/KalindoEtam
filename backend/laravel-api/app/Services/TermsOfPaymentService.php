<?php

namespace App\Services;

use App\Models\TermsOfPayment;
use App\Repositories\TermsOfPaymentRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TermsOfPaymentService
{
    public function __construct(
        protected TermsOfPaymentRepository $termsOfPaymentRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->termsOfPaymentRepository->paginate($perPage);
    }

    /** `code` has a real DB-level unique index unaware of `deleted_at` — see ChartOfAccountService::create()'s own comment for why a trashed match is restored instead of inserted fresh. */
    public function create(array $data): TermsOfPayment
    {
        return DB::transaction(function () use ($data) {
            $trashed = TermsOfPayment::onlyTrashed()->where('code', $data['code'])->first();

            if ($trashed) {
                $trashed->restore();
                $termsOfPayment = $this->termsOfPaymentRepository->update($trashed, $data);
                $this->auditLogService->record('created', 'terms_of_payment', "Created terms of payment \"{$termsOfPayment->name}\" (restored from an archived terms of payment with the same code).");

                return $termsOfPayment;
            }

            $termsOfPayment = $this->termsOfPaymentRepository->create($data);
            $this->auditLogService->record('created', 'terms_of_payment', "Created terms of payment \"{$termsOfPayment->name}\".");

            return $termsOfPayment;
        });
    }

    public function update(TermsOfPayment $termsOfPayment, array $data): TermsOfPayment
    {
        return DB::transaction(function () use ($termsOfPayment, $data) {
            $termsOfPayment = $this->termsOfPaymentRepository->update($termsOfPayment, $data);
            $this->auditLogService->record('updated', 'terms_of_payment', "Updated terms of payment \"{$termsOfPayment->name}\".");

            return $termsOfPayment;
        });
    }

    public function delete(TermsOfPayment $termsOfPayment): void
    {
        DB::transaction(function () use ($termsOfPayment) {
            $name = $termsOfPayment->name;
            $this->termsOfPaymentRepository->delete($termsOfPayment);
            $this->auditLogService->record('deleted', 'terms_of_payment', "Deleted terms of payment \"{$name}\".");
        });
    }
}
