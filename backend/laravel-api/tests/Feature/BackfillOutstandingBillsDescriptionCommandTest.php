<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simulates the pre-fix database: InvoiceItem rows CustomerOutstandingArchiveImportService
 * created before 2026-10-12 with item_name "Outstanding Balance — {ref_no}" (see that service's
 * own createInvoicesFromLines(), now fixed to store just the ref_no).
 */
class BackfillOutstandingBillsDescriptionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);
    }

    private function makeOutstandingBillsInvoiceLine(string $refNo): InvoiceItem
    {
        $invoice = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-30',
            'subtotal' => 150000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 150000,
            'source_document_number' => $refNo,
            'import_source_type' => 'outstanding_bills_archive',
        ]);

        return InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_name' => "Outstanding Balance — {$refNo}",
            'uom' => null, 'rate' => 150000, 'qty' => 1, 'qty_category' => 'weight', 'amount' => 150000,
        ]);
    }

    public function test_commit_strips_the_old_prefix(): void
    {
        $item = $this->makeOutstandingBillsInvoiceLine('SI/KE/03827/05/2026');

        $this->artisan('sales-invoice:backfill-outstanding-bills-description')->assertExitCode(0);

        $this->assertSame('SI/KE/03827/05/2026', $item->fresh()->item_name);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $item = $this->makeOutstandingBillsInvoiceLine('SI/KE/03827/05/2026');

        $this->artisan('sales-invoice:backfill-outstanding-bills-description', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('Outstanding Balance — SI/KE/03827/05/2026', $item->fresh()->item_name);
    }

    public function test_leaves_other_invoice_types_item_name_alone(): void
    {
        $manual = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-30',
            'subtotal' => 50000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 50000,
        ]);
        $item = InvoiceItem::query()->create([
            'invoice_id' => $manual->id,
            'item_name' => 'Outstanding Balance — not really a ref_no, just a coincidental name',
            'uom' => null, 'rate' => 50000, 'qty' => 1, 'qty_category' => 'weight', 'amount' => 50000,
        ]);

        $this->artisan('sales-invoice:backfill-outstanding-bills-description')->assertExitCode(0);

        $this->assertSame('Outstanding Balance — not really a ref_no, just a coincidental name', $item->fresh()->item_name);
    }
}
