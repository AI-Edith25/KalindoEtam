<?php

namespace Tests\Unit\BankStatement;

use App\Services\BankStatement\BankStatementMatcher;
use PHPUnit\Framework\TestCase;

class BankStatementMatcherTest extends TestCase
{
    private function jlRow(float $debit, float $kredit, string $doc = 'PV/KE/00001'): array
    {
        return ['document_number' => $doc, 'debit' => $debit, 'kredit' => $kredit];
    }

    private function mutasiRow(float $debit, float $kredit, string $description = 'transfer'): array
    {
        return ['id' => uniqid('', true), 'description' => $description, 'debit' => $debit, 'kredit' => $kredit];
    }

    public function test_merge_admin_fees_combines_fee_line_into_preceding_same_direction_transfer(): void
    {
        $rows = [
            $this->mutasiRow(100000000, 0, 'TRANSFER KELUAR'),
            $this->mutasiRow(2500, 0, 'BIAYA TXN'),
        ];

        $merged = (new BankStatementMatcher())->mergeAdminFees($rows);

        $this->assertCount(1, $merged);
        $this->assertSame(100002500.0, $merged[0]['debit']);
    }

    public function test_merge_admin_fees_leaves_unrelated_lines_untouched(): void
    {
        $rows = [
            $this->mutasiRow(0, 5000000, 'transfer masuk 1'),
            $this->mutasiRow(0, 3000000, 'transfer masuk 2'),
        ];

        $merged = (new BankStatementMatcher())->mergeAdminFees($rows);

        $this->assertCount(2, $merged);
        $this->assertSame(5000000.0, $merged[0]['kredit']);
        $this->assertSame(3000000.0, $merged[1]['kredit']);
    }

    public function test_merge_admin_fees_does_not_merge_across_opposite_directions(): void
    {
        $rows = [
            $this->mutasiRow(0, 5000000, 'transfer masuk'),
            $this->mutasiRow(2500, 0, 'BIAYA TXN'),
        ];

        $merged = (new BankStatementMatcher())->mergeAdminFees($rows);

        $this->assertCount(2, $merged, 'a fee line cannot merge into a preceding line on the opposite side');
    }

    public function test_match_pairs_exact_amount_same_direction(): void
    {
        $jl = [$this->jlRow(0, 210000, 'OR/KE/00001')];
        $mutasi = [$this->mutasiRow(0, 210000)];

        $result = (new BankStatementMatcher())->match($jl, $mutasi);

        $this->assertCount(1, $result);
        $this->assertSame('cocok', $result[0]['status']);
        $this->assertSame(0.0, $result[0]['selisih']);
        $this->assertNotNull($result[0]['jl']);
        $this->assertNotNull($result[0]['mutasi']);
    }

    public function test_match_tolerates_up_to_rp2000_difference(): void
    {
        $jl = [$this->jlRow(0, 8000001.30, 'OR/KE/00002')];
        $mutasi = [$this->mutasiRow(0, 8000000)];

        $result = (new BankStatementMatcher())->match($jl, $mutasi);

        $this->assertCount(1, $result);
        $this->assertSame('cocok', $result[0]['status']);
        $this->assertEqualsWithDelta(1.30, $result[0]['selisih'], 0.001);
    }

    public function test_match_rejects_difference_over_rp2000(): void
    {
        $jl = [$this->jlRow(200000, 0, 'PV/KE/00001')];
        $mutasi = [$this->mutasiRow(197000, 0)];

        $result = (new BankStatementMatcher())->match($jl, $mutasi);

        $this->assertCount(2, $result);
        $this->assertSame('tidak_cocok', $result[0]['status']);
        $this->assertSame('tidak_cocok', $result[1]['status']);
    }

    public function test_match_does_not_pair_across_opposite_directions(): void
    {
        $jl = [$this->jlRow(500000, 0, 'PV/KE/00001')];
        $mutasi = [$this->mutasiRow(0, 500000)];

        $result = (new BankStatementMatcher())->match($jl, $mutasi);

        $this->assertCount(2, $result);
        $this->assertTrue(collect($result)->every(fn ($row) => $row['status'] === 'tidak_cocok'));
    }

    public function test_match_unpaired_jl_row_has_null_mutasi_side(): void
    {
        $jl = [$this->jlRow(0, 300000, 'OR/KE/00003')];

        $result = (new BankStatementMatcher())->match($jl, []);

        $this->assertCount(1, $result);
        $this->assertSame('tidak_cocok', $result[0]['status']);
        $this->assertNotNull($result[0]['jl']);
        $this->assertNull($result[0]['mutasi']);
    }

    public function test_match_unpaired_mutasi_row_has_null_jl_side(): void
    {
        $mutasi = [$this->mutasiRow(0, 300000)];

        $result = (new BankStatementMatcher())->match([], $mutasi);

        $this->assertCount(1, $result);
        $this->assertSame('tidak_cocok', $result[0]['status']);
        $this->assertNull($result[0]['jl']);
        $this->assertNotNull($result[0]['mutasi']);
    }

    public function test_match_picks_closest_amount_first_among_duplicate_candidates(): void
    {
        // Two JL rows at 5.800.000; three mutasi candidates, only two should pair 1:1.
        $jl = [$this->jlRow(0, 5800000, 'OR/A'), $this->jlRow(0, 5800000, 'OR/B')];
        $mutasi = [$this->mutasiRow(0, 5801500), $this->mutasiRow(0, 5800000), $this->mutasiRow(0, 5799000)];

        $result = (new BankStatementMatcher())->match($jl, $mutasi);

        $matched = collect($result)->where('status', 'cocok');
        $unmatched = collect($result)->where('status', 'tidak_cocok');
        $this->assertCount(2, $matched);
        $this->assertCount(1, $unmatched);
    }
}
