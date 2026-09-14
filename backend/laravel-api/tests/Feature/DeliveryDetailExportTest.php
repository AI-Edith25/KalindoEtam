<?php

namespace Tests\Feature;

use App\Enums\TaxCalculationMode;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\SalesOrder;
use App\Models\SalesPerson;
use App\Models\Tax;
use App\Models\TermsOfPayment;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * mode=detail (the default) — the flat, one-row-per-delivery-line replacement
 * for the legacy xlsDeliveryOrderListing_Detail.xlsx layout. Metadata
 * (title/period/company/generated-at) lives on the same data sheet at the
 * legacy template's own A1/A2/A5/D5 positions (see DeliveryDetailExport);
 * there is no separate "Info" sheet and no auto filter.
 */
class DeliveryDetailExportTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Tax $tax;

    protected Warehouse $warehouse;

    protected SalesPerson $salesPerson;

    protected TermsOfPayment $terms;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        Permission::query()->firstOrCreate(['name' => 'sales.deliveries.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.deliveries.view');
        Sanctum::actingAs($user);

        $this->customer = Customer::query()->create(['customer_code' => 'C-0011', 'customer_name' => 'Acme']);
        $this->tax = Tax::query()->create([
            'code' => 'PPN11-S', 'name' => 'PPN 11%', 'type' => TaxType::VAT,
            'transaction_type' => TaxTransactionType::SALES, 'rate' => 11,
            'calculation_mode' => TaxCalculationMode::EXCLUSIVE, 'is_active' => true,
        ]);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->salesPerson = SalesPerson::query()->create(['code' => 'SP001', 'name' => 'Budi']);
        $this->terms = TermsOfPayment::query()->create(['code' => 'COD', 'name' => 'Cash On Delivery', 'days' => 0, 'is_active' => true]);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget',
            'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id,
            'uom_id' => UnitOfMeasurement::query()->create(['name' => 'Pcs'])->id,
            'standard_rate' => 10000,
        ]);
    }

    protected function makeDeliveryWithLines(string $documentNumber, string $deliveryDate, array $lines): Delivery
    {
        $salesOrder = SalesOrder::query()->create([
            'document_number' => 'SO/KE/'.$documentNumber, 'status' => 'approved',
            'customer_id' => $this->customer->id, 'sales_person_id' => $this->salesPerson->id,
            'reference' => 'PO-'.$documentNumber, 'order_date' => $deliveryDate,
            'total_amount' => 0, 'grand_total' => 0,
        ]);
        $delivery = Delivery::query()->create([
            'document_number' => 'DO/KE/'.$documentNumber, 'status' => 'complete',
            'sales_order_id' => $salesOrder->id, 'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id, 'terms_of_payment_id' => $this->terms->id,
            'delivery_date' => $deliveryDate, 'due_date' => '2026-09-04', 'remarks' => 'Handle with care',
        ]);

        foreach ($lines as [$qty, $rate]) {
            $amount = $qty * $rate;
            $taxAmount = round($amount * 0.11, 2);
            $soItem = $salesOrder->items()->create([
                'item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'delivered_qty' => 0,
            ]);
            $delivery->items()->create([
                'sales_order_item_id' => $soItem->id, 'item_id' => $this->item->id,
                'item_code' => 'ITM-1', 'item_name' => 'Widget', 'uom' => 'Pcs',
                'rate' => $rate, 'qty' => $qty, 'amount' => $amount,
                'tax_id' => $this->tax->id, 'tax_amount' => $taxAmount,
            ]);
        }

        return $delivery->load(['items']);
    }

    protected function downloadXlsx(string $query = ''): Worksheet
    {
        $response = $this->get('/api/v1/deliveries/export?format=xlsx'.($query ? "&{$query}" : ''));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'delivery-detail').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheetByName('Delivery Detail');
        unlink($tmpPath);

        return $sheet;
    }

    public function test_metadata_and_header_layout_matches_legacy_positions(): void
    {
        $this->makeDeliveryWithLines('0001', '2026-08-05', [[2, 10000], [1, 5000]]);
        $this->makeDeliveryWithLines('0002', '2026-05-10', [[3, 7000]]);

        $sheet = $this->downloadXlsx();

        $this->assertSame('DELIVERY ORDER LISTING - DETAIL', $sheet->getCell('A1')->getValue());
        $this->assertSame('10/05/2026 - 05/08/2026', $sheet->getCell('A2')->getValue()); // no date filter -> actual min/max of the exported data
        $this->assertSame('PT. KALINDO ETAM', $sheet->getCell('A5')->getValue());
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}:\d{2}$/', (string) $sheet->getCell('D5')->getValue());

        // Rows 3, 4, 6, 7 stay blank.
        foreach ([3, 4, 6, 7] as $row) {
            $this->assertNull($sheet->getCell("A{$row}")->getValue());
        }

        // Header is exactly row 8 — one row, 24 columns, no merges anywhere.
        $this->assertSame([
            'No', 'Tanggal', 'No Dokumen', 'Kode Customer', 'Nama Customer',
            'Kode Item', 'Deskripsi Item', 'UOM', 'Quantity', 'Unit Price',
            'Disc', 'Tax', 'Line Amount', 'Reference', 'Sales Person', 'Lokasi',
            'Status', 'Jatuh Tempo', 'Termin', 'Kode Pajak', 'Catatan',
            'Subtotal Dokumen', 'Tax Dokumen', 'Grand Total Dokumen',
        ], $sheet->rangeToArray('A8:X8')[0]);
        $this->assertCount(0, $sheet->getMergeCells());

        // 3 delivery lines total (2 + 1), data starting row 9 -> highest row 11.
        $this->assertSame(11, $sheet->getHighestRow());

        // No auto filter.
        $this->assertSame('', $sheet->getAutoFilter()->getRange());
    }

    public function test_numeric_and_date_cells_are_typed_not_text(): void
    {
        $this->makeDeliveryWithLines('0003', '2026-08-05', [[2, 10000]]);

        $sheet = $this->downloadXlsx();

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I9')->getDataType());
        $this->assertSame(2.0, $sheet->getCell('I9')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('J9')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M9')->getDataType());
        $this->assertSame(20000.0, $sheet->getCell('M9')->getValue());

        // Disc column always 0 (numeric), never blank/"-" — no per-line discount concept exists in this schema.
        $this->assertSame(0.0, $sheet->getCell('K9')->getValue());

        // Tanggal (B9) — a real Excel date serial, not a formatted string.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B9')->getDataType());
        $this->assertEqualsWithDelta(ExcelDate::PHPToExcel(Carbon::parse('2026-08-05')), (float) $sheet->getCell('B9')->getValue(), 0.0001);
    }

    public function test_document_totals_repeat_per_line_and_match_sum_of_line_amounts(): void
    {
        $this->makeDeliveryWithLines('0004', '2026-08-05', [[2, 10000], [1, 5000]]); // amounts 20000 + 5000 = 25000

        $sheet = $this->downloadXlsx();

        $lineAmounts = [(float) $sheet->getCell('M9')->getValue(), (float) $sheet->getCell('M10')->getValue()];
        $subtotalRow9 = (float) $sheet->getCell('V9')->getValue();
        $subtotalRow10 = (float) $sheet->getCell('V10')->getValue();

        $this->assertSame(25000.0, array_sum($lineAmounts));
        $this->assertSame(25000.0, $subtotalRow9);
        $this->assertSame($subtotalRow9, $subtotalRow10); // denormalized identically on every line of the same document

        $tax = (float) $sheet->getCell('W9')->getValue();
        $grandTotal = (float) $sheet->getCell('X9')->getValue();
        $this->assertEqualsWithDelta($subtotalRow9 + $tax, $grandTotal, 0.001);
    }

    public function test_status_shows_invoiced_only_once_actually_invoiced(): void
    {
        $delivery = $this->makeDeliveryWithLines('0005', '2026-08-05', [[1, 1000]]);
        $sheet = $this->downloadXlsx();
        $this->assertSame('Complete', $sheet->getCell('Q9')->getValue());

        \App\Models\Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $this->customer->id,
            'invoice_date' => '2026-08-06', 'due_date' => '2026-09-05',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 1000,
        ])->deliveries()->attach($delivery->id);

        $sheet = $this->downloadXlsx();
        $this->assertSame('Invoiced', $sheet->getCell('Q9')->getValue());
    }

    public function test_default_order_is_latest_first_matching_the_list_page_and_respects_filters(): void
    {
        $this->makeDeliveryWithLines('0006', '2026-08-05', [[1, 1000]]);
        $other = Customer::query()->create(['customer_code' => 'C-0099', 'customer_name' => 'Other Co']);
        $salesOrder = SalesOrder::query()->create([
            'document_number' => 'SO/KE/0099', 'status' => 'approved', 'customer_id' => $other->id,
            'order_date' => '2026-08-10', 'total_amount' => 0, 'grand_total' => 0,
        ]);
        $delivery = Delivery::query()->create([
            'document_number' => 'DO/KE/0099', 'status' => 'complete', 'sales_order_id' => $salesOrder->id,
            'customer_id' => $other->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => '2026-08-10', 'due_date' => '2026-08-31',
        ]);
        $soItem = $salesOrder->items()->create(['item_id' => $this->item->id, 'qty' => 1, 'rate' => 500, 'amount' => 500, 'delivered_qty' => 0]);
        $delivery->items()->create([
            'sales_order_item_id' => $soItem->id, 'item_id' => $this->item->id,
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'uom' => 'Pcs',
            'rate' => 500, 'qty' => 1, 'amount' => 500,
        ]);

        $sheet = $this->downloadXlsx('customer_id='.$this->customer->id);
        $this->assertSame(9, $sheet->getHighestRow()); // header (8) + exactly 1 line — the other customer's delivery is excluded.
        $this->assertSame('DO/KE/0006', $sheet->getCell('C9')->getValue());

        // No filter, no explicit sort: same "latest first" default as /sales/deliveries (0099's 08-10 before 0006's 08-05).
        $sheetAll = $this->downloadXlsx();
        $this->assertSame('DO/KE/0099', $sheetAll->getCell('C9')->getValue());
        $this->assertSame('DO/KE/0006', $sheetAll->getCell('C10')->getValue());
    }

    public function test_export_follows_the_sort_the_user_picked_on_screen(): void
    {
        $this->makeDeliveryWithLines('0010', '2026-08-05', [[1, 1000]]);
        $this->makeDeliveryWithLines('0009', '2026-08-10', [[1, 1000]]);

        // Sorted by Document ascending -> 0009 before 0010, regardless of delivery_date.
        $sheet = $this->downloadXlsx('sort_by=document_number&sort_direction=asc');
        $this->assertSame('DO/KE/0009', $sheet->getCell('C9')->getValue());
        $this->assertSame('DO/KE/0010', $sheet->getCell('C10')->getValue());
    }

    public function test_filename_and_period_use_actual_data_range_when_no_date_filter(): void
    {
        $this->makeDeliveryWithLines('0011', '2026-05-01', [[1, 1000]]);
        $this->makeDeliveryWithLines('0012', '2026-09-14', [[1, 1000]]);

        $response = $this->get('/api/v1/deliveries/export?format=xlsx');
        $response->assertOk();

        $this->assertStringContainsString(
            'DeliveryOrderListing_Detail_2026-05-01_2026-09-14.xlsx',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_filename_and_period_use_explicit_date_filter_when_given(): void
    {
        $this->makeDeliveryWithLines('0013', '2026-05-01', [[1, 1000]]);

        $response = $this->get('/api/v1/deliveries/export?format=xlsx&date_from=2026-01-01&date_to=2026-12-31');
        $response->assertOk();
        $this->assertStringContainsString(
            'DeliveryOrderListing_Detail_2026-01-01_2026-12-31.xlsx',
            (string) $response->headers->get('content-disposition'),
        );

        $tmpPath = tempnam(sys_get_temp_dir(), 'delivery-detail').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheetByName('Delivery Detail');
        unlink($tmpPath);
        $this->assertSame('01/01/2026 - 31/12/2026', $sheet->getCell('A2')->getValue());
    }

    public function test_csv_export_has_no_metadata_single_header_line_and_plain_values(): void
    {
        $this->makeDeliveryWithLines('0007', '2026-08-05', [[2, 10000]]);

        $response = $this->get('/api/v1/deliveries/export?format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content); // UTF-8 BOM
        $body = str_replace("\xEF\xBB\xBF", '', trim($content));
        $lines = preg_split('/\r\n|\n/', $body);
        $this->assertCount(2, $lines); // header + 1 line only — no metadata rows, no document-header row.

        $header = str_getcsv($lines[0]);
        $this->assertSame(['No', 'Tanggal', 'No Dokumen', 'Kode Customer'], array_slice($header, 0, 4));
        $this->assertSame('Reference', $header[13]);
        $this->assertCount(24, $header);

        // Date as plain dd/mm/yyyy text (CSV has no cell type), amounts as bare numbers — no "Rp", no thousand separator.
        $row = str_getcsv($lines[1]);
        $this->assertSame('05/08/2026', $row[1]);
        $this->assertSame('20000', $row[12]); // Line Amount
        $this->assertStringNotContainsString('Rp', $content);
        $this->assertStringNotContainsString('20,000', $content);
    }
}
