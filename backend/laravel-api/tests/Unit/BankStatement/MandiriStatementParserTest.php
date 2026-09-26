<?php

namespace Tests\Unit\BankStatement;

use App\Services\BankStatement\MandiriStatementParser;
use PHPUnit\Framework\TestCase;

class MandiriStatementParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/BankStatements/mandiri_sample.csv');
    }

    public function test_detects_header_past_metadata_rows(): void
    {
        $this->assertTrue((new MandiriStatementParser())->detect($this->fixture()));
        $this->assertFalse((new MandiriStatementParser())->detect("just,some,other,csv\n1,2,3"));
    }

    public function test_parses_debit_row(): void
    {
        $rows = (new MandiriStatementParser())->parse($this->fixture());

        $first = $rows[0]; // "01/09/2026","...","0027","32,000.00 DB","1,155,986,343.14"
        $this->assertSame('2026-09-01', $first['transaction_date']->format('Y-m-d'));
        $this->assertSame(32000.0, $first['debit_amount']);
        $this->assertSame(0.0, $first['credit_amount']);
        $this->assertSame(1155986343.14, $first['running_balance']);
    }

    public function test_parses_credit_row(): void
    {
        $rows = (new MandiriStatementParser())->parse($this->fixture());

        $creditRow = $rows[2]; // "1,500,000.00 CR"
        $this->assertSame(1500000.0, $creditRow['credit_amount']);
        $this->assertSame(0.0, $creditRow['debit_amount']);
    }

    public function test_skips_footer_summary_lines_without_erroring(): void
    {
        $rows = (new MandiriStatementParser())->parse($this->fixture());

        // 53 data rows in the fixture; footer (Saldo Awal/Mutasi Debet/Mutasi Kredit/Saldo Akhir) must not appear.
        $this->assertCount(53, $rows);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(now()->addYear(), $row['transaction_date']);
        }
    }

    public function test_throws_when_header_row_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MandiriStatementParser())->parse("no,header,here\n1,2,3");
    }
}
