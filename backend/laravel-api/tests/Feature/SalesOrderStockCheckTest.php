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
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ItemService;
use App\Services\SalesOrderService;
use App\Services\StockLedgerService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sales Order stock-availability block — see SalesOrderStockService and
 * SalesOrderService::enforceStockCheck(). Mirrors SalesOrderCreditCheckTest's shape.
 */
class SalesOrderStockCheckTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;

    protected Customer $customer;

    protected Warehouse $warehouseA;

    protected Warehouse $warehouseB;

    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouseA = Warehouse::query()->create(['name' => 'Warehouse A', 'code' => 'WHA', 'warehouse_type' => WarehouseType::MAIN]);
        $this->warehouseB = Warehouse::query()->create(['name' => 'Warehouse B', 'code' => 'WHB', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);

        $itemGroup = ItemGroup::query()->create(['name' => 'General']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'Pcs']);
        $this->item = Item::query()->create([
            'item_code' => 'ITM-1', 'item_name' => 'Widget', 'item_group_id' => $itemGroup->id, 'uom_id' => $uom->id, 'standard_rate' => 10000,
        ]);
    }

    protected function newOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouseA->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]],
        ], $overrides);
    }

    public function test_order_within_physical_stock_can_be_created(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 10);

        $salesOrder = $this->salesOrderService->create($this->newOrderPayload());

        $this->assertNotNull($salesOrder->id);
    }

    public function test_order_exceeding_physical_stock_is_blocked(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 3);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        $this->salesOrderService->create($this->newOrderPayload());
    }

    public function test_override_with_permission_allows_creation_and_logs_audit(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 3);
        $this->actingAsStockOverride();

        $salesOrder = $this->salesOrderService->create($this->newOrderPayload([
            'override_stock_block' => true,
            'stock_override_reason' => 'Backorder approved by warehouse manager.',
        ]));

        $this->assertNotNull($salesOrder->id);
        $this->assertDatabaseHas(
            (new AuditLog)->getTable(),
            ['action' => 'stock_block_overridden', 'module' => 'sales_order']
        );
    }

    public function test_override_flag_without_permission_still_blocks(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 3);
        $user = User::factory()->create(); // no override_stock_check permission
        $this->actingAs($user);

        $this->expectException(BusinessException::class);

        $this->salesOrderService->create($this->newOrderPayload([
            'override_stock_block' => true,
            'stock_override_reason' => 'Trying anyway.',
        ]));
    }

    /** The actual over-selling scenario the ticket is about: physical stock alone would cover a second order, but another active order already committed most of it. */
    public function test_committed_qty_from_another_active_order_reduces_what_a_second_order_can_request(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 10);

        // First order takes 8 of the 10 — never delivered, so it's still "committed."
        $this->salesOrderService->create($this->newOrderPayload(['items' => [['item_id' => $this->item->id, 'qty' => 8, 'rate' => 10000]]]));

        // Second order for 5 would fit in physical stock (10) but not in what's left after the
        // first order's commitment (10 - 8 = 2 available).
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        $this->salesOrderService->create($this->newOrderPayload(['items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 10000]]]));
    }

    public function test_cancelled_order_no_longer_counts_as_committed(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 10);

        $first = $this->salesOrderService->create($this->newOrderPayload(['items' => [['item_id' => $this->item->id, 'qty' => 8, 'rate' => 10000]]]));
        $this->salesOrderService->cancel($first);

        // Now the full 10 is available again.
        $second = $this->salesOrderService->create($this->newOrderPayload(['items' => [['item_id' => $this->item->id, 'qty' => 8, 'rate' => 10000]]]));

        $this->assertNotNull($second->id);
    }

    public function test_editing_an_existing_order_does_not_double_count_its_own_committed_qty(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 10);

        $salesOrder = $this->salesOrderService->create($this->newOrderPayload(['items' => [['item_id' => $this->item->id, 'qty' => 8, 'rate' => 10000]]]));

        // Bumping the same order's own line from 8 to 10 must be checked against the full 10
        // physical stock, not 10 - 8 (which would wrongly count its own prior reservation twice).
        $updated = $this->salesOrderService->update($salesOrder, [
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 10000]],
        ]);

        $this->assertEquals(10, (float) $updated->items->first()->qty);
    }

    public function test_editing_an_existing_order_beyond_available_stock_is_blocked(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 10);

        $salesOrder = $this->salesOrderService->create($this->newOrderPayload(['items' => [['item_id' => $this->item->id, 'qty' => 8, 'rate' => 10000]]]));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        $this->salesOrderService->update($salesOrder, [
            'items' => [['item_id' => $this->item->id, 'qty' => 11, 'rate' => 10000]],
        ]);
    }

    /**
     * Mirrors the credit check's own "can drift between draft-save and approval" reasoning — for
     * stock, the drift is physical stock itself moving for an unrelated reason (e.g. a write-off)
     * between the order's own creation and its approval, not another Sales Order's commitment
     * (that pool is symmetric by construction: excluding an order's own reservation from its own
     * re-check can never make the same order block itself).
     */
    public function test_approve_re_validates_and_can_newly_block_an_order_that_was_fine_at_save_time(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 10);
        $salesOrder = $this->salesOrderService->create($this->newOrderPayload()); // qty=5, fine against 10

        // Physical stock drops to 3 for an unrelated reason (e.g. a stock write-off) — nothing to
        // do with any Sales Order's own commitment.
        app(StockLedgerService::class)->record(
            itemId: $this->item->id,
            warehouseId: $this->warehouseA->id,
            transactionType: StockTransactionType::OUT,
            voucherType: StockVoucherType::STOCK_ADJUSTMENT,
            voucherId: (string) Str::uuid(),
            qtyChange: -7,
            postingDatetime: now(),
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        $this->salesOrderService->approve($salesOrder);
    }

    public function test_a_different_warehouses_stock_never_affects_the_check(): void
    {
        $this->seedStock($this->item->id, $this->warehouseB->id, 100); // plenty, but wrong warehouse

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Stok tidak mencukupi');

        $this->salesOrderService->create($this->newOrderPayload()); // orders against warehouseA, which has 0
    }

    public function test_purchase_order_style_item_lookup_without_warehouse_never_exposes_or_enforces_stock(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 3);

        // No warehouse_id at all — same call shape Purchase Order's own item lookup uses.
        $items = app(ItemService::class)->list(warehouseId: null);
        $row = $items->firstWhere('id', $this->item->id);

        $this->assertNull($row->available_qty ?? null);

        // And creating a Sales Order with no warehouse_id skips the stock check entirely (it has
        // nothing to check against) rather than erroring — same permissive fallback the header
        // itself already has for an absent warehouse_id.
        $salesOrder = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 999, 'rate' => 10000]],
        ]);

        $this->assertNotNull($salesOrder->id);
    }

    /** Sales Order Detail page's own Approve button pre-check — see SalesOrderController::stockStatus(). */
    public function test_stock_status_for_endpoint_reports_the_same_block_the_write_paths_enforce(): void
    {
        $this->seedStock($this->item->id, $this->warehouseA->id, 3);
        $this->actingAsStockOverride();
        $salesOrder = $this->salesOrderService->create($this->newOrderPayload([
            'override_stock_block' => true,
            'stock_override_reason' => 'Fixture setup — created over-limit on purpose.',
        ]));

        $status = $this->salesOrderService->stockStatusFor($salesOrder->fresh(['items']));

        $this->assertTrue($status['is_blocked']);
        $this->assertStringContainsString('Stok tidak mencukupi', $status['message']);
    }
}
