<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\MiscellaneousItem;
use App\Models\UnitOfMeasurement;
use App\Services\Import\SalesInvoiceDetailBackfillService;
use Database\Seeders\ChartOfAccountsSeeder;
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
        $this->seed(ChartOfAccountsSeeder::class);
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

    /** Found 2026-10-12 against the real export: this file's own per-line DISC column (item
        sub-header col index 7) is where the real discount lives -- the header row's own DISC
        column is 0 on every Goods document observed. A formula that only reads the header DISC
        (as the old, now-removed SalesInvoiceImportService's totals() also did) silently drops
        every item's discount, overstating the recomputed grand_total by exactly the sum of the
        item DISC values -- confirmed against 12 real documents that all failed
        skipped_amount_mismatch for this exact reason. */
    public function test_commit_subtracts_each_items_own_disc_column_not_just_the_header_disc(): void
    {
        $account = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        MiscellaneousItem::query()->create([
            'misc_code' => 'TOKKA_1_2KG', 'description' => 'Paku Tokka 1 inci @ 2 KG', 'rate' => 0,
            'charge_type' => 'addition', 'unit_cost' => 0,
            'sales_account_id' => $account->id, 'purchase_account_id' => $account->id,
        ]);
        $invoice = $this->makeOutstandingBillsInvoice('SI/KE/08657/09/2026', 'goods', 499800.00);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['28/09/2026', 'SI/KE/08657/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 499800, '', ''],
            ['TOKKA_1_2KG', '', 'PAKU TOKKA 1 inci @ 2 KG', '', 'DUS', 1, '"459459,46"', '"9189,19"', '"49529,73"', 'PPN-K11(EXC)', 499800],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $path = $this->writeFixture($csv);
        $preflight = $this->service->preflight($path, 'csv');

        $this->assertSame(1, $preflight['will_update'], 'must reconcile once the item disc is subtracted, not be skipped_amount_mismatch');
        $this->assertSame([], $preflight['skipped_amount_mismatch']);

        $this->service->commit($path, 'csv');

        $invoice->refresh();
        $this->assertEquals(459459.46, (float) $invoice->subtotal);
        $this->assertEquals(9189.19, (float) $invoice->discount_amount, 'the item-level disc must land in the Invoice header, not be dropped');
        $this->assertEquals(49529.73, (float) $invoice->tax_amount);
        $this->assertEquals(499800.00, (float) $invoice->grand_total, 'grand_total must stay untouched');
    }

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

    public function test_preflight_skips_a_document_number_not_found_among_outstanding_bills_archive_invoices(): void
    {
        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/99999/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame(['document_number' => 'SI/KE/99999/09/2026', 'customer_code' => 'C-1364'], $preflight['skipped_not_found'][0]);
    }

    public function test_preflight_skips_when_the_files_customer_does_not_match_the_invoices_customer(): void
    {
        $otherCustomer = Customer::query()->create(['customer_code' => 'C-9999', 'customer_name' => 'Someone Else']);
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 500000);
        // Reassign to a different customer than the file will claim.
        Invoice::query()->where('source_document_number', 'SI/KE/08111/09/2026')->update(['customer_id' => $otherCustomer->id]);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('SI/KE/08111/09/2026', $preflight['skipped_customer_mismatch'][0]['document_number']);
        $this->assertSame('C-1364', $preflight['skipped_customer_mismatch'][0]['file_customer_code']);
        $this->assertSame('C-9999', $preflight['skipped_customer_mismatch'][0]['invoice_customer_code']);
    }

    public function test_preflight_skips_when_the_files_document_type_does_not_match_the_invoices_type(): void
    {
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'transportation', 500000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            // Deliberately SI/ (goods), not TR/: type-mismatch must fire before item lookup runs,
            // or the unresolved MISC item code would short-circuit this test for the wrong reason.
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('goods', $preflight['skipped_type_mismatch'][0]['file_type']);
        $this->assertSame('transportation', $preflight['skipped_type_mismatch'][0]['invoice_type']);
    }

    public function test_preflight_skips_a_goods_group_with_an_unresolvable_item_code(): void
    {
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 500000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['NOPE-999', '', 'Barang Entah', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('SI/KE/08111/09/2026', $preflight['skipped_item_unresolved'][0]['document_number']);
    }

    public function test_preflight_skips_when_the_recomputed_total_is_beyond_tolerance(): void
    {
        // Deliberately TR/ (transportation) on both sides: Transportation skips item lookup
        // entirely, so the unresolved MISC item code never gets in the way of reaching the
        // amount check this test is actually exercising.
        $this->makeOutstandingBillsInvoice('TR/KE/08111/09/2026', 'transportation', 999999);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'TR/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 999999, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('TR/KE/08111/09/2026', $preflight['skipped_amount_mismatch'][0]['document_number']);
        $this->assertEquals(500000.0, $preflight['skipped_amount_mismatch'][0]['computed_amount']);
    }

    public function test_commit_resolves_an_unmatched_item_code_against_miscellaneous_item(): void
    {
        $account = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $misc = MiscellaneousItem::query()->create([
            'misc_code' => 'TRANSPORT', 'description' => 'Ongkos Angkut', 'rate' => 0,
            'charge_type' => 'addition', 'unit_cost' => 0,
            'sales_account_id' => $account->id, 'purchase_account_id' => $account->id,
        ]);
        $invoice = $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 150000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 150000, '', ''],
            ['TRANSPORT', '', 'Ongkos Angkut', '', 'UNIT', 1, 150000, 0, 0, '', 150000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $this->service->commit($this->writeFixture($csv), 'csv');

        $line = $invoice->items()->sole();
        $this->assertNull($line->item_id);
        $this->assertSame('Ongkos Angkut', $line->item_name);
    }

    public function test_commit_writes_freeform_items_for_a_transportation_group_without_any_item_lookup(): void
    {
        $invoice = $this->makeOutstandingBillsInvoice('TR/KE/07644/10/2026', 'transportation', 2000000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['10/10/2026', 'TR/KE/07644/10/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 2000000, '', ''],
            // col 0 ("ITEM #") is always the literal code "TRANSPORT" on a TR document, never a
            // real Item code — no Item/MiscellaneousItem lookup is ever attempted for it (see
            // SalesInvoiceHistoryParser's own docblock + this service's classifyOne()).
            ['TRANSPORT', '', 'Angkutan Semen ke Gudang', '', 'UNIT', 1, 2000000, 0, 0, '', 2000000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $result = $this->service->commit($this->writeFixture($csv), 'csv');

        $this->assertSame(1, $result['updated']);
        $line = $invoice->refresh()->items()->sole();
        $this->assertNull($line->item_id);
        $this->assertSame('Angkutan Semen ke Gudang', $line->item_name);
        $this->assertEquals(2000000, (float) $line->amount);
        $this->assertEquals(2000000, (float) $invoice->grand_total, 'grand_total must stay untouched');
    }

    public function test_commit_never_touches_an_invoice_that_is_not_from_the_outstanding_bills_archive(): void
    {
        $manual = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-12', 'due_date' => '2026-10-12',
            'subtotal' => 150000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 150000,
            'source_document_number' => 'SI/KE/08111/09/2026',
        ]);
        $originalItem = InvoiceItem::query()->create([
            'invoice_id' => $manual->id, 'item_name' => 'Manually Entered Line',
            'uom' => null, 'rate' => 150000, 'qty' => 1, 'qty_category' => 'weight', 'amount' => 150000,
        ]);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 150000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 150000, 0, 0, '', 150000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $result = $this->service->commit($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $result['updated']);
        $this->assertNotNull(InvoiceItem::query()->find($originalItem->id), 'the manually-entered line must survive untouched');
    }
}
