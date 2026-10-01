<?php

namespace Tests\Unit\Support;

use App\Support\ImportErrorReportWriter;
use PHPUnit\Framework\TestCase;

class ImportErrorReportWriterTest extends TestCase
{
    public function test_builds_csv_with_header_and_rows(): void
    {
        $csv = ImportErrorReportWriter::toCsv([
            ['document_number' => 'SI/KE/00001', 'status' => 'needs_review', 'reason' => 'Duplikat'],
            ['document_number' => 'SI/KE/00002', 'status' => 'failed', 'reason' => 'Error lain'],
        ]);

        $lines = explode("\n", trim(str_replace("\r\n", "\n", $csv)));
        $this->assertSame('document_number,status,reason', $lines[0]);
        $this->assertSame('SI/KE/00001,needs_review,Duplikat', $lines[1]);
        $this->assertSame('SI/KE/00002,failed,"Error lain"', $lines[2]);
    }

    public function test_empty_rows_produce_empty_string(): void
    {
        $this->assertSame('', ImportErrorReportWriter::toCsv([]));
    }
}
