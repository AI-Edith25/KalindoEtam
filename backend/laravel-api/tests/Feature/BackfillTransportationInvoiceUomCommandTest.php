<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Enums\MiscellaneousChargeType;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MiscellaneousItem;
use App\Models\UnitOfMeasurement;
use App\Services\InvoiceService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simulates the pre-fix database: Transportation Invoice lines created before UOM was wired
 * through (see InvoiceService::createTransportation()) all have uom = null, regardless of
 * document status. Builds those lines directly with uom forced back to null (create() itself
 * already stores it correctly now), to reproduce the old broken state for the command to repair.
 */
class BackfillTransportationInvoiceUomCommandTest extends TestCase
{
    use RefreshDatabase;

    protected InvoiceService $invoiceService;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->invoiceService = app(InvoiceService::class);
        $this->customer = Customer::query()->create(['customer_code' => 'CUST1', 'customer_name' => 'Test Customer']);
    }

    private function miscItem(string $code, string $description, ?UnitOfMeasurement $uom): MiscellaneousItem
    {
        $account = ChartOfAccount::query()->where('code', '1100')->firstOrFail();

        return MiscellaneousItem::query()->create([
            'misc_code' => $code,
            'description' => $description,
            'rate' => 25000,
            'uom_id' => $uom?->id,
            'charge_type' => MiscellaneousChargeType::ADDITION,
            'unit_cost' => 0,
            'sales_account_id' => $account->id,
            'purchase_account_id' => $account->id,
        ]);
    }

    /** @return array{0: Invoice, 1: string} the invoice and its one item's id */
    private function createTransportationInvoiceLine(string $description, string $status = 'draft'): array
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'items' => [['description' => $description, 'qty' => 3, 'rate' => 25000]],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        if ($status === 'submitted') {
            $this->invoiceService->submit($invoice);
        }

        return [$invoice, $invoice->items->first()->id];
    }

    public function test_backfills_uom_from_a_uniquely_matching_misc_item_on_a_draft_invoice(): void
    {
        $uom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $this->miscItem('MISC1', 'Ongkos Angkut Semen 50kg - Rute A', $uom);

        [, $itemId] = $this->createTransportationInvoiceLine('Ongkos Angkut Semen 50kg - Rute A', 'draft');

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertSame('Trip', Invoice::query()->whereHas('items')->first()->items->firstWhere('id', $itemId)->uom);
    }

    public function test_backfills_uom_on_a_submitted_invoice_too(): void
    {
        $uom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $this->miscItem('MISC1', 'Ongkos Angkut Semen 50kg - Rute A', $uom);

        [$invoice, $itemId] = $this->createTransportationInvoiceLine('Ongkos Angkut Semen 50kg - Rute A', 'submitted');

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertSame('Trip', $invoice->items()->whereKey($itemId)->first()->uom);
    }

    public function test_match_is_case_insensitive_and_trims_whitespace(): void
    {
        $uom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $this->miscItem('MISC1', '  Ongkos Angkut Semen 50kg - Rute A  ', $uom);

        [, $itemId] = $this->createTransportationInvoiceLine('ongkos angkut semen 50kg - rute a');

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertSame('Trip', \App\Models\InvoiceItem::query()->whereKey($itemId)->first()->uom);
    }

    public function test_leaves_a_line_untouched_when_no_misc_item_matches_its_description(): void
    {
        [, $itemId] = $this->createTransportationInvoiceLine('No such service anywhere');

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertNull(\App\Models\InvoiceItem::query()->whereKey($itemId)->first()->uom);
    }

    public function test_leaves_a_line_untouched_when_the_description_is_ambiguous(): void
    {
        $tripUom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $kgUom = UnitOfMeasurement::query()->create(['name' => 'Kg', 'symbol' => 'KG']);
        $this->miscItem('MISC1', 'Ongkos Angkut Semen 50kg - Rute A', $tripUom);
        $this->miscItem('MISC2', 'Ongkos Angkut Semen 50kg - Rute A', $kgUom);

        [, $itemId] = $this->createTransportationInvoiceLine('Ongkos Angkut Semen 50kg - Rute A');

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertNull(\App\Models\InvoiceItem::query()->whereKey($itemId)->first()->uom);
    }

    public function test_dry_run_reports_but_does_not_save(): void
    {
        $uom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $this->miscItem('MISC1', 'Ongkos Angkut Semen 50kg - Rute A', $uom);

        [, $itemId] = $this->createTransportationInvoiceLine('Ongkos Angkut Semen 50kg - Rute A');

        $this->artisan('invoices:backfill-transportation-uom', ['--dry-run' => true])->assertExitCode(0);

        $this->assertNull(\App\Models\InvoiceItem::query()->whereKey($itemId)->first()->uom);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $uom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $this->miscItem('MISC1', 'Ongkos Angkut Semen 50kg - Rute A', $uom);

        [, $itemId] = $this->createTransportationInvoiceLine('Ongkos Angkut Semen 50kg - Rute A');

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);
        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertSame('Trip', \App\Models\InvoiceItem::query()->whereKey($itemId)->first()->uom);
    }

    public function test_a_line_already_created_with_uom_is_left_as_is_and_not_recounted(): void
    {
        $uom = UnitOfMeasurement::query()->create(['name' => 'Trip', 'symbol' => 'TRIP']);
        $this->miscItem('MISC1', 'Ongkos Angkut Semen 50kg - Rute A', $uom);

        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'items' => [['description' => 'Ongkos Angkut Semen 50kg - Rute A', 'qty' => 3, 'rate' => 25000, 'uom' => 'Trip']],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->artisan('invoices:backfill-transportation-uom')->assertExitCode(0);

        $this->assertSame('Trip', $invoice->items->first()->uom);
    }
}
