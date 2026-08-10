<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\StockVoucherType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\ReceiptEntry;
use App\Models\SalesPerson;
use App\Models\UnitOfMeasurement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\PaymentAllocationService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression guard for the invoice print-page data path: the eager-load in
 * InvoiceController::show() and the resource fields in InvoiceResource must
 * stay in sync, or these silently come back null via whenLoaded().
 */
class InvoicePrintDataTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected PaymentAllocationService $paymentAllocationService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected Branch $branch;
    protected SalesPerson $salesPerson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);
        $this->paymentAllocationService = app(PaymentAllocationService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Head Office Samarinda', 'code' => 'HQ']);
        $this->salesPerson = SalesPerson::query()->create(['code' => 'SP001', 'name' => 'Eko']);
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

    protected function accountId(string $code): string
    {
        return \App\Models\ChartOfAccount::query()->where('code', $code)->firstOrFail()->id;
    }

    public function test_invoice_show_returns_sales_person_branch_and_payment_method(): void
    {
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'sales_person_id' => $this->salesPerson->id,
            'branch_id' => $this->branch->id,
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
        $delivery = $this->deliveryService->submit($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $invoice = $this->invoiceService->submit($invoice);

        $accountsReceivable = $invoice->accountsReceivable()->firstOrFail();
        $payment = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => $this->accountId('1100'),
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'total_amount' => 100000,
            'allocated_amount' => 0,
        ])->submit();
        $this->paymentAllocationService->allocateBatch($payment, [
            ['accounts_receivable_id' => $accountsReceivable->id, 'amount' => 100000],
        ]);

        \App\Models\Permission::query()->firstOrCreate(['name' => 'sales.invoices.view', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('sales.invoices.view');
        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}");

        $response->assertOk();
        $response->assertJsonPath('data.sales_order.sales_person.name', 'Eko');
        $response->assertJsonPath('data.sales_order.branch.name', 'Head Office Samarinda');
        $response->assertJsonPath('data.payment_history.0.payment_method', 'bank_transfer');
        $response->assertJsonPath('data.payment_history.0.cash_account_name', fn ($name) => ! empty($name));
    }
}
