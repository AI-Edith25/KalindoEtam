<?php

namespace Tests\Unit;

use App\Support\SettlementStatus;
use PHPUnit\Framework\TestCase;

/** See SettlementStatus's own FORGIVABLE_RESIDUAL docblock — business decision 2026-10-08. */
class SettlementStatusTest extends TestCase
{
    public function test_zero_paid_is_unpaid(): void
    {
        $this->assertSame('unpaid', SettlementStatus::resolve(100_000, 0));
    }

    public function test_exact_match_is_paid(): void
    {
        $this->assertSame('paid', SettlementStatus::resolve(100_000, 100_000));
    }

    public function test_overpaid_is_still_paid(): void
    {
        $this->assertSame('paid', SettlementStatus::resolve(100_000, 100_000.50));
    }

    public function test_a_real_remaining_balance_is_partially_paid(): void
    {
        $this->assertSame('partially_paid', SettlementStatus::resolve(100_000, 50_000));
    }

    public function test_a_residual_under_one_rupiah_is_forgiven_as_paid(): void
    {
        $this->assertSame('paid', SettlementStatus::resolve(100_000, 99_999.50));
        $this->assertSame('paid', SettlementStatus::resolve(100_000, 99_999.01));
    }

    public function test_a_residual_of_exactly_one_rupiah_or_more_is_not_forgiven(): void
    {
        $this->assertSame('partially_paid', SettlementStatus::resolve(100_000, 99_999.00));
        $this->assertSame('partially_paid', SettlementStatus::resolve(100_000, 99_998.99));
    }
}
