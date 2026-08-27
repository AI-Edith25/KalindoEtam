<?php

namespace App\Services;

use App\Enums\QtyCategory;
use App\Exceptions\BusinessException;
use App\Exceptions\OverReceiptConfirmationRequiredException;
use App\Models\Item;
use App\Repositories\PurchaseSettingRepository;

/**
 * Single source of truth for enforcing Item.qty_category's integer-vs-decimal
 * rule on a qty value — shared by every module that accepts a user-entered
 * qty against an Item (Goods Receipt, Purchase Order, Purchase Return, Stock
 * Adjustment, Stock Transfer). Business-rule validation, so it lives in the
 * service layer like assertWithinOutstanding()/assertSufficientStock(), not
 * in a FormRequest (which never has the Item loaded).
 */
class QtyCategoryValidator
{
    public function __construct(
        protected PurchaseSettingRepository $purchaseSettingRepository,
    ) {}

    public function assertValid(Item $item, int|float|string $qty): void
    {
        if ($item->qty_category->decimalPlaces() === 0 && ! $this->isWholeNumber($qty)) {
            throw new BusinessException(
                "Item ini dihitung per satuan ({$item->uom->name}). Masukkan bilangan bulat.",
            );
        }
    }

    /** Rounds to the item's qty_category decimal places — stray precision (e.g. float drift) never reaches storage. */
    public function round(Item $item, int|float|string $qty): float
    {
        return round((float) $qty, $item->qty_category->decimalPlaces());
    }

    /**
     * Weight-category items are never hard-blocked for exceeding the outstanding
     * PO qty — truck-scale results legitimately vary from the ordered qty. Above
     * the configured tolerance (Administration > Purchase Settings), the caller
     * must confirm once (confirm_over_receipt) before this passes; at/below
     * tolerance, or once confirmed, it's a no-op — the caller shows a warning,
     * never a block. A null/zero tolerance setting means no limit at all.
     */
    public function assertWeightOverReceiptAllowed(Item $item, float $outstanding, float $qty, bool $confirmOverReceipt): void
    {
        $excess = $qty - $outstanding;
        if ($excess <= 0) {
            return;
        }

        $tolerancePercent = (float) ($this->purchaseSettingRepository->current()->weight_over_receipt_tolerance_percent ?? 0);
        if ($tolerancePercent <= 0) {
            return;
        }

        $allowedExcess = $outstanding * $tolerancePercent / 100;
        if ($excess > $allowedExcess && ! $confirmOverReceipt) {
            $excessPercent = $outstanding > 0 ? round($excess / $outstanding * 100, 1) : 100;

            throw new OverReceiptConfirmationRequiredException(
                "Qty untuk item {$item->item_code} melebihi sisa PO sebesar {$excessPercent}% — lanjutkan?",
            );
        }
    }

    protected function isWholeNumber(int|float|string $qty): bool
    {
        return abs((float) $qty - round((float) $qty)) < 1e-6;
    }
}
