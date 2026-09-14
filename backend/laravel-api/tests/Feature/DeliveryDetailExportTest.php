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
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * mode=detail (the default) — the flat, one-row-per-delivery-line replacement
 * for the legacy xlsDeliveryOrderListing_Detail.xlsx layout. See
 * DeliveryDetailExport/DeliveryDetailDataSheet for the column contract.
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

    /** @return array{0: Delivery, 1: int} [delivery, line count] */
    protected function makeDeliveryWithLines(string $documentNumber, array $lines): Delivery
    {
        $salesOrder = SalesOrder::query()->create([
            'document_number' => 'SO/KE/'.$documentNumber, 'status' => 'approved',
            'customer_id' => $this->customer->id, 'sales_person_id' => $this->salesPerson->id,
            'reference' => 'PO-'.$documentNumber, 'order_date' => '2026-08-01',
            'total_amount' => 0, 'grand_total' => 0,
        ]);
        $delivery = Delivery::query()->create([
            'document_number' => 'DO/KE/'.$documentNumber, 'status' => 'complete',
            'sales_order_id' => $salesOrder->id, 'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id, 'terms_of_payment_id' => $this->terms->id,
            'delivery_date' => '2026-08-05', 'due_date' => '2026-09-04', 'remarks' => 'Handle with care',
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

    protected function downloadXlsx(string $query = ''): Spreadsheet
    {
        $response = $this->get('/api/v1/deliveries/export?format=xlsx'.($query ? "&{$query}" : ''));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'delivery-detail').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $spreadsheet = IOFactory::load($tmpPath);
        unlink($tmpPath);

        return $spreadsheet;
    }

    public function test_detail_export_has_single_header_row_no_merges_and_one_row_per_line(): void
    {
        $this->makeDeliveryWithLines('0001', [[2, 10000], [1, 5000]]);
        $this->makeDeliveryWithLines('0002', [[3, 7000]]);

        $spreadsheet = $this->downloadXlsx();
        $sheet = $spreadsheet->getSheetByName('Delivery Detail');
        $this->assertNotNull($sheet);

        $this->assertSame([
            'No', 'Tanggal', 'No Dokumen', 'Kode Customer', 'Nama Customer',
            'Kode Item', 'Deskripsi Item', 'UOM', 'Quantity', 'Unit Price',
            'Disc', 'Tax', 'Line Amount', 'Reference 1', 'Reference 2',
            'Sales Person', 'Lokasi', 'Status', 'Jatuh Tempo', 'Termin',
            'Kode Pajak', 'Catatan', 'Subtotal Dokumen', 'Tax Dokumen', 'Grand Total Dokumen',
        ], $sheet->rangeToArray('A1:Y1')[0]);

        // 3 delivery lines total (2 + 1), all on rows 2-4 — no separate document-header row anywhere.
        $this->assertSame(4, $sheet->getHighestRow());
        $this->assertCount(0, $sheet->getMergeCells());

        // Info sheet exists separately and doesn't leak into the data sheet.
        $this->assertNotNull($spreadsheet->getSheetByName('Info'));
        $this->assertSame(2, $spreadsheet->getSheetCount());
    }

    public function test_numeric_and_date_cells_are_typed_not_text(): void
    {
        $this->makeDeliveryWithLines('0003', [[2, 10000]]);

        $sheet = $this->downloadXlsx()->getSheetByName('Delivery Detail');

        // Quantity (I2), Unit Price (J2), Line Amount (M2) — real numeric cells.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('I2')->getDataType());
        $this->assertSame(2.0, $sheet->getCell('I2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('J2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('M2')->getDataType());
        $this->assertSame(20000.0, $sheet->getCell('M2')->getValue());

        // Disc column always 0 (numeric), never blank/"-" — no per-line discount concept exists in this schema.
        $this->assertSame(0.0, $sheet->getCell('K2')->getValue());

        // Tanggal (B2) — a real Excel date serial, not a formatted string.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertEqualsWithDelta(ExcelDate::PHPToExcel(\Carbon\Carbon::parse('2026-08-05')), (float) $sheet->getCell('B2')->getValue(), 0.0001);
    }

    public function test_document_totals_repeat_per_line_and_match_sum_of_line_amounts(): void
    {
        $this->makeDeliveryWithLines('0004', [[2, 10000], [1, 5000]]); // amounts 20000 + 5000 = 25000

        $sheet = $this->downloadXlsx()->getSheetByName('Delivery Detail');

        $lineAmounts = [(float) $sheet->getCell('M2')->getValue(), (float) $sheet->getCell('M3')->getValue()];
        $subtotalRow2 = (float) $sheet->getCell('W2')->getValue();
        $subtotalRow3 = (float) $sheet->getCell('W3')->getValue();

        $this->assertSame(25000.0, array_sum($lineAmounts));
        $this->assertSame(25000.0, $subtotalRow2);
        $this->assertSame($subtotalRow2, $subtotalRow3); // denormalized identically on every line of the same document

        $tax = (float) $sheet->getCell('X2')->getValue();
        $grandTotal = (float) $sheet->getCell('Y2')->getValue();
        $this->assertEqualsWithDelta($subtotalRow2 + $tax, $grandTotal, 0.001);
    }

    public function test_status_shows_invoiced_only_once_actually_invoiced(): void
    {
        $delivery = $this->makeDeliveryWithLines('0005', [[1, 1000]]);
        $sheet = $this->downloadXlsx()->getSheetByName('Delivery Detail');
        $this->assertSame('Complete', $sheet->getCell('R2')->getValue());

        \App\Models\Invoice::query()->create([
            'invoice_type' => 'goods', 'status' => 'submitted', 'customer_id' => $this->customer->id,
            'invoice_date' => '2026-08-06', 'due_date' => '2026-09-05',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 1000,
        ])->deliveries()->attach($delivery->id);

        $sheet = $this->downloadXlsx()->getSheetByName('Delivery Detail');
        $this->assertSame('Invoiced', $sheet->getCell('R2')->getValue());
    }

    public function test_respects_active_filters_and_defaults_to_date_then_document_order(): void
    {
        $this->makeDeliveryWithLines('0006', [[1, 1000]]);
        $other = Customer::query()->create(['customer_code' => 'C-0099', 'customer_name' => 'Other Co']);
        $salesOrder = SalesOrder::query()->create([
            'document_number' => 'SO/KE/0099', 'status' => 'approved', 'customer_id' => $other->id,
            'order_date' => '2026-08-02', 'total_amount' => 0, 'grand_total' => 0,
        ]);
        $delivery = Delivery::query()->create([
            'document_number' => 'DO/KE/0099', 'status' => 'complete', 'sales_order_id' => $salesOrder->id,
            'customer_id' => $other->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => '2026-08-01', 'due_date' => '2026-08-31',
        ]);
        $soItem = $salesOrder->items()->create(['item_id' => $this->item->id, 'qty' => 1, 'rate' => 500, 'amount' => 500, 'delivered_qty' => 0]);
        $delivery->items()->create([
            'sales_order_item_id' => $soItem->id, 'item_id' => $this->item->id,
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'uom' => 'Pcs',
            'rate' => 500, 'qty' => 1, 'amount' => 500,
        ]);

        $sheet = $this->downloadXlsx('customer_id='.$this->customer->id)->getSheetByName('Delivery Detail');
        $this->assertSame(2, $sheet->getHighestRow()); // header + exactly 1 line — the other customer's delivery is excluded.
        $this->assertSame('DO/KE/0006', $sheet->getCell('C2')->getValue());

        // No filter: both deliveries, ordered by delivery_date ASC (0099's 08-01 before 0006's 08-05).
        $sheetAll = $this->downloadXlsx()->getSheetByName('Delivery Detail');
        $this->assertSame('DO/KE/0099', $sheetAll->getCell('C2')->getValue());
        $this->assertSame('DO/KE/0006', $sheetAll->getCell('C3')->getValue());
    }

    public function test_csv_export_has_bom_single_header_line_and_plain_values(): void
    {
        $this->makeDeliveryWithLines('0007', [[2, 10000]]);

        $response = $this->get('/api/v1/deliveries/export?format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content); // UTF-8 BOM
        $body = str_replace("\xEF\xBB\xBF", '', trim($content));
        $lines = preg_split('/\r\n|\n/', $body);
        $this->assertCount(2, $lines); // header + 1 line, no document-header row.

        $header = str_getcsv($lines[0]);
        $this->assertSame(['No', 'Tanggal', 'No Dokumen', 'Kode Customer'], array_slice($header, 0, 4));

        // Date as plain dd/mm/yyyy text (CSV has no cell type), amounts as bare numbers — no "Rp", no thousand separator.
        $row = str_getcsv($lines[1]);
        $this->assertSame('05/08/2026', $row[1]);
        $this->assertSame('20000', $row[12]); // Line Amount
        $this->assertStringNotContainsString('Rp', $content);
        $this->assertStringNotContainsString('20,000', $content);
    }
}
