<?php

namespace Tests\Unit\BankStatement;

use App\Services\BankStatement\BcaStatementParser;
use PHPUnit\Framework\TestCase;

class BcaStatementParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/BankStatements/bca_sample.csv');
    }

    public function test_detects_header(): void
    {
        $this->assertTrue((new BcaStatementParser())->detect($this->fixture()));
        $this->assertFalse((new BcaStatementParser())->detect("not,a,bca,file\n1,2,3"));
    }

    public function test_parses_credit_row_with_full_datetime(): void
    {
        $rows = (new BcaStatementParser())->parse($this->fixture());

        $first = $rows[0];
        $this->assertSame('2026-09-01 14:24:27', $first['transaction_date']->format('Y-m-d H:i:s'));
        $this->assertSame(18429612.0, $first['credit_amount']);
        $this->assertSame(0.0, $first['debit_amount']);
        $this->assertSame(130941712.79, $first['running_balance']);
        $this->assertStringContainsString('MCM InhouseTrf', $first['description']);
    }

    public function test_parses_debit_row_and_indonesian_month_name(): void
    {
        $rows = (new BcaStatementParser())->parse($this->fixture());

        $debitRow = $rows[2]; // "02 Januari 2026 ..." biaya admin
        $this->assertSame('2026-01-02', $debitRow['transaction_date']->format('Y-m-d'));
        $this->assertSame(25000.0, $debitRow['debit_amount']);
        $this->assertSame(0.0, $debitRow['credit_amount']);
    }

    public function test_parses_english_month_name(): void
    {
        $rows = (new BcaStatementParser())->parse($this->fixture());

        $row = $rows[3]; // "15 January 2026 ..."
        $this->assertSame('2026-01-15', $row['transaction_date']->format('Y-m-d'));
    }

    public function test_returns_one_row_per_data_line(): void
    {
        $rows = (new BcaStatementParser())->parse($this->fixture());

        $this->assertCount(4, $rows);
    }
}
