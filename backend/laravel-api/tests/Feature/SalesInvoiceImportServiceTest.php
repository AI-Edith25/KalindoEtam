<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\WarehouseType;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\StockLedger;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\Import\SalesInvoiceImportService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises SalesInvoiceImportService end-to-end — see SalesInvoiceHistoryParser/
 * SalesInvoiceImportService docblocks for the file shape and the "no stock/AR/GL" contract.
 */
class SalesInvoiceImportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesInvoiceImportService $service;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);

        $this->service = app(SalesInvoiceImportService::class);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Zak', 'symbol' => 'ZAK']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Test Item', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
    }

    private function csv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";
    }

    private const PREAMBLE = [
        ['SALES INVOICE LISTING - DETAIL'],
        ['31/08/2026 - 30/09/2026 - Base Currency'],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['PT. KALINDO ETAM', '', '', '30/09/2026 15:33:31', '', '', '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['DATE', 'DOCUMENT #', 'CUSTOMER#', 'NAME', '', 'DELIVERY TO', '', 'DISC', 'TAX', 'T.CODE', 'AMOUNT', 'REFERENCE 1 #', 'REFERENCE 2 #'],
        ['ITEM # ', '', 'DESCRIPTION', '', 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC', 'TAX', 'T.CODE', 'LINE AMOUNT', '', ''],
    ];

    private function makeBatch(string $csv, array $resolutions = []): ImportBatch
    {
        $path = 'imports/test-sales-invoice-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        return ImportBatch::query()->create([
            'module' => 'sales-invoice-history',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'test.csv',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['warehouse_id' => $this->warehouse->id],
            'fk_resolutions' => $resolutions !== [] ? $resolutions : null,
        ]);
    }

    public function test_goods_row_creates_a_submitted_invoice_with_no_stock_ar_or_gl_side_effects(): void
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'SI/KE/00001/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 11000, '', 111000, 'SO/KE/1', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 11000, 'PPN-K11(EXC)', 111000, '', ''],
        ]);

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows);

        $invoice = Invoice::query()->where('source_document_number', 'SI/KE/00001/09/2026')->with('items')->firstOrFail();
        $this->assertSame('submitted', $invoice->status->value);
        $this->assertSame('historical_invoice', $invoice->import_source_type);
        $this->assertSame('goods', $invoice->invoice_type->value);
        $this->assertEquals(100000, (float) $invoice->subtotal);
        $this->assertEquals(11000, (float) $invoice->tax_amount);
        $this->assertEquals(111000, (float) $invoice->grand_total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame($this->item->id, $invoice->items->first()->item_id);
        $this->assertEquals(10, $invoice->items->first()->qty);

        $this->assertSame(0, AccountsReceivable::query()->count(), 'AR/GL already backfilled by a separate import — must never be created here');
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, StockLedger::query()->count(), 'must never touch stock');
    }

    public function test_transportation_row_creates_a_freeform_invoice_with_no_item_master_lookup(): void
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'TR/KE/00001/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 0, '', 50000, 'SI/KE/00001/09/2026', ''],
            ['TRANSPORT', '', 'BIAYA TRANSPORT', '', 'ZAK', 5, 10000, 0, 0, 'NON-PPN', 50000, '', ''],
        ]);

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows);

        $invoice = Invoice::query()->where('source_document_number', 'TR/KE/00001/09/2026')->with('items')->firstOrFail();
        $this->assertSame('transportation', $invoice->invoice_type->value);
        $this->assertSame('submitted', $invoice->status->value);
        $this->assertNull($invoice->items->first()->item_id);
        $this->assertSame('BIAYA TRANSPORT', $invoice->items->first()->item_name);
        $this->assertEquals(50000, (float) $invoice->grand_total);
    }

    public function test_unresolved_customer_is_needs_review_not_a_failure(): void
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'SI/KE/00002/09/2026', 'UNKNOWN', 'Unknown Customer', '', '', '', 0, 0, '', 100000, '', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 0, '', 100000, '', ''],
        ]);

        $batch = $this->makeBatch($csv);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(0, $batch->success_rows);
        $this->assertSame(0, $batch->failed_rows, 'unresolved master data is reported as needs_review, never a hard failure');
        $this->assertSame(1, $batch->preview_summary['needs_review_rows']);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_unresolved_item_can_be_mapped_to_an_existing_item(): void
    {
        $otherItem = Item::query()->create(['item_code' => 'ITEM2', 'item_name' => 'Other Item', 'item_group_id' => $this->item->item_group_id, 'uom_id' => $this->item->uom_id, 'standard_rate' => 0]);

        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'SI/KE/00003/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 0, '', 100000, '', ''],
            ['UNKNOWNITEM', '', 'Unknown Item', '', 'ZAK', 10, 10000, 0, 0, '', 100000, '', ''],
        ]);

        $batch = $this->makeBatch($csv, [
            'item' => ['UNKNOWNITEM' => ['action' => 'map', 'target_id' => $otherItem->id]],
        ]);
        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertSame(1, $batch->success_rows);

        $invoice = Invoice::query()->where('source_document_number', 'SI/KE/00003/09/2026')->with('items')->firstOrFail();
        $this->assertSame($otherItem->id, $invoice->items->first()->item_id);
    }

    public function test_duplicate_source_document_number_is_skipped_by_default_but_can_be_created_anyway(): void
    {
        $csv = $this->csv([
            ...self::PREAMBLE,
            ['30/09/2026', 'SI/KE/00004/09/2026', 'CUST1', 'Test Customer', '', '', '', 0, 0, '', 100000, '', ''],
            ['ITEM1', '', 'Test Item', '', 'ZAK', 10, 10000, 0, 0, '', 100000, '', ''],
        ]);

        $first = $this->makeBatch($csv);
        $this->service->import($first);
        $this->assertSame(1, Invoice::query()->where('source_document_number', 'SI/KE/00004/09/2026')->count());

        $again = $this->makeBatch($csv);
        $this->service->import($again);
        $this->assertSame(1, Invoice::query()->where('source_document_number', 'SI/KE/00004/09/2026')->count(), 'skipped by default');

        $proceed = $this->makeBatch($csv, [
            'duplicate' => ['SI/KE/00004/09/2026' => ['action' => 'proceed', 'target_id' => null]],
        ]);
        $this->service->import($proceed);
        $this->assertSame(2, Invoice::query()->where('source_document_number', 'SI/KE/00004/09/2026')->count(), 'explicit override creates it anyway');
    }

    public function test_real_sales_invoice_listing_file_completes_without_crashing(): void
    {
        $realFile = dirname(__DIR__, 4).'/xlsSalesInvoiceListing_Detail.xlsx';

        if (! file_exists($realFile)) {
            $this->markTestSkipped('Real xlsSalesInvoiceListing_Detail.xlsx sample not present in the project root.');
        }

        $path = 'imports/real-sales-invoice.xlsx';
        Storage::disk('local')->put($path, file_get_contents($realFile));

        $batch = ImportBatch::query()->create([
            'module' => 'sales-invoice-history',
            'status' => ImportBatchStatus::QUEUED,
            'original_filename' => 'xlsSalesInvoiceListing_Detail.xlsx',
            'disk' => 'local',
            'file_path' => $path,
            'mapping' => ['warehouse_id' => $this->warehouse->id],
        ]);

        $this->service->import($batch);
        $batch->refresh();

        $this->assertEquals(ImportBatchStatus::COMPLETED, $batch->status, (string) $batch->failure_reason);
        $this->assertGreaterThan(0, $batch->total_rows);
        $this->assertSame(0, $batch->failed_rows, 'unresolved master data is reported as needs_review, never a hard failure');
        $this->assertSame(0, AccountsReceivable::query()->count());
        $this->assertSame(0, StockLedger::query()->count());
    }
}
