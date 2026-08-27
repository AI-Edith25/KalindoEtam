<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Item;

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

    protected function isWholeNumber(int|float|string $qty): bool
    {
        return abs((float) $qty - round((float) $qty)) < 1e-6;
    }
}
