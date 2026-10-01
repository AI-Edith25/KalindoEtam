<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ReceiptEntry;
use App\Models\Warehouse;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documentable's duplicate-key normalization hook (shared by every document type that declares a
 * duplicateKeyField(), e.g. ReceiptEntry::reference_number / Invoice::source_document_number) —
 * exercised here via ReceiptEntry since it's writable from both the manual-entry path and the
 * import path, so this proves normalization isn't something only the import services remember to
 * do (see feedback_gate_all_write_paths).
 */
class DocumentSourceNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function makeReceiptEntry(?string $referenceNumber): ReceiptEntry
    {
        $customer = Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Toko Alpha']);

        return ReceiptEntry::query()->create([
            'customer_id' => $customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'reference_number' => $referenceNumber,
            'total_amount' => 10000,
            'payment_method' => 'cash',
        ]);
    }

    public function test_trims_and_uppercases_reference_number_on_create(): void
    {
        $entry = $this->makeReceiptEntry(' or/ke/00001/08/2026 ');

        $this->assertSame('OR/KE/00001/08/2026', $entry->reference_number_normalized);
    }

    public function test_blank_reference_number_normalizes_to_null(): void
    {
        $entry = $this->makeReceiptEntry(null);

        $this->assertNull($entry->reference_number_normalized);
    }

    public function test_renormalizes_on_update(): void
    {
        $entry = $this->makeReceiptEntry('OR/KE/00002/08/2026');

        $entry->update(['reference_number' => ' or/ke/00099/08/2026 ']);

        $this->assertSame('OR/KE/00099/08/2026', $entry->fresh()->reference_number_normalized);
    }

    public function test_defaults_to_manual_source(): void
    {
        $entry = $this->makeReceiptEntry('OR/KE/00003/08/2026');

        $this->assertSame('manual', $entry->source);
        $this->assertNull($entry->import_batch_id);
        $this->assertNull($entry->imported_at);
    }

    /**
     * Cancelling frees the normalized slot so a later import can reuse that legacy number without
     * tripping the unique index — the unique index is effectively "unique among live documents",
     * not "unique forever", while the raw source_document_number is kept for audit/display.
     */
    public function test_cancel_frees_the_normalized_duplicate_key_for_reuse(): void
    {
        $customer = Customer::query()->create(['customer_code' => 'C-0001', 'customer_name' => 'Toko Alpha']);
        $warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => 'main']);

        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'subtotal' => 100000,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => 100000,
            'source_document_number' => 'SI/KE/00001/09/2026',
        ]);
        $invoice->update(['status' => 'submitted']);

        $this->assertSame('SI/KE/00001/09/2026', $invoice->fresh()->source_document_number_normalized);

        $invoice->cancel();

        $this->assertNull($invoice->fresh()->source_document_number_normalized);
        $this->assertSame('SI/KE/00001/09/2026', $invoice->fresh()->source_document_number, 'raw value kept for audit/display');
    }
}
