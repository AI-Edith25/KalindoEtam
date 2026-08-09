<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentTimeline;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\JournalEntry;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountsReceivableService;
use App\Services\CreditNoteService;
use App\Services\DeliveryService;
use App\Services\InvoiceChangeRequestService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Enums\CreditNoteReason;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvoiceChangeRequestTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected InvoiceChangeRequestService $changeRequestService;
    protected CreditNoteService $creditNoteService;
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
        $this->changeRequestService = app(InvoiceChangeRequestService::class);
        $this->creditNoteService = app(CreditNoteService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1',
            'item_name' => 'Widget',
            'item_group_id' => $itemGroup->id,
            'uom_id' => $uom->id,
            'standard_rate' => 10000,
        ]);

        app(\App\Services\StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouse->id,
            transactionType: StockTransactionType::IN,
            voucherType: StockVoucherType::STOCK_IN,
            voucherId: (string) Str::uuid(),
            qtyChange: 100,
            postingDatetime: now(),
        );
    }

    protected function submittedInvoice(int $qty = 10, float $rate = 20000, InvoiceType $type = InvoiceType::TRANSPORTATION): Invoice
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => $qty, 'rate' => $rate]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => $qty]],
        ]);
        $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_type' => $type->value,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        return $this->invoiceService->submit($invoice);
    }

    protected function actingAsRequester(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_request_blocked_for_non_transportation_invoice(): void
    {
        $invoice = $this->submittedInvoice(type: InvoiceType::GOODS);
        $this->actingAsRequester();

        $this->expectException(BusinessException::class);
        $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
    }

    public function test_request_blocked_for_non_submitted_invoice(): void
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 20000]],
        ]);
        $this->approveDocument($salesOrder);
        $this->salesOrderService->submit($salesOrder);
        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $this->deliveryService->submit($delivery);
        $draftInvoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->actingAsRequester();
        $this->expectException(BusinessException::class);
        $this->changeRequestService->create(['invoice_id' => $draftInvoice->id, 'request_reason' => 'Rate correction']);
    }

    public function test_request_blocked_once_invoice_has_a_payment_applied(): void
    {
        $invoice = $this->submittedInvoice(); // grand_total 200000
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        app(AccountsReceivableService::class)->settle($accountsReceivable, 50000);

        $this->actingAsRequester();
        $this->expectException(BusinessException::class);
        $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
    }

    public function test_request_blocked_once_invoice_has_a_credit_note_applied(): void
    {
        $invoice = $this->submittedInvoice(); // grand_total 200000
        $invoiceItem = $invoice->items->first();
        $creditNote = $this->creditNoteService->create([
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => CreditNoteReason::PRICE_ADJUSTMENT->value,
            'items' => [['invoice_item_id' => $invoiceItem->id, 'amount' => 20000]],
        ]);
        $this->creditNoteService->submit($creditNote);

        $this->actingAsRequester();
        $this->expectException(BusinessException::class);
        $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
    }

    public function test_reject_leaves_invoice_locked_and_untouched(): void
    {
        $invoice = $this->submittedInvoice();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);

        $approver = User::factory()->create();
        $this->actingAs($approver);
        $rejected = $this->changeRequestService->reject($changeRequest, 'Not justified.');

        $this->assertEquals(ApprovalStatus::REJECTED, $rejected->status);
        $this->assertEquals($approver->id, $rejected->decided_by_id);
        $invoice->refresh();
        $this->assertEquals(200000, (float) $invoice->grand_total);
        $this->assertDatabaseCount('journal_entries', 1); // only the original submit-time JE
    }

    public function test_apply_nominal_blocked_while_request_is_still_pending(): void
    {
        $invoice = $this->submittedInvoice();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $invoiceItem = $invoice->items->first();

        $this->expectException(BusinessException::class);
        $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 25000]]);
    }

    public function test_zero_delta_apply_is_rejected(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000);
        $invoiceItem = $invoice->items->first();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $this->changeRequestService->approve($changeRequest);

        $this->expectException(BusinessException::class);
        $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 20000]]); // unchanged
    }

    public function test_approved_increase_recomputes_nominal_and_posts_delta_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $originalJournalId = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->value('id');
        $invoiceItem = $invoice->items->first();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $changeRequest = $this->changeRequestService->approve($changeRequest, 'Approved.');
        $this->assertEquals(ApprovalStatus::APPROVED, $changeRequest->status);
        $this->assertNull($changeRequest->consumed_at);

        $updatedInvoice = $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 25000]]);

        $this->assertEquals(250000, (float) $updatedInvoice->subtotal);
        $this->assertEquals(250000, (float) $updatedInvoice->grand_total);
        $this->assertEquals(25000, (float) $updatedInvoice->items->first()->rate);
        $this->assertEquals(250000, (float) $updatedInvoice->items->first()->amount);

        $this->assertNotNull($changeRequest->fresh()->consumed_at);

        $deltaJournal = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->where('id', '!=', $originalJournalId)->firstOrFail();
        $lines = $deltaJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(50000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->debit);
        $this->assertEquals(50000, (float) $lines->firstWhere('chartOfAccount.code', '4000')->credit);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(250000, (float) $accountsReceivable->amount);
    }

    public function test_approved_decrease_recomputes_nominal_and_posts_swapped_delta_journal(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $originalJournalId = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->value('id');
        $invoiceItem = $invoice->items->first();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $this->changeRequestService->approve($changeRequest);

        $updatedInvoice = $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 15000]]);

        $this->assertEquals(150000, (float) $updatedInvoice->grand_total);

        $deltaJournal = JournalEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoice->id)->where('id', '!=', $originalJournalId)->firstOrFail();
        $lines = $deltaJournal->lines()->with('chartOfAccount')->get();
        $this->assertEquals(50000, (float) $lines->firstWhere('chartOfAccount.code', '1200')->credit);
        $this->assertEquals(50000, (float) $lines->firstWhere('chartOfAccount.code', '4000')->debit);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail()->fresh();
        $this->assertEquals(150000, (float) $accountsReceivable->amount);
    }

    public function test_ar_guard_is_re_checked_at_apply_time(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000);
        $invoiceItem = $invoice->items->first();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $this->changeRequestService->approve($changeRequest);

        // A payment lands after approval, before the edit is applied.
        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        app(AccountsReceivableService::class)->settle($accountsReceivable, 50000);

        $this->expectException(BusinessException::class);
        $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 25000]]);
    }

    public function test_second_apply_after_consumption_fails(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000);
        $invoiceItem = $invoice->items->first();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $this->changeRequestService->approve($changeRequest);
        $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 25000]]);

        $this->expectException(BusinessException::class);
        $this->changeRequestService->applyNominal($changeRequest->fresh(), [['id' => $invoiceItem->id, 'rate' => 30000]]);
    }

    public function test_all_four_transitions_are_recorded_to_document_timeline_and_audit_log(): void
    {
        $invoice = $this->submittedInvoice(qty: 10, rate: 20000); // grand_total 200000
        $invoiceItem = $invoice->items->first();
        $this->actingAsRequester();
        $changeRequest = $this->changeRequestService->create(['invoice_id' => $invoice->id, 'request_reason' => 'Rate correction']);
        $this->changeRequestService->approve($changeRequest, 'Approved.');
        $this->changeRequestService->applyNominal($changeRequest, [['id' => $invoiceItem->id, 'rate' => 25000]]);

        $timelineActions = DocumentTimeline::query()->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->pluck('action')->all();
        $this->assertContains('nominal_change_requested', $timelineActions);
        $this->assertContains('nominal_change_approved', $timelineActions);
        $this->assertContains('nominal_changed', $timelineActions);

        $changedEntry = DocumentTimeline::query()->where('subject_id', $invoice->id)->where('action', 'nominal_changed')->firstOrFail();
        $this->assertEquals(200000, (float) $changedEntry->properties['old_grand_total']);
        $this->assertEquals(250000, (float) $changedEntry->properties['new_grand_total']);

        $auditActions = AuditLog::query()->where('module', 'invoice')->pluck('action')->all();
        $this->assertContains('nominal_change_requested', $auditActions);
        $this->assertContains('nominal_change_approved', $auditActions);
        $this->assertContains('nominal_changed', $auditActions);

        // Reject path recorded too, on a fresh invoice.
        $secondInvoice = $this->submittedInvoice(qty: 5, rate: 20000);
        $this->actingAsRequester();
        $secondRequest = $this->changeRequestService->create(['invoice_id' => $secondInvoice->id, 'request_reason' => 'Second look']);
        $this->changeRequestService->reject($secondRequest, 'No.');

        $this->assertDatabaseHas('document_timelines', ['subject_id' => $secondInvoice->id, 'action' => 'nominal_change_rejected']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'invoice', 'action' => 'nominal_change_rejected']);
    }
}
