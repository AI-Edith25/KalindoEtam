<?php

namespace App\Enums;

/** Fixed 4-value set, never edited at runtime — a lookup table would be pure overhead. */
enum MiscellaneousChargeType: string
{
    case ADDITION = 'addition';
    case DEDUCTION = 'deduction';
    case ADDITION_PERCENT = 'addition_percent';
    case DEDUCTION_PERCENT = 'deduction_percent';

    public function label(): string
    {
        return match ($this) {
            self::ADDITION => 'Addition (+)',
            self::DEDUCTION => 'Deduction (-)',
            self::ADDITION_PERCENT => 'Addition (+) (By %)',
            self::DEDUCTION_PERCENT => 'Deduction (-) (By %)',
        };
    }
}
