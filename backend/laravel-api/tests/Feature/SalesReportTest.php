<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\TaxTransactionType;
use App\Enums\TaxType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Permission;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\SalesReportService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected SalesReportService $salesReportService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->salesReportService = app(SalesReportService::class);

        $company = Company::query()->create([
            'name' => 'Test Co', 'code' => 'TC', 'currency' => 'IDR', 'fiscal_year_start' => now()->startOfYear()->toDateString(),
        ]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        Currency::query()->create(['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'symbol' => 'RP', 'exchange_rate' => 1]);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);

        app(StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 1000,
            postingDatetime: now(),
        );
    }

    protected function makeTax(array $overrides = []): Tax
    {
        return Tax::query()->create(array_merge([
            'code' => 'PPN11', 'name' => 'PPN 11%', 'type' => TaxType::VAT,
            'transaction_type' => TaxTransactionType::SALES, 'rate' => 11, 'is_active' => true,
        ], $overrides));
    }

    /** @return array{0: \App\Models\SalesOrder, 1: \App\Models\Delivery} */
    protected function submittedDelivery(int $qty, float $rate, ?string $taxId = null): array
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate, 'tax_id' => $taxId]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);

        return [$salesOrder, $this->deliveryService->complete($delivery)];
    }

    public function test_summary_rows_reconcile_and_total_row_sums_correctly(): void
    {
        $tax = $this->makeTax();
        [, $delivery1] = $this->submittedDelivery(qty: 10, rate: 10000, taxId: $tax->id); // subtotal 100000, tax 11000
        [, $delivery2] = $this->submittedDelivery(qty: 5, rate: 20000); // subtotal 100000, no tax

        $invoice1 = $this->invoiceService->create(['delivery_ids' => [$delivery1->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);
        $invoice2 = $this->invoiceService->create(['delivery_ids' => [$delivery2->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        $invoices = $this->salesReportService->rows([]);
        $rows = $this->salesReportService->summaryRows($invoices);

        $this->assertCount(3, $rows); // 2 invoices + Total

        $row1 = collect($rows)->first(fn ($r) => $r[1] === $invoice1->document_number);
        $this->assertEquals(100000.0, $row1[5]); // EXCL.TAX
        $this->assertEquals(0.0, $row1[6]); // DISC
        $this->assertEquals(11000.0, $row1[7]); // TAX
        $this->assertEquals(111000.0, $row1[8]); // INCL.TAX
        $this->assertEqualsWithDelta($row1[5] - $row1[6] + $row1[7], $row1[8], 0.001);

        $total = $rows[array_key_last($rows)];
        $this->assertEquals('Total By Header', $total[4]);
        $this->assertEquals(200000.0, $total[5]);
        $this->assertEquals(0.0, $total[6]);
        $this->assertEquals(11000.0, $total[7]);
        $this->assertEquals(211000.0, $total[8]);
    }

    public function test_summary_includes_transportation_invoices(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => 'transportation',
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['description' => 'Ongkos Angkut', 'qty' => 1, 'rate' => 500000]],
        ]);

        $invoices = $this->salesReportService->rows([]);
        $rows = $this->salesReportService->summaryRows($invoices);

        $this->assertTrue(collect($rows)->contains(fn ($r) => $r[1] === $invoice->document_number));
    }

    public function test_detail_rows_have_one_header_row_followed_by_item_rows(): void
    {
        $tax = $this->makeTax();
        [, $delivery] = $this->submittedDelivery(qty: 10, rate: 10000, taxId: $tax->id);
        $invoice = $this->invoiceService->create(['delivery_ids' => [$delivery->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        $invoices = $this->salesReportService->rows([]);
        $rows = $this->salesReportService->detailRows($invoices);

        $this->assertCount(2, $rows); // 1 header + 1 item

        $header = $rows[0];
        $this->assertEquals($invoice->document_number, $header[1]);
        $this->assertEquals($this->customer->customer_code, $header[2]);
        $this->assertEquals(111000.0, $header[10]); // AMOUNT = grand_total
        $this->assertEquals('PPN11', $header[9]); // T.CODE — single tax across all lines

        $item = $rows[1];
        $this->assertEquals('ITM-1', $item[0]); // ITEM#
        $this->assertEquals(10, $item[5]); // QUANTITY
        $this->assertEquals(0.0, $item[7]); // DISC — no per-line discount exists
        $this->assertEquals(11000.0, $item[8]); // TAX
        $this->assertEquals('PPN11', $item[9]); // T.CODE
        $this->assertEquals(100000.0, $item[10]); // LINE AMOUNT
        $this->assertNull($item[11]); // REFERENCE 1# blank on item rows
    }

    public function test_detail_header_tax_code_blank_when_lines_use_different_taxes(): void
    {
        $taxA = $this->makeTax(['code' => 'PPN11', 'rate' => 11]);
        $taxB = $this->makeTax(['code' => 'BEBAS', 'type' => TaxType::EXEMPT, 'rate' => 0]);

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000, 'tax_id' => $taxA->id],
                ['item_id' => $this->item->id, 'qty' => 3, 'rate' => 10000, 'tax_id' => $taxB->id],
            ],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->approve($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => $salesOrder->items->map(fn ($line) => ['sales_order_item_id' => $line->id, 'qty' => $line->qty])->all(),
        ]);
        $delivery = $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create(['delivery_ids' => [$delivery->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        $invoices = $this->salesReportService->rows([]);
        $rows = $this->salesReportService->detailRows($invoices);

        $header = collect($rows)->first(fn ($r) => $r[1] === $invoice->document_number);
        $this->assertNull($header[9]); // T.CODE blank — lines disagree
    }

    public function test_ids_override_takes_priority_over_filters(): void
    {
        [, $delivery1] = $this->submittedDelivery(qty: 1, rate: 10000);
        [, $delivery2] = $this->submittedDelivery(qty: 1, rate: 20000);
        $invoice1 = $this->invoiceService->create(['delivery_ids' => [$delivery1->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);
        $this->invoiceService->create(['delivery_ids' => [$delivery2->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        // A filter that would normally match both (status omitted = no filter), but ids narrows to just one.
        $invoices = $this->salesReportService->rows(['status' => 'draft'], [$invoice1->id]);

        $this->assertCount(1, $invoices);
        $this->assertEquals($invoice1->id, $invoices->first()->id);
    }

    public function test_export_route_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/invoices/export/sales-report?mode=summary')->assertForbidden();
    }

    public function test_export_route_downloads_a_real_xlsx_whose_cells_match_the_computed_totals(): void
    {
        $tax = $this->makeTax();
        [, $delivery] = $this->submittedDelivery(qty: 10, rate: 10000, taxId: $tax->id);
        $this->invoiceService->create(['delivery_ids' => [$delivery->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($viewer);

        $response = $this->get('/api/v1/invoices/export/sales-report?mode=summary&format=xlsx');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmpPath = tempnam(sys_get_temp_dir(), 'sales-report') . '.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();

        // Row 1 title, row 2 date range, rows 3-4 blank, row 5 company/timestamp, rows 6-7 blank,
        // row 8 column headings, row 9 invoice data, row 10 "Total By Header", rows 11-12 blank,
        // row 13 "TAX SUMMARY", row 14 its own Code/Rate/... header, row 15 the one tax group,
        // row 16 its grand total, row 17 blank, row 18 "Printed By".
        $this->assertEquals('SALES INVOICE LISTING - SUMMARY', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString(' - Base Currency', $sheet->getCell('A2')->getValue());
        $this->assertEquals('EXCL.TAX', $sheet->getCell('F8')->getValue());
        $this->assertEquals(100000, $sheet->getCell('F9')->getValue());
        // Regression: PhpSpreadsheet's fromArray() defaults to loose (==) null comparison and
        // silently skips writing any cell whose value == null — which a real 0 does in PHP.
        // WithStrictNullComparison on the Export class is what keeps this a real 0, not blank.
        $this->assertSame(0.0, $sheet->getCell('G9')->getValue()); // DISC — genuinely zero, must not render blank
        $this->assertEquals(11000, $sheet->getCell('H9')->getValue());
        $this->assertEquals(111000, $sheet->getCell('I9')->getValue());
        $this->assertEquals('Total By Header', $sheet->getCell('E10')->getValue());
        $this->assertEquals(100000, $sheet->getCell('F10')->getValue());
        $this->assertEquals(111000, $sheet->getCell('I10')->getValue());
        $this->assertEquals('TAX SUMMARY', $sheet->getCell('A13')->getValue());
        $this->assertEquals('Code', $sheet->getCell('A14')->getValue());
        $this->assertEquals('PPN11', $sheet->getCell('A15')->getValue());
        $this->assertEquals('11 %', $sheet->getCell('B15')->getValue());
        $this->assertEquals(100000, $sheet->getCell('C15')->getValue());
        $this->assertEquals(11000, $sheet->getCell('D15')->getValue());
        $this->assertEquals(100000, $sheet->getCell('C16')->getValue()); // tax summary grand total
        $this->assertEquals('Printed By :', $sheet->getCell('A18')->getValue());

        unlink($tmpPath);
    }

    public function test_export_route_downloads_a_utf8_bom_csv(): void
    {
        [, $delivery] = $this->submittedDelivery(qty: 1, rate: 10000);
        $this->invoiceService->create(['delivery_ids' => [$delivery->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($viewer);

        $response = $this->get('/api/v1/invoices/export/sales-report?mode=summary&format=csv');
        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content); // UTF-8 BOM
        $this->assertStringContainsString('SALES INVOICE LISTING - SUMMARY', $content);
        $this->assertStringContainsString('TAX SUMMARY', $content);
    }

    public function test_export_route_downloads_a_real_xlsx_for_detail_mode_with_two_heading_rows_and_a_tax_summary(): void
    {
        $tax = $this->makeTax();
        [, $delivery] = $this->submittedDelivery(qty: 10, rate: 10000, taxId: $tax->id);
        $this->invoiceService->create(['delivery_ids' => [$delivery->id], 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString()]);

        Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($viewer);

        $response = $this->get('/api/v1/invoices/export/sales-report?mode=detail&format=xlsx');
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'sales-report') . '.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();

        // Same 7-row header block as Summary, but two heading rows (8 document-level, 9
        // item-level), then the invoice header row (10) and its one item row (11) — no
        // "Total By Header" row for Detail — then blanks, TAX SUMMARY (14), its own header (15),
        // the one tax group (16), grand total (17), blank, "Printed By".
        $this->assertEquals('SALES INVOICE LISTING - DETAIL', $sheet->getCell('A1')->getValue());
        $this->assertEquals('DATE', $sheet->getCell('A8')->getValue());
        $this->assertEquals('ITEM # ', $sheet->getCell('A9')->getValue());
        $this->assertEquals('DELIVERY TO', $sheet->getCell('F8')->getValue());
        $this->assertNull($sheet->getCell('E8')->getValue()); // blank spacer column
        $this->assertEquals('ITM-1', $sheet->getCell('A11')->getValue());
        $this->assertEquals(10, $sheet->getCell('F11')->getValue()); // QUANTITY
        $this->assertSame(0.0, $sheet->getCell('H11')->getValue()); // DISC — always 0, must not render blank (see WithStrictNullComparison)
        $this->assertEquals(11000, $sheet->getCell('I11')->getValue()); // TAX
        $this->assertEquals(100000, $sheet->getCell('K11')->getValue()); // LINE AMOUNT
        $this->assertEquals('TAX SUMMARY', $sheet->getCell('A14')->getValue());
        $this->assertEquals('PPN11', $sheet->getCell('A16')->getValue());
        $this->assertEquals(11000, $sheet->getCell('D16')->getValue());
        $this->assertEquals('Printed By :', $sheet->getCell('A19')->getValue());

        // Both heading rows and both Tax Summary header rows are bold; the title row is not.
        $this->assertTrue($sheet->getStyle('A8')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('A9')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('A14')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('A15')->getFont()->getBold());
        $this->assertFalse($sheet->getStyle('A1')->getFont()->getBold());

        unlink($tmpPath);
    }
}
