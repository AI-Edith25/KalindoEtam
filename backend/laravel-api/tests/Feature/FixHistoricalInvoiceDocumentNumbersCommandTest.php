<?php

namespace Tests\Feature;

use App\Enums\AccountsReceivableStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\WarehouseType;
use App\Models\AccountsReceivable;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FixHistoricalInvoiceDocumentNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
    }

    private function createCustomer(): Customer
    {
        return Customer::query()->create(['customer_code' => 'C-'.Str::random(6), 'customer_name' => 'Test Customer']);
    }

    /** Mirrors what SalesInvoiceImportService used to write before resolveDocumentNumber() existed — a mismatched, auto-generated document_number. */
    private function createMismatchedHistoricalInvoice(string $legacyNumber, ?string $currentDocumentNumber = null): Invoice
    {
        return Invoice::query()->create([
            'document_number' => $currentDocumentNumber ?? 'AUTO-'.Str::random(8),
            'customer_id' => $this->createCustomer()->id,
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'status' => DocumentStatus::SUBMITTED->value,
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-09-15',
            'subtotal' => 100000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 100000,
            'source_document_number' => $legacyNumber,
            'import_source_type' => 'historical_invoice',
        ]);
    }

    public function test_replaces_document_number_with_the_legacy_number(): void
    {
        $invoice = $this->createMismatchedHistoricalInvoice('TR/KE/06981/09/2026');

        $this->artisan('invoices:fix-historical-document-numbers')->assertExitCode(0);

        $this->assertSame('TR/KE/06981/09/2026', $invoice->refresh()->document_number);
    }

    public function test_repairs_an_already_created_accounts_receivable_reference_number(): void
    {
        $invoice = $this->createMismatchedHistoricalInvoice('TR/KE/06981/09/2026', 'TR/KE/00501/10/2026');
        $ar = AccountsReceivable::query()->create([
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'reference_number' => 'TR/KE/00501/10/2026',
            'amount' => 100000,
            'paid_amount' => 0,
            'due_date' => $invoice->due_date,
            'status' => AccountsReceivableStatus::UNPAID,
        ]);

        $this->artisan('invoices:fix-historical-document-numbers')->assertExitCode(0);

        $this->assertSame('TR/KE/06981/09/2026', $invoice->refresh()->document_number);
        $this->assertSame('TR/KE/06981/09/2026', $ar->refresh()->reference_number);
    }

    public function test_skips_a_legacy_number_already_owned_by_another_invoice(): void
    {
        $owner = $this->createCustomer();
        Invoice::query()->create([
            'document_number' => 'TR/KE/06981/09/2026',
            'customer_id' => $owner->id,
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'status' => DocumentStatus::SUBMITTED->value,
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-09-15',
            'subtotal' => 1,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'grand_total' => 1,
        ]);

        $invoice = $this->createMismatchedHistoricalInvoice('TR/KE/06981/09/2026');
        $originalNumber = $invoice->document_number;

        $this->artisan('invoices:fix-historical-document-numbers')->assertExitCode(0);

        $this->assertSame($originalNumber, $invoice->refresh()->document_number, 'left untouched — the legacy number is already taken');
    }

    public function test_leaves_an_already_correct_invoice_untouched(): void
    {
        $invoice = $this->createMismatchedHistoricalInvoice('TR/KE/06981/09/2026', 'TR/KE/06981/09/2026');

        $this->artisan('invoices:fix-historical-document-numbers')->assertExitCode(0);

        $this->assertSame('TR/KE/06981/09/2026', $invoice->refresh()->document_number);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $invoice = $this->createMismatchedHistoricalInvoice('TR/KE/06981/09/2026');
        $originalNumber = $invoice->document_number;

        $this->artisan('invoices:fix-historical-document-numbers', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame($originalNumber, $invoice->refresh()->document_number);
    }
}
