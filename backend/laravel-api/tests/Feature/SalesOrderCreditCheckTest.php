<?php

namespace Tests\Feature;

use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Sales Order credit/overdue block — see CustomerCreditService and SalesOrderService::enforceCreditCheck(). */
class SalesOrderCreditCheckTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
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

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
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

    /** Full SO -> Delivery -> Invoice -> submit cycle, bypassing the credit gate itself as fixture setup. */
    protected function submittedInvoiceWithDueDate(string $dueDate, float $rate = 20000): void
    {
        $this->actingAsCreditOverride();

        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => $rate]],
            'override_credit_block' => true,
            'override_reason' => 'Fixture setup.',
        ]);
        $this->approveDocument($salesOrder);

        $this->actingAsCreditOverride();
        $this->salesOrderService->submit($salesOrder, true, 'Fixture setup.');

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $salesOrder->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(),
            'due_date' => $dueDate,
            'items' => [['sales_order_item_id' => $salesOrder->items->first()->id, 'qty' => 5]],
        ]);
        $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_id' => $delivery->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => $dueDate,
        ]);
        $this->invoiceService->submit($invoice);
    }

    protected function newOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 50000]],
        ], $overrides);
    }

    public function test_normal_customer_can_create_sales_order(): void
    {
        $salesOrder = $this->salesOrderService->create($this->newOrderPayload());

        $this->assertNotNull($salesOrder->id);
    }

    public function test_customer_with_overdue_invoice_blocks_sales_order_creation(): void
    {
        $this->submittedInvoiceWithDueDate(now()->subDays(10)->toDateString());

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('overdue');

        $this->salesOrderService->create($this->newOrderPayload());
    }

    public function test_customer_over_credit_limit_blocks_sales_order_creation(): void
    {
        // Not-yet-due invoice (no overdue), so only the limit check can trip.
        $this->submittedInvoiceWithDueDate(now()->addDays(30)->toDateString(), rate: 20000); // outstanding = 100000
        $this->customer->update(['credit_limit' => 50000]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Limit kredit');

        $this->salesOrderService->create($this->newOrderPayload());
    }

    public function test_override_with_permission_allows_creation_and_logs_audit(): void
    {
        $this->submittedInvoiceWithDueDate(now()->subDays(10)->toDateString());
        $this->actingAsCreditOverride();

        $salesOrder = $this->salesOrderService->create($this->newOrderPayload([
            'override_credit_block' => true,
            'override_reason' => 'Approved by manager over the phone.',
        ]));

        $this->assertNotNull($salesOrder->id);
        $this->assertDatabaseHas(
            (new AuditLog)->getTable(),
            ['action' => 'credit_block_overridden', 'module' => 'sales_order']
        );
        $this->assertStringContainsString(
            'Approved by manager over the phone.',
            AuditLog::query()->where('action', 'credit_block_overridden')->latest('created_at')->first()->description
        );
    }

    public function test_override_flag_without_permission_still_blocked(): void
    {
        $this->submittedInvoiceWithDueDate(now()->subDays(10)->toDateString());
        // submittedInvoiceWithDueDate() leaves an override-permitted user acting (its own
        // fixture setup) — switch to a plain user with no permissions to actually test "no permission".
        $this->actingAs(\App\Models\User::factory()->create());

        $this->expectException(BusinessException::class);

        $this->salesOrderService->create($this->newOrderPayload([
            'override_credit_block' => true,
            'override_reason' => 'Attempted without permission.',
        ]));
    }

    public function test_submit_blocked_when_customer_drifts_over_limit_after_create(): void
    {
        $this->customer->update(['credit_limit' => 1000000]);

        $salesOrder = $this->salesOrderService->create($this->newOrderPayload([
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100000]],
        ]));
        $this->approveDocument($salesOrder);

        // Drift: the limit tightens after the Draft was saved but before it's submitted.
        $this->customer->update(['credit_limit' => 50000]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Limit kredit');

        $this->salesOrderService->submit($salesOrder);
    }
}
