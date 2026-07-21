<?php

namespace App\Support;

/**
 * Pure helper shared by AccountsPayableService and AccountsReceivableService
 * so the Unpaid/PartiallyPaid/Paid comparison exists in exactly one place,
 * even though the two sides use separate status enums (see docs/DECISIONS.md).
 */
class SettlementStatus
{
    public static function resolve(float $amount, float $paidAmount): string
    {
        if ($paidAmount <= 0) {
            return 'unpaid';
        }

        if ($paidAmount >= $amount) {
            return 'paid';
        }

        return 'partially_paid';
    }
}
