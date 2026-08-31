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

    /**
     * A genuinely-numeric Excel cell (PhpSpreadsheet gives floats/ints, not strings) is never
     * ambiguous and must pass straight through — re-stringifying it once turned a real
     * 255945.95 into 25594595.0 under the default 'dot_thousands' style.
     */
    public function test_normalize_number_passes_through_already_numeric_values_regardless_of_style(): void
    {
        $this->assertSame(255945.95, DataCleaner::normalizeNumber(255945.95, 'dot_thousands'));
        $this->assertSame(255945.95, DataCleaner::normalizeNumber(255945.95, 'dot_decimal'));
        $this->assertSame(0.0, DataCleaner::normalizeNumber(0, 'dot_thousands'));
        $this->assertSame(50000.0, DataCleaner::normalizeNumber(50000, 'dot_decimal'));
    }

    public function test_detect_decimal_style_from_decimal_shaped_string_values(): void
    {
        $this->assertSame('dot_decimal', DataCleaner::detectDecimalStyle(['255945.95', '46846.85', '0']));
    }

    public function test_detect_decimal_style_from_thousands_shaped_string_values(): void
    {
        $this->assertSame('dot_thousands', DataCleaner::detectDecimalStyle(['1.000', '25.500', '540.541']));
    }

    public function test_detect_decimal_style_defaults_to_thousands_when_no_evidence(): void
    {
        $this->assertSame('dot_thousands', DataCleaner::detectDecimalStyle([null, '', '0']));
        $this->assertSame('dot_thousands', DataCleaner::detectDecimalStyle([255945.95, 46846.85, 0]));
    }

    public function test_normalize_text_trims_strips_leading_hash_and_collapses_spaces(): void
    {
        $this->assertSame('Semen Portland', DataCleaner::normalizeText('  # Semen   Portland  '));
    }

    /** Regression: item codes/names legitimately contain commas and inch-marks (e.g. real xlsItemListing.xlsx rows) — normalizeText must never strip them. */
    public function test_normalize_text_leaves_commas_and_quotes_untouched(): void
    {
        $this->assertSame('8,5 KG_TOKKA_2"', DataCleaner::normalizeText('8,5 KG_TOKKA_2"'));
        $this->assertSame('PAKU TOKKA 2"@ 8,5 KG', DataCleaner::normalizeText('PAKU TOKKA 2"@ 8,5 KG'));
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
