<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Exceptions\BusinessException;

/**
 * The single source of truth for per-line discount calculation — Sales Order, Delivery, and
 * Invoice (Goods/Direct Goods/Transportation) all call this instead of reimplementing the
 * percent-vs-amount math. Mirrors TaxService's own role exactly: a stateless calculator that
 * never persists anything and never posts a journal entry.
 */
class DiscountService
{
    /** @return array{discount_amount: float, net_amount: float} */
    public function calculate(float $grossAmount, DiscountType $type, float $value): array
    {
        if ($type === DiscountType::PERCENTAGE) {
            if ($value < 0 || $value > 100) {
                throw new BusinessException('Discount percentage must be between 0 and 100.');
            }

            $discountAmount = round($grossAmount * $value / 100, 2);

            return ['discount_amount' => $discountAmount, 'net_amount' => round($grossAmount - $discountAmount, 2)];
        }

        if ($value < 0) {
            throw new BusinessException('Discount amount cannot be negative.');
        }

        if ($value > $grossAmount) {
            throw new BusinessException('Discount amount cannot exceed the line amount.');
        }

        $discountAmount = round($value, 2);

        return ['discount_amount' => $discountAmount, 'net_amount' => round($grossAmount - $discountAmount, 2)];
    }

    /**
     * Resolve a single document line's discount from raw request data — same "key present wins,
     * absent means none" contract as TaxService::resolveLineTax(). A line that sends neither key
     * gets discount_type=amount, value=0 (no discount), matching every line's current implicit
     * behavior before this field existed.
     *
     * @param  array<string, mixed>  $lineData
     * @return array{0: DiscountType, 1: float, 2: float, 3: float} [type, value, discountAmount, netAmount]
     */
    public function resolveLineDiscount(array $lineData, float $grossAmount): array
    {
        $type = isset($lineData['discount_type']) ? DiscountType::from($lineData['discount_type']) : DiscountType::AMOUNT;
        $value = (float) ($lineData['discount_value'] ?? 0);

        $result = $this->calculate($grossAmount, $type, $value);

        return [$type, $value, $result['discount_amount'], $result['net_amount']];
    }
}
