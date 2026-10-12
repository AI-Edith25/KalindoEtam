<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Services\Import\SalesInvoiceDetailBackfillService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises SalesInvoiceDetailBackfillService — see this file's own docblock and
 * docs/superpowers/specs/2026-10-12-sales-invoice-detail-backfill-design.md for the matching/
 * validation rules. Invoices are built directly via Eloquent to simulate the state
 * CustomerOutstandingArchiveImportService already leaves behind (one generic InvoiceItem line,
 * import_source_type = 'outstanding_bills_archive').
 */
class SalesInvoiceDetailBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesInvoiceDetailBackfillService $service;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(SalesInvoiceDetailBackfillService::class);
        $this->customer = Customer::query()->create(['customer_code' => 'C-1364', 'customer_name' => 'TK. DENDYA']);
    }

    /** @return Invoice the invoice with its one generic InvoiceItem line, as the Outstanding Bills import leaves it */
    private function makeOutstandingBillsInvoice(string $documentNumber, string $type, float $grandTotal): Invoice
    {
        $invoice = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => $type,
            'invoice_date' => '2026-09-12',
            'due_date' => '2026-10-12',
            'subtotal' => $grandTotal, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $grandTotal,
            'source_document_number' => $documentNumber,
            'import_source_type' => 'outstanding_bills_archive',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_name' => $documentNumber,
            'uom' => null, 'rate' => $grandTotal, 'qty' => 1, 'qty_category' => 'weight', 'amount' => $grandTotal,
        ]);

        return $invoice;
    }

    /** @return string path to a CSV fixture shaped like "Sales Invoice Listing - Detail" */
    private function writeFixture(string $content): string
    {
        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    private function csv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";
    }

    private const HEADER_ROW = ['DATE', 'DOCUMENT #', 'CUSTOMER#', 'NAME', '', 'DELIVERY TO', '', 'DISC', 'TAX', 'T.CODE', 'AMOUNT', 'REFERENCE 1 #', 'REFERENCE 2 #'];

    private const ITEM_SUB_HEADER = ['ITEM #', '', 'DESCRIPTION', '', 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC', 'TAX', 'T.CODE', 'LINE AMOUNT'];

    public function test_preflight_matches_and_will_update_a_goods_invoice_with_a_real_item(): void
    {
        $item = Item::query()->create([
            'item_code' => 'SC-50', 'item_name' => 'Semen Conch 50kg',
            'item_group_id' => ItemGroup::query()->create(['name' => 'Semen'])->id,
            'uom_id' => UnitOfMeasurement::query()->create(['name' => 'ZAK'])->id,
            'qty_category' => 'weight', 'standard_rate' => 0,
        ]);

        $invoice = $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 1050000.28);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [],
            [],
            ['PT. KALINDO ETAM'],
            [],
            [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, '"104.054,08"', '', '"1.050.000,28"', '', ''],
            ['SC-50', '', 'Semen Conch 50kg', '', 'ZAK', 30, '"31.531,54"', 0, '"104.054,08"', '', '"1.050.000,28"'],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $path = $this->writeFixture($csv);
        $preflight = $this->service->preflight($path, 'csv');

        $this->assertSame(1, $preflight['will_update']);
        $this->assertSame([], $preflight['skipped_not_found']);
        $this->assertSame([], $preflight['skipped_customer_mismatch']);
        $this->assertSame([], $preflight['skipped_type_mismatch']);
        $this->assertSame([], $preflight['skipped_item_unresolved']);
        $this->assertSame([], $preflight['skipped_amount_mismatch']);

        $result = $this->service->commit($path, 'csv');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(['SI/KE/08111/09/2026'], $result['document_numbers']);

        $invoice->refresh();
        $this->assertEquals(945946.2, (float) $invoice->subtotal);
        $this->assertEquals(104054.08, (float) $invoice->tax_amount);
        $this->assertEquals(0, (float) $invoice->discount_amount);
        $this->assertEquals(1050000.28, (float) $invoice->grand_total, 'grand_total must stay untouched, already validated');

        $line = $invoice->items()->sole();
        $this->assertSame($item->id, $line->item_id);
        $this->assertSame('Semen Conch 50kg', $line->item_name);
        $this->assertSame('ZAK', $line->uom);
        $this->assertEquals(30, (float) $line->qty);
        $this->assertEquals(31531.54, (float) $line->rate);
        $this->assertEquals(945946.2, (float) $line->amount);
        $this->assertEquals(104054.08, (float) $line->tax_amount);
    }
}
