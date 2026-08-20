<?php

namespace App\Services;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxType;
use App\Exceptions\BusinessException;
use App\Models\Item;
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
            if ($tax->invoices()->exists() || $tax->purchaseOrders()->exists()
                || $tax->purchaseTaxItems()->exists() || $tax->salesTaxItems()->exists()
                || $tax->salesOrderItems()->exists() || $tax->invoiceItems()->exists()
                || $tax->purchaseOrderItems()->exists() || $tax->deliveryItems()->exists()) {
                throw new BusinessException('This Tax is used by existing documents or items and cannot be deleted. Deactivate it instead.');
            }

            $name = $tax->name;
            $this->taxRepository->delete($tax);
            $this->auditLogService->record('deleted', 'tax', "Deleted tax \"{$name}\".");
        });
    }

    /**
     * Tax Exclusive: tax_amount = base × rate / 100, total = base + tax_amount.
     * Tax Inclusive: base already contains tax — rate is backed out instead.
     * Which mode applies is read off the Tax record itself (`$tax->calculation_mode`),
     * not passed by the caller — every Tax carries its own Inclusive/Exclusive setting.
     * Zero-Rated, Exempt, and "no tax selected" (`$tax === null`) all resolve
     * identically to zero — callers never branch on tax type themselves.
     * See docs/TAX_ENGINE_DESIGN.md §4.
     *
     * @return array{tax_amount: float, base_amount: float, total: float}
     */
    public function calculate(float $baseAmount, ?Tax $tax): array
    {
        if (! $tax || $tax->type !== TaxType::VAT) {
            return ['tax_amount' => 0.0, 'base_amount' => $baseAmount, 'total' => $baseAmount];
        }

        $rate = (float) $tax->rate;

        if ($tax->calculation_mode === TaxCalculationMode::INCLUSIVE) {
            $net = $baseAmount / (1 + $rate / 100);
            $taxAmount = round($baseAmount - $net, 2);

            return ['tax_amount' => $taxAmount, 'base_amount' => round($net, 2), 'total' => $baseAmount];
        }

        $taxAmount = round($baseAmount * $rate / 100, 2);

        return ['tax_amount' => $taxAmount, 'base_amount' => $baseAmount, 'total' => round($baseAmount + $taxAmount, 2)];
    }

    /**
     * Resolve a single document line's tax. If the payload's `tax_id` key is
     * present at all (even as null/empty — the line's own Tax select was
     * explicitly cleared to "No tax"), that value is trusted as-is. Only
     * when the key is absent entirely does this default from the Item's own
     * `$itemTaxField` (`purchase_tax_id`/`sales_tax_id`) — the one-time
     * default a fresh line gets when an Item is first picked, matching the
     * same "explicit override wins, else Item default" rule everywhere this
     * is used. Reused identically by SalesOrderService, PurchaseOrderService,
     * and InvoiceService's Goods path so the rule can't drift between them.
     *
     * @param  array<string, mixed>  $lineData
     * @return array{0: ?string, 1: float} [taxId, taxAmount]
     */
    public function resolveLineTax(array $lineData, ?Item $item, string $itemTaxField, float $lineAmount): array
    {
        $taxId = array_key_exists('tax_id', $lineData)
            ? ($lineData['tax_id'] ?: null)
            : $item?->{$itemTaxField};

        if (! $taxId) {
            return [null, 0.0];
        }

        $tax = $this->taxRepository->findOrFail($taxId);

        return [$taxId, $this->calculate($lineAmount, $tax)['tax_amount']];
    }
}
