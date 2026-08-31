<?php

namespace Tests\Unit\Import;

use App\Services\Import\DataCleaner;
use PHPUnit\Framework\TestCase;

class DataCleanerTest extends TestCase
{
    public function test_normalize_number_strips_rupiah_symbol_and_thousands_separator(): void
    {
        $this->assertSame(65000.0, DataCleaner::normalizeNumber('Rp 65.000', 'dot_thousands'));
    }

    public function test_normalize_number_strips_hash_symbol_under_thousands_style(): void
    {
        $this->assertSame(540541.0, DataCleaner::normalizeNumber('# 540.541', 'dot_thousands'));
    }

    public function test_normalize_number_treats_dot_as_decimal_point_when_configured(): void
    {
        $this->assertSame(540.541, DataCleaner::normalizeNumber('540.541', 'dot_decimal'));
    }

    public function test_normalize_number_blank_is_null(): void
    {
        $this->assertNull(DataCleaner::normalizeNumber('', 'dot_thousands'));
        $this->assertNull(DataCleaner::normalizeNumber(null, 'dot_thousands'));
    }

    public function test_normalize_text_trims_strips_leading_hash_and_collapses_spaces(): void
    {
        $this->assertSame('Semen Portland', DataCleaner::normalizeText('  # Semen   Portland  '));
    }

    public function test_normalize_text_blank_is_null(): void
    {
        $this->assertNull(DataCleaner::normalizeText('   '));
    }

    public function test_normalize_date_accepts_multiple_formats(): void
    {
        $this->assertSame('2026-03-15', DataCleaner::normalizeDate('15/03/2026'));
        $this->assertSame('2026-03-15', DataCleaner::normalizeDate('15-03-2026'));
        $this->assertSame('2026-03-15', DataCleaner::normalizeDate('2026-03-15'));
    }

    public function test_normalize_date_rejects_unparseable_value(): void
    {
        $this->assertNull(DataCleaner::normalizeDate('not a date'));
    }

    public function test_blank_to_null(): void
    {
        $this->assertNull(DataCleaner::blankToNull(''));
        $this->assertNull(DataCleaner::blankToNull(null));
        $this->assertSame('x', DataCleaner::blankToNull('x'));
    }

    public function test_drops_100_percent_empty_column(): void
    {
        $rows = [
            ['code' => 'A', 'notes' => ''],
            ['code' => 'B', 'notes' => null],
        ];

        $result = DataCleaner::dropEmptyAndConstantColumns($rows);

        $this->assertSame(['notes'], $result['droppedEmpty']);
        $this->assertSame([], $result['droppedConstant']);
        $this->assertSame([['code' => 'A'], ['code' => 'B']], $result['rows']);
    }

    public function test_drops_constant_value_column(): void
    {
        $rows = [
            ['code' => 'A', 'currency' => 'IDR'],
            ['code' => 'B', 'currency' => 'IDR'],
        ];

        $result = DataCleaner::dropEmptyAndConstantColumns($rows);

        $this->assertSame(['currency'], $result['droppedConstant']);
        $this->assertSame([['code' => 'A'], ['code' => 'B']], $result['rows']);
    }

    public function test_keeps_column_with_varying_values(): void
    {
        $rows = [
            ['code' => 'A'],
            ['code' => 'B'],
        ];

        $result = DataCleaner::dropEmptyAndConstantColumns($rows);

        $this->assertSame([], $result['droppedEmpty']);
        $this->assertSame([], $result['droppedConstant']);
        $this->assertSame($rows, $result['rows']);
    }
}
