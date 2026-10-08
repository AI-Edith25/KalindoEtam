<?php

namespace App\Support;

/**
 * Pure helper shared by AccountsPayableService and AccountsReceivableService
 * so the Unpaid/PartiallyPaid/Paid comparison exists in exactly one place,
 * even though the two sides use separate status enums (see docs/DECISIONS.md).
 */
class SettlementStatus
{
    /** A residual under Rp 1 is forgiven outright rather than left stranded as "partially paid"
        forever — business decision 2026-10-08: tax/discount rounding routinely leaves a few-cent
        gap a customer or supplier will never actually settle separately. Status-only: paid_amount
        itself is never bumped to close the gap (see AccountsReceivableService::settle()'s own
        docblock on why — that would corrupt a later reversal's math), so an outstanding_amount
        display can still show a few stray cents even once status reads "paid". */
    private const FORGIVABLE_RESIDUAL = 1.0;

    public static function resolve(float $amount, float $paidAmount): string
    {
        if ($paidAmount <= 0) {
            return 'unpaid';
        }

        if ($amount - $paidAmount < self::FORGIVABLE_RESIDUAL) {
            return 'paid';
        }

        return 'partially_paid';
    }
}
