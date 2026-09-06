<?php

namespace Tests\Unit\Import;

use App\Services\Import\HeaderDetector;
use App\Services\Import\Templates\SalesPersonImportTemplate;
use PHPUnit\Framework\TestCase;

class HeaderDetectorTest extends TestCase
{
    /** Shape of the real xlsItemListing.xlsx fixture: 3 preamble rows, header at row 4, a type-spec row at row 5, then data. */
    public function test_detects_header_past_preamble_and_skips_a_type_spec_row(): void
    {
        $rawRows = [
            ['ITEM MAINTENANCE', null, null, '**  t = text   **  n = number', null, '31/08/2026 10:41:19'],
            ['PT. KALINDO ETAM', null, null, 'X = columns and rows to be amend', null, null],
            ['AmendYN', 'X', 'X', 'X', 'X', null],
            [null, 'ItemCode', 'Description', 'UnitPrice', 'UOM', null],
            [null, '40 (t)', '255 (t)', '12 (n)', '30 (t)', null],
            [null, '8,5 KG_TOKKA_2"', 'PAKU TOKKA 2"@ 8,5 KG', 0, 'DUS', null],
            [null, 'BENDRAT TOKKA @ 20 KG', 'KAWAT BENDRAT TOKKA @ 20 KG', 255945.95, 'ROLL', null],
            [null, 'IS - 4 MM', 'INDOSTAR MATRIC 4 MM', 46846.85, 'LEMBAR', null],
        ];

        $this->assertSame(['header_row' => 4, 'data_start_row' => 6], HeaderDetector::detect($rawRows));
    }

    public function test_plain_single_header_file_resolves_to_row_one(): void
    {
        $rawRows = [
            ['Kode Barang', 'Nama Barang', 'Kategori', 'Satuan', 'Harga'],
            ['ITM-001', 'Semen Portland', 'General', 'Pcs', 65000],
            ['ITM-002', 'Besi Beton', 'General', 'Pcs', 120000],
        ];

        $this->assertSame(['header_row' => 1, 'data_start_row' => 2], HeaderDetector::detect($rawRows));
    }

    /** A data row can legitimately blank an optional trailing column without breaking detection. */
    public function test_tolerates_a_data_row_blanking_an_optional_column(): void
    {
        $rawRows = [
            ['Satuan', 'Simbol', 'Kategori', 'Catatan'],
            ['Pcs', 'pcs', 'Aktif', null],
            ['Zak', 'zak', 'Aktif', null],
        ];

        $this->assertSame(['header_row' => 1, 'data_start_row' => 2], HeaderDetector::detect($rawRows));
    }

    /** Too few rows below any candidate to trust its shape -> falls back to row 1. */
    public function test_falls_back_to_row_one_with_only_a_single_data_row(): void
    {
        $rawRows = [
            ['Kode Barang', 'Nama Barang', 'Kategori', 'Satuan', 'Harga'],
            ['ITM-001', 'Semen Portland', 'General', 'Pcs', 65000],
        ];

        $this->assertSame(['header_row' => 1, 'data_start_row' => 2], HeaderDetector::detect($rawRows));
    }

    /**
     * Real xlsSalesPersonListing.xlsx shape: 4 preamble rows, header at row 5, then data rows
     * where most non-code/name/status columns (Address/Area/Telephone/Fax/Branch) are blank.
     * Without the semantic pass, the structural heuristic alone mis-picks a later data row —
     * many share the exact same "only Code/Name/Ratio/Status populated" shape as their own
     * lookahead window, scoring a perfect (zero-extra) structural match that beats the real
     * header (which necessarily spans all 9 columns, some rarely used).
     */
    public function test_detects_header_past_preamble_when_most_data_rows_share_a_sparser_shape(): void
    {
        $rawRows = [
            ['SALES PERSON LISTING', null, null, null, null, null, null, null, null],
            [null, null, null, null, null, null, null, null, null],
            ['PT. KALINDO ETAM', null, null, null, '05/09/2026 23:02:40', null, null, null, null],
            [null, null, null, null, null, null, null, null, null],
            ['Sales Person Code', 'Sales Person Name', 'Address', 'Area', 'Telephone', 'Fax', 'Ratio', 'Branch', 'Status'],
            ['KE-0003', 'EDDY WIJAYA HOLIM', null, null, null, null, 0, null, 'Active'],
            ['KE-AKHSAN', 'AKHSAN', null, 'SMR', null, null, 0, 'SMD', 'Active'],
            ['KE-ANDRI', 'EKO ANDRI ASTUTI', null, null, null, null, 0, null, 'Active'],
            ['KE-ANTONY', 'ANTONY', null, null, null, null, 0, null, 'Active'],
            ['KE-AVRIANUS', 'AVRIANUS', null, null, null, null, 0, null, 'Active'],
            ['KE-BUDI', 'BUDI SANTOSO', null, null, null, null, 0, null, 'Active'],
            ['KE-CITRA', 'CITRA DEWI', null, null, null, null, 0, null, 'Active'],
        ];

        $fields = (new SalesPersonImportTemplate)->fields();

        $this->assertSame(['header_row' => 5, 'data_start_row' => 6], HeaderDetector::detect($rawRows, $fields));
    }
}
