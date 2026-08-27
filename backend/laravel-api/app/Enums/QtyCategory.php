<?php

namespace App\Enums;

/**
 * Decides an Item's qty input type end to end (validation, storage rounding,
 * display) — the single source of truth for the integer-vs-decimal rule.
 */
enum QtyCategory: string
{
    case UNIT = 'unit';
    case WEIGHT = 'weight';

    public function decimalPlaces(): int
    {
        return match ($this) {
            self::UNIT => 0,
            self::WEIGHT => 2,
        };
    }
}
