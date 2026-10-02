<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Exceptions\BusinessException;
use App\Services\DiscountService;
use Tests\TestCase;

class DiscountCalculationTest extends TestCase
{
    protected DiscountService $discountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discountService = app(DiscountService::class);
    }

    public function test_percentage_discount_computes_amount_and_net(): void
    {
        $result = $this->discountService->calculate(10_000_000, DiscountType::PERCENTAGE, 10);

        $this->assertEquals(1_000_000, $result['discount_amount']);
        $this->assertEquals(9_000_000, $result['net_amount']);
    }

    public function test_nominal_discount_computes_net(): void
    {
        $result = $this->discountService->calculate(10_000_000, DiscountType::AMOUNT, 1_500_000);

        $this->assertEquals(1_500_000, $result['discount_amount']);
        $this->assertEquals(8_500_000, $result['net_amount']);
    }

    public function test_zero_discount_is_a_no_op(): void
    {
        $result = $this->discountService->calculate(500_000, DiscountType::AMOUNT, 0);

        $this->assertEquals(0, $result['discount_amount']);
        $this->assertEquals(500_000, $result['net_amount']);
    }

    public function test_hundred_percent_discount_zeroes_the_net_amount(): void
    {
        $result = $this->discountService->calculate(750_000, DiscountType::PERCENTAGE, 100);

        $this->assertEquals(750_000, $result['discount_amount']);
        $this->assertEquals(0, $result['net_amount']);
    }

    public function test_percentage_over_100_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->discountService->calculate(100_000, DiscountType::PERCENTAGE, 101);
    }

    public function test_nominal_discount_exceeding_gross_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->discountService->calculate(100_000, DiscountType::AMOUNT, 100_001);
    }

    public function test_negative_nominal_discount_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->discountService->calculate(100_000, DiscountType::AMOUNT, -1);
    }

    public function test_rounds_to_two_decimals_on_a_repeating_percentage(): void
    {
        // 1.666.657 * 10% = 166.665,7 -> rounds to 166.665,70.
        $result = $this->discountService->calculate(1_666_657, DiscountType::PERCENTAGE, 10);

        $this->assertEquals(166665.70, $result['discount_amount']);
        $this->assertEquals(1499991.30, $result['net_amount']);
    }

    /** The mandatory reference example: Gross 10jt, diskon 10%, PPN 11% -> Net 9jt, PPN 990rb, Grand Total 9.99jt. */
    public function test_reference_example_discount_then_tax_on_net(): void
    {
        $discount = $this->discountService->calculate(10_000_000, DiscountType::PERCENTAGE, 10);
        $this->assertEquals(9_000_000, $discount['net_amount']);

        $tax = round($discount['net_amount'] * 0.11, 2);
        $this->assertEquals(990_000, $tax);

        $grandTotal = $discount['net_amount'] + $tax;
        $this->assertEquals(9_990_000, $grandTotal);
    }
}
