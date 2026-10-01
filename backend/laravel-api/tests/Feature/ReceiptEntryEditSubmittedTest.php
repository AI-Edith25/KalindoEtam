<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\User;
use App\Services\ReceiptEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A submitted Official Receipt (ReceiptEntry) can now be corrected instead of being locked forever — same "allow it, reverse+repost" policy as the Goods Receipt confirmed-edit precedent. */
class ReceiptEntryEditSubmittedTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;
    protected ChartOfAccount $cashAccount;
    protected ChartOfAccount $altCashAccount;
    protected ReceiptEntryService $receiptEntryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $this->cashAccount = ChartOfAccount::query()->create(['code' => '1100A', 'name' => 'Bank A', 'account_type' => 'asset', 'is_cash_bank' => true]);
        $this->altCashAccount = ChartOfAccount::query()->create(['code' => '1100B', 'name' => 'Bank B', 'account_type' => 'asset', 'is_cash_bank' => true]);

        $user = User::factory()->create();
        foreach (['finance.incoming_payment.create', 'finance.incoming_payment.update', 'finance.incoming_payment.view', 'finance.payment_allocation.create'] as $permission) {
            Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo(['finance.incoming_payment.create', 'finance.incoming_payment.update', 'finance.incoming_payment.view', 'finance.payment_allocation.create']);
        Sanctum::actingAs($user);

        $this->receiptEntryService = app(ReceiptEntryService::class);
    }

    protected function createSubmittedReceipt(float $total = 100000): ReceiptEntry
    {
        $receipt = $this->receiptEntryService->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id,
            'total_amount' => $total,
        ]);

        return $this->receiptEntryService->submit($receipt);
    }

    public function test_submitted_receipt_amount_can_be_corrected_and_journal_is_reposted(): void
    {
        $receipt = $this->createSubmittedReceipt(100000);

        $response = $this->putJson("/api/v1/receipt-entries/{$receipt->id}", ['total_amount' => 150000]);

        $response->assertOk();
        $response->assertJsonPath('data.total_amount', '150000.00');

        // original (now reversed) + its reversal + the repost.
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertDatabaseHas('journal_entries', [
            'reference_type' => $receipt->getMorphClass(),
            'reference_id' => $receipt->id,
            'reversed_by_id' => null,
        ]);
    }

    public function test_submitted_receipt_header_only_edit_does_not_touch_the_journal(): void
    {
        $receipt = $this->createSubmittedReceipt(100000);

        $response = $this->putJson("/api/v1/receipt-entries/{$receipt->id}", ['remarks' => 'Corrected note']);

        $response->assertOk();
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_submitted_receipt_amount_cannot_drop_below_already_allocated_amount(): void
    {
        $receipt = $this->createSubmittedReceipt(100000);
        // Simulates a prior allocation without needing a full Invoice/AR fixture graph —
        // the guard under test only reads ReceiptEntry.allocated_amount.
        $receipt->forceFill(['allocated_amount' => 60000])->save();

        $response = $this->putJson("/api/v1/receipt-entries/{$receipt->id}", ['total_amount' => 50000]);

        $response->assertUnprocessable();
    }

    public function test_cancelled_receipt_cannot_be_edited(): void
    {
        $receipt = $this->createSubmittedReceipt(100000);
        $receipt->forceFill(['status' => 'cancelled'])->save();

        $response = $this->putJson("/api/v1/receipt-entries/{$receipt->id}", ['remarks' => 'nope']);

        $response->assertUnprocessable();
    }
}
