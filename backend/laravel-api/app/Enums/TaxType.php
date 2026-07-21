<?php

namespace App\Enums;

/**
 * Distinguishes calculation behavior from rate — a Zero-Rated or Exempt
 * Tax both calculate to Rp 0, but are legally distinct VAT categories
 * (Zero-Rated still counts toward taxable turnover; Exempt doesn't).
 * Withholding tax deliberately excluded — a payer-withholds-remits flow,
 * not a rate-on-amount calculation. See docs/TAX_ENGINE_DESIGN.md §2/§3.
 */
enum TaxType: string
{
    case VAT = 'vat';
    case ZERO_RATED = 'zero_rated';
    case EXEMPT = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::VAT => 'VAT',
            self::ZERO_RATED => 'Zero Rated',
            self::EXEMPT => 'Tax Exempt',
        };
    }
}
