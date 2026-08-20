<?php

namespace App\Enums;

/**
 * A Tax record applies to exactly one side of a transaction — a shared
 * rate (e.g. PPN 11%) needing both sides is two separate Tax rows, not
 * one "both" row, since Inclusive/Exclusive can differ by context even
 * when the rate doesn't.
 */
enum TaxTransactionType: string
{
    case PURCHASE = 'purchase';
    case SALES = 'sales';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE => 'Purchase',
            self::SALES => 'Sales',
        };
    }
}
