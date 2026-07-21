<?php

namespace App\Services;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxType;
use App\Exceptions\BusinessException;
use App\Models\Tax;
use App\Repositories\TaxRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The single source of truth for tax calculation — Sales (InvoiceService),
 * Purchase (PurchaseOrderService), and any future module all call
 * calculate() instead of reimplementing the rate math. See
 * docs/TAX_ENGINE_DESIGN.md §1/§4/§6.
 */
class TaxService
{
    public function __construct(
        protected TaxRepository $taxRepository,
        protected AuditLogService $auditLogService,
    ) {}

    public function list(int $perPage = 15): LengthAwarePaginator
    {
        return $this->taxRepository->paginate($perPage);
    }

    public function create(array $data): Tax
    {
        return DB::transaction(function () use ($data) {
            $tax = $this->taxRepository->create($data);
            $this->auditLogService->record('created', 'tax', "Created tax \"{$tax->name}\".");

            return $tax;
        });
    }

    public function update(Tax $tax, array $data): Tax
    {
        return DB::transaction(function () use ($tax, $data) {
            $tax = $this->taxRepository->update($tax, $data);
            $this->auditLogService->record('updated', 'tax', "Updated tax \"{$tax->name}\".");

            return $tax;
        });
    }

    /** Prefer deactivation (is_active = false) over deletion — this guard is the enforcement, not just a UI convention. See docs/TAX_ENGINE_DESIGN.md §9 (Tax Status). */
    public function delete(Tax $tax): void
    {
        DB::transaction(function () use ($tax) {
            if ($tax->invoices()->exists() || $tax->purchaseOrders()->exists()) {
                throw new BusinessException('This Tax is used by existing documents and cannot be deleted. Deactivate it instead.');
            }

            $name = $tax->name;
            $this->taxRepository->delete($tax);
            $this->auditLogService->record('deleted', 'tax', "Deleted tax \"{$name}\".");
        });
    }

    /**
     * Tax Exclusive: tax_amount = base × rate / 100, total = base + tax_amount.
     * Tax Inclusive: base already contains tax — rate is backed out instead.
     * Zero-Rated, Exempt, and "no tax selected" (`$tax === null`) all resolve
     * identically to zero — callers never branch on tax type themselves.
     * See docs/TAX_ENGINE_DESIGN.md §4.
     *
     * @return array{tax_amount: float, base_amount: float, total: float}
     */
    public function calculate(float $baseAmount, ?Tax $tax, TaxCalculationMode $mode = TaxCalculationMode::EXCLUSIVE): array
    {
        if (! $tax || $tax->type !== TaxType::VAT) {
            return ['tax_amount' => 0.0, 'base_amount' => $baseAmount, 'total' => $baseAmount];
        }

        $rate = (float) $tax->rate;

        if ($mode === TaxCalculationMode::INCLUSIVE) {
            $net = $baseAmount / (1 + $rate / 100);
            $taxAmount = round($baseAmount - $net, 2);

            return ['tax_amount' => $taxAmount, 'base_amount' => round($net, 2), 'total' => $baseAmount];
        }

        $taxAmount = round($baseAmount * $rate / 100, 2);

        return ['tax_amount' => $taxAmount, 'base_amount' => $baseAmount, 'total' => round($baseAmount + $taxAmount, 2)];
    }
}
