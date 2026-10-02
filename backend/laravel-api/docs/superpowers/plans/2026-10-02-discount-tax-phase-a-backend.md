# Discount + PPN Recalculation — Phase A (Backend Calc + Schema + Tests) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move discount from Invoice-header-only (manually typed, never affecting tax) to **per-line** on Sales Order, Delivery, and Invoice (Goods/Direct Goods/Transportation), with PPN computed on the **net-of-discount** line amount everywhere, through one shared calculation service — backend only, no UI, no print, no backfill (those are separate follow-up plans).

**Architecture:** A new `DiscountService` (mirrors the existing `TaxService`'s role exactly: stateless, `calculate()`/`resolveLineDiscount()`, never persists, never posts GL) becomes the single place discount math happens. Every per-line write path that already calls `TaxService::resolveLineTax($line, $item, $field, $lineAmount)` keeps that exact call, but `$lineAmount` changes from the line's **gross** amount to its **net-of-discount** amount — so `journalLines()` (GL posting) needs zero code changes, since it already reads `tax_amount`/`discount_amount`/`grand_total` as opaque stored numbers and `grand_total = subtotal − discount_amount + tax_amount` already holds algebraically.

**Tech Stack:** Laravel 11, PHPUnit (sqlite in-memory, `php artisan test`), existing `DiscountType` enum (`amount`/`percentage`), existing `TaxService`.

**Ground truth this plan is based on** (confirmed by reading the actual files, 2026-10-02):
- `app/Services/TaxService.php` — `calculate(float $baseAmount, ?Tax $tax)` and `resolveLineTax(array $lineData, ?Item $item, string $itemTaxField, float $lineAmount)`. Both take a generic `$baseAmount`/`$lineAmount` — the caller decides what "base" means. This plan changes every caller to pass net instead of gross.
- `app/Services/SalesOrderService.php` — `replaceItems()` (create/plain update) and `syncApprovedItems()` (approved-order edit) are the two places Sales Order lines are written. Both call `resolveLineTax($line, $item, 'sales_tax_id', $lineAmount)` where `$lineAmount = $line['qty'] * $line['rate']` (gross).
- `app/Services/DeliveryService.php` — `buildDeliveryLineAttributes()` (SO-sourced line) and `buildDirectDeliveryLineAttributes()` (no-SO manual line) are the two line-builders. SO-sourced lines inherit `tax_id` from the Sales Order line but recompute `tax_amount` against their own (possibly partial) qty.
- `app/Services/InvoiceService.php` — `createGoods()` copies `DeliveryItem` fields verbatim into `InvoiceItem` (frozen snapshot). `createDirectGoods()` and `createTransportation()` both call `resolveDiscount()`/`resolveTax()` at **header** level today — this is the actual bug (tax computed on gross subtotal, independent of the header discount). `applyItemChanges()` is the one shared place a Draft or Submitted Invoice line's qty/rate/tax is edited (`updateSubmitted()` already has a proven reverse-and-repost GL + AR-delta mechanism for when `grand_total` changes after submission — see its own docblock, "confirmed with the user").
- `app/Models/Invoice.php::journalLines()` posts `AR=grand_total(debit) / Revenue=subtotal(credit) / Tax Payable=tax_amount(credit) / "4900 Discount Given"=discount_amount(debit)` — already balances as long as `grand_total = subtotal − discount_amount + tax_amount`. No change needed here.
- Schema today: `sales_order_items`/`delivery_items`/`invoice_items` all have `tax_id`/`tax_amount` but **no discount columns at all**. `sales_orders`/`deliveries` have **no discount columns at all**. `invoices` has header `discount_amount`/`discount_type`/`discount_percentage` (the old, soon-to-be-superseded manual header field).
- Production data check (2026-10-02, read-only): of 2,005 existing Invoices, **exactly one** has a non-zero `discount_amount`, and it is still in `draft` status (never submitted, nothing posted to GL/AR). Sales Orders/Deliveries have zero discount usage (the column doesn't exist yet). **This phase and its later backfill phase carry effectively zero data-migration risk** — there is nothing real to correct, this is purely new behavior for new documents going forward.

**Allocation rule (agreed):** when a Sales Order line is split across multiple partial Deliveries, a **percentage** discount is copied as-is to every Delivery line (it just scales naturally). A **nominal (Rp)** discount is pro-rated by each Delivery's share of the SO line's total qty: `do_line_discount_value = round(so_line.discount_amount * (do_qty / so_line.qty), 2)`. Small rounding dust across many partial deliveries is accepted (each line rounds to 2 decimals independently, same discipline `TaxService`/`BalanceSheetService`/etc. already use) — never enforced to sum back exactly to the SO line's discount.

**Rounding rule (reused, not invented):** every line's `discount_amount`, `net_amount`, and `tax_amount` round to 2 decimals independently (`round(x, 2)`), and every header total is the **sum of the already-rounded line values** — identical to how `tax_amount` is already handled everywhere in this codebase (`TaxService::calculate()`, `SalesOrderService::replaceItems()`, etc.). No new rounding convention is introduced.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Services/DiscountService.php` (new) | The one shared discount calculator — `calculate()` (raw math) and `resolveLineDiscount()` (request-array convenience, mirrors `TaxService::resolveLineTax()`'s contract). |
| `database/migrations/..._add_discount_to_sales_order_items_table.php` (new) | `discount_type`, `discount_value`, `discount_amount`, `net_amount` on `sales_order_items`. |
| `database/migrations/..._add_discount_totals_to_sales_orders_table.php` (new) | `total_discount`, `tax_base` on `sales_orders` (header cache columns, same pattern as existing `total_amount`/`tax_amount`). |
| `database/migrations/..._add_discount_to_delivery_items_table.php` (new) | Same 4 columns on `delivery_items`. No header columns on `deliveries` — it has never had any (totals are computed live from items), stays that way. |
| `database/migrations/..._add_discount_to_invoice_items_table.php` (new) | Same 4 columns on `invoice_items`. |
| `database/migrations/..._add_tax_base_to_invoices_table.php` (new) | `tax_base` on `invoices` (the existing `discount_amount` column is repurposed to mean "sum of line discounts" instead of "manually typed header discount" — no rename needed). |
| `app/Models/SalesOrderItem.php`, `DeliveryItem.php`, `InvoiceItem.php` | Add new columns to `$fillable`/`$casts`. |
| `app/Models/SalesOrder.php`, `Invoice.php` | Add `total_discount`/`tax_base` (SalesOrder) and `tax_base` (Invoice) to `$fillable`/`$casts`. |
| `app/Http/Requests/StoreSalesOrderRequest.php`, `UpdateSalesOrderRequest.php` | `items.*.discount_type`/`items.*.discount_value` validation. |
| `app/Http/Requests/StoreDeliveryRequest.php` | Same, but only meaningful for a Direct Delivery line (no `sales_order_id`) — SO-sourced lines always derive their discount, never accept one directly. |
| `app/Http/Requests/StoreInvoiceRequest.php` | Transportation and Direct Goods lines gain `discount_type`/`discount_value`; Transportation lines also gain `tax_id` (moving from header-only tax select to per-line, per your decision #3); header `discount_type`/`discount_amount`/`discount_percentage` become `prohibited` (no longer a valid client input — derived from lines only). |
| `app/Services/SalesOrderService.php` | `replaceItems()`, `syncApprovedItems()` — discount resolved per line, tax computed on net. Header gains `total_discount`/`tax_base`. |
| `app/Services/DeliveryService.php` | `buildDeliveryLineAttributes()` (derive from SO line via allocation rule), `buildDirectDeliveryLineAttributes()` (accept manual per-line discount). |
| `app/Services/InvoiceService.php` | `createGoods()` (copy discount fields verbatim from `DeliveryItem`), `createDirectGoods()`/`createTransportation()` (restructured to per-line discount + tax, header `resolveDiscount()`/`resolveTax()` methods removed), `applyItemChanges()` (discount becomes editable alongside qty/rate/tax on Draft and Submitted Invoices). |
| `tests/Feature/DiscountCalculationTest.php` (new) | `DiscountService` unit tests + the mandatory reference example. |
| `tests/Feature/SalesOrderDiscountTest.php` (new) | Per-line discount + net-based tax on Sales Order create/update/approved-edit. |
| `tests/Feature/DeliveryDiscountAllocationTest.php` (new) | SO line split across 2 partial Deliveries, both % and Rp allocation. |
| `tests/Feature/InvoiceDiscountTest.php` (new) | Goods-from-DO inheritance, Direct Goods per-line, Transportation per-line (incl. per-line tax, mixed VAT/non-VAT), rounding edge case, 100% discount edge case, Submitted-Invoice discount edit. |

---

## Task 1: `DiscountService` — the central calculator

**Files:**
- Create: `app/Services/DiscountService.php`
- Test: `tests/Feature/DiscountCalculationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\DiscountType;
use App\Exceptions\BusinessException;
use App\Services\DiscountService;
use Tests\TestCase;

class DiscountCalculationTest extends TestCase
{
    protected DiscountService $discountService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discountService = app(DiscountService::class);
    }

    public function test_percentage_discount_computes_amount_and_net(): void
    {
        $result = $this->discountService->calculate(10_000_000, DiscountType::PERCENTAGE, 10);

        $this->assertEquals(1_000_000, $result['discount_amount']);
        $this->assertEquals(9_000_000, $result['net_amount']);
    }

    public function test_nominal_discount_computes_net(): void
    {
        $result = $this->discountService->calculate(10_000_000, DiscountType::AMOUNT, 1_500_000);

        $this->assertEquals(1_500_000, $result['discount_amount']);
        $this->assertEquals(8_500_000, $result['net_amount']);
    }

    public function test_zero_discount_is_a_no_op(): void
    {
        $result = $this->discountService->calculate(500_000, DiscountType::AMOUNT, 0);

        $this->assertEquals(0, $result['discount_amount']);
        $this->assertEquals(500_000, $result['net_amount']);
    }

    public function test_hundred_percent_discount_zeroes_the_net_amount(): void
    {
        $result = $this->discountService->calculate(750_000, DiscountType::PERCENTAGE, 100);

        $this->assertEquals(750_000, $result['discount_amount']);
        $this->assertEquals(0, $result['net_amount']);
    }

    public function test_percentage_over_100_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->discountService->calculate(100_000, DiscountType::PERCENTAGE, 101);
    }

    public function test_nominal_discount_exceeding_gross_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->discountService->calculate(100_000, DiscountType::AMOUNT, 100_001);
    }

    public function test_negative_nominal_discount_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->discountService->calculate(100_000, DiscountType::AMOUNT, -1);
    }

    public function test_rounds_to_two_decimals_on_a_repeating_percentage(): void
    {
        // 1.666.657 * 10% = 166.665,7 -> rounds to 166.665,70 (banker's-neutral round-half-up via PHP round()).
        $result = $this->discountService->calculate(1_666_657, DiscountType::PERCENTAGE, 10);

        $this->assertEquals(166665.70, $result['discount_amount']);
        $this->assertEquals(1499991.30, $result['net_amount']);
    }

    /** The mandatory reference example from the ticket: Gross 10jt, diskon 10%, PPN 11% -> Net 9jt, PPN 990rb, Grand Total 9.99jt. */
    public function test_reference_example_discount_then_tax_on_net(): void
    {
        $discount = $this->discountService->calculate(10_000_000, DiscountType::PERCENTAGE, 10);
        $this->assertEquals(9_000_000, $discount['net_amount']);

        $tax = round($discount['net_amount'] * 0.11, 2);
        $this->assertEquals(990_000, $tax);

        $grandTotal = $discount['net_amount'] + $tax;
        $this->assertEquals(9_990_000, $grandTotal);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DiscountCalculationTest`
Expected: FAIL — `Class "App\Services\DiscountService" not found`

- [ ] **Step 3: Write the implementation**

```php
<?php

namespace App\Services;

use App\Enums\DiscountType;
use App\Exceptions\BusinessException;

/**
 * The single source of truth for per-line discount calculation — Sales Order, Delivery, and
 * Invoice (Goods/Direct Goods/Transportation) all call this instead of reimplementing the
 * percent-vs-amount math. Mirrors TaxService's own role exactly: a stateless calculator that
 * never persists anything and never posts a journal entry.
 */
class DiscountService
{
    /** @return array{discount_amount: float, net_amount: float} */
    public function calculate(float $grossAmount, DiscountType $type, float $value): array
    {
        if ($type === DiscountType::PERCENTAGE) {
            if ($value < 0 || $value > 100) {
                throw new BusinessException('Discount percentage must be between 0 and 100.');
            }

            $discountAmount = round($grossAmount * $value / 100, 2);

            return ['discount_amount' => $discountAmount, 'net_amount' => round($grossAmount - $discountAmount, 2)];
        }

        if ($value < 0) {
            throw new BusinessException('Discount amount cannot be negative.');
        }

        if ($value > $grossAmount) {
            throw new BusinessException('Discount amount cannot exceed the line amount.');
        }

        $discountAmount = round($value, 2);

        return ['discount_amount' => $discountAmount, 'net_amount' => round($grossAmount - $discountAmount, 2)];
    }

    /**
     * Resolve a single document line's discount from raw request data — same "key present wins,
     * absent means none" contract as TaxService::resolveLineTax(). A line that sends neither key
     * gets discount_type=amount, value=0 (no discount), matching every line's current implicit
     * behavior before this field existed.
     *
     * @param  array<string, mixed>  $lineData
     * @return array{0: DiscountType, 1: float, 2: float, 3: float} [type, value, discountAmount, netAmount]
     */
    public function resolveLineDiscount(array $lineData, float $grossAmount): array
    {
        $type = isset($lineData['discount_type']) ? DiscountType::from($lineData['discount_type']) : DiscountType::AMOUNT;
        $value = (float) ($lineData['discount_value'] ?? 0);

        $result = $this->calculate($grossAmount, $type, $value);

        return [$type, $value, $result['discount_amount'], $result['net_amount']];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DiscountCalculationTest`
Expected: PASS (9 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/DiscountService.php tests/Feature/DiscountCalculationTest.php
git commit -m "feat(sales): add DiscountService, the central per-line discount calculator"
```

---

## Task 2: Schema — per-line discount columns + header totals

**Files:**
- Create: `database/migrations/2026_10_02_000001_add_discount_to_sales_order_items_table.php`
- Create: `database/migrations/2026_10_02_000002_add_discount_totals_to_sales_orders_table.php`
- Create: `database/migrations/2026_10_02_000003_add_discount_to_delivery_items_table.php`
- Create: `database/migrations/2026_10_02_000004_add_discount_to_invoice_items_table.php`
- Create: `database/migrations/2026_10_02_000005_add_tax_base_to_invoices_table.php`

- [ ] **Step 1: Write the item-level migration (same shape 3 times)**

```php
<?php
// 2026_10_02_000001_add_discount_to_sales_order_items_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line discount — SalesOrderService::replaceItems()/syncApprovedItems() resolve this via
 * DiscountService, same cache-column discipline tax_id/tax_amount already use on this table.
 * discount_type defaults 'amount'/0 so every existing row (created before this column existed)
 * reads as "no discount", identical to its current real behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->string('discount_type')->default('amount')->after('amount');
            $table->decimal('discount_value', 15, 2)->default(0)->after('discount_type');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_value');
            $table->decimal('net_amount', 15, 2)->default(0)->after('discount_amount');
        });

        // Backfill existing rows so net_amount isn't stuck at 0 for pre-existing lines — this is
        // a schema-default backfill (net = gross, since no discount existed before this column),
        // not the document-recalculation backfill (that's a separate, later plan).
        DB::table('sales_order_items')->update(['net_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_amount', 'net_amount']);
        });
    }
};
```

Add `use Illuminate\Support\Facades\DB;` at the top. Repeat the identical shape (same 4 columns, same `after('amount')`, same backfill-to-gross `up()` tail) for:
- `database/migrations/2026_10_02_000003_add_discount_to_delivery_items_table.php` on `delivery_items`.
- `database/migrations/2026_10_02_000004_add_discount_to_invoice_items_table.php` on `invoice_items`.

- [ ] **Step 2: Write the Sales Order header totals migration**

```php
<?php
// 2026_10_02_000002_add_discount_totals_to_sales_orders_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * total_discount = sum of every line's discount_amount. tax_base (DPP) = sum of every line's
 * net_amount = total_amount - total_discount. Both are cache columns, same discipline
 * total_amount/tax_amount already use — the per-line sum in SalesOrderService is authoritative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->decimal('total_discount', 15, 2)->default(0)->after('total_amount');
            $table->decimal('tax_base', 15, 2)->default(0)->after('total_discount');
        });

        DB::table('sales_orders')->update(['tax_base' => DB::raw('total_amount')]);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['total_discount', 'tax_base']);
        });
    }
};
```

- [ ] **Step 3: Write the Invoice tax_base migration**

```php
<?php
// 2026_10_02_000005_add_tax_base_to_invoices_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tax_base (DPP) = subtotal - discount_amount, the amount PPN is actually computed against.
 * Stored (not derived on read) for the same reason subtotal/tax_amount/grand_total already are —
 * print/export/reports read it directly. discount_amount (existing column) is repurposed from
 * "manually typed header discount" to "sum of line discounts" — same column, new meaning, no
 * rename, since every existing reader already just treats it as a number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('tax_base', 15, 2)->default(0)->after('discount_percentage');
        });

        DB::table('invoices')->update(['tax_base' => DB::raw('subtotal - discount_amount')]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('tax_base');
        });
    }
};
```

- [ ] **Step 4: Run migrations against the test DB**

Run: `php artisan migrate --env=testing` (or just run the test suite — `RefreshDatabase` runs every migration fresh). Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_10_02_*.php
git commit -m "feat(sales): add per-line discount columns (SO/DO/SI items) and header discount totals"
```

---

## Task 3: Model updates — `$fillable`/`$casts`

**Files:**
- Modify: `app/Models/SalesOrderItem.php`
- Modify: `app/Models/SalesOrder.php`
- Modify: `app/Models/DeliveryItem.php`
- Modify: `app/Models/InvoiceItem.php`
- Modify: `app/Models/Invoice.php`

- [ ] **Step 1: `SalesOrderItem` — add to `$fillable` after `'amount'`:**

```php
'discount_type',
'discount_value',
'discount_amount',
'net_amount',
```

Add to `$casts`:

```php
'discount_value' => 'decimal:2',
'discount_amount' => 'decimal:2',
'net_amount' => 'decimal:2',
```

- [ ] **Step 2: `SalesOrder` — add to `$fillable` after `'total_amount'`:**

```php
'total_discount',
'tax_base',
```

Add to `$casts` (create the `$casts` array if the model doesn't have one yet — check the file first; if it has no `$casts` property, add one):

```php
protected $casts = [
    'total_discount' => 'decimal:2',
    'tax_base' => 'decimal:2',
];
```

- [ ] **Step 3: `DeliveryItem` and `InvoiceItem` — same 4 fields/casts as `SalesOrderItem`** (identical names: `discount_type`, `discount_value`, `discount_amount`, `net_amount`).

- [ ] **Step 4: `Invoice` — add `'tax_base'` to `$fillable`** (after `'discount_percentage'`) and `'tax_base' => 'decimal:2'` to `$casts`.

- [ ] **Step 5: Run the full suite to confirm nothing broke from the model changes alone**

Run: `php artisan test`
Expected: PASS, same count as before this task (these are additive fields, no behavior changed yet)

- [ ] **Step 6: Commit**

```bash
git add app/Models/SalesOrderItem.php app/Models/SalesOrder.php app/Models/DeliveryItem.php app/Models/InvoiceItem.php app/Models/Invoice.php
git commit -m "feat(sales): expose new discount columns on SO/DO/SI item and header models"
```

---

## Task 4: Sales Order — per-line discount wired into create/update/approved-edit

**Files:**
- Modify: `app/Services/SalesOrderService.php:94-152` (`create()`), `:154-205` (`update()`), `:218-267` (`updateApproved()`), `:277-345` (`syncApprovedItems()`), `:526-556` (`replaceItems()`)
- Modify: `app/Http/Requests/StoreSalesOrderRequest.php:34-41`
- Modify: `app/Http/Requests/UpdateSalesOrderRequest.php` (items rules)
- Test: `tests/Feature/SalesOrderDiscountTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected Tax $ppn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Widget', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
        $this->ppn = Tax::query()->create(['name' => 'PPN 11%', 'code' => 'PPN11', 'type' => 'vat', 'rate' => 11, 'calculation_mode' => 'exclusive', 'is_active' => true]);
    }

    public function test_reference_example_percentage_discount_then_tax_on_net(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id],
            ],
        ]);

        $line = $order->items->first();
        $this->assertEquals(10_000_000, (float) $line->amount);
        $this->assertEquals(1_000_000, (float) $line->discount_amount);
        $this->assertEquals(9_000_000, (float) $line->net_amount);
        $this->assertEquals(990_000, (float) $line->tax_amount);

        $this->assertEquals(10_000_000, (float) $order->total_amount);
        $this->assertEquals(1_000_000, (float) $order->total_discount);
        $this->assertEquals(9_000_000, (float) $order->tax_base);
        $this->assertEquals(990_000, (float) $order->tax_amount);
        $this->assertEquals(9_990_000, (float) $order->grand_total);
    }

    public function test_line_with_no_discount_behaves_exactly_as_before(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 2, 'rate' => 500_000, 'tax_id' => $this->ppn->id],
            ],
        ]);

        $line = $order->items->first();
        $this->assertEquals(0, (float) $line->discount_amount);
        $this->assertEquals(1_000_000, (float) $line->net_amount);
        $this->assertEquals(110_000, (float) $line->tax_amount);
        $this->assertEquals(1_110_000, (float) $order->grand_total);
    }

    public function test_item_with_no_tax_is_never_taxed_regardless_of_discount(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100_000, 'discount_type' => 'amount', 'discount_value' => 20_000],
            ],
        ]);

        $this->assertEquals(0, (float) $order->tax_amount);
        $this->assertEquals(80_000, (float) $order->grand_total);
    }

    public function test_updating_a_lines_discount_recomputes_header_totals(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id,
            'branch_id' => Branch::query()->first()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100_000, 'tax_id' => $this->ppn->id]],
        ]);

        $order = $this->salesOrderService->update($order, [
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 100_000, 'discount_type' => 'amount', 'discount_value' => 10_000, 'tax_id' => $this->ppn->id]],
        ]);

        $this->assertEquals(10_000, (float) $order->total_discount);
        $this->assertEquals(90_000, (float) $order->tax_base);
        $this->assertEquals(9_900, (float) $order->tax_amount);
        $this->assertEquals(99_900, (float) $order->grand_total);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SalesOrderDiscountTest`
Expected: FAIL — `discount_type` not a valid key / column doesn't affect totals yet (totals come back as if no discount applied).

- [ ] **Step 3: Add validation rules**

In `StoreSalesOrderRequest::rules()`, after `'items.*.rate'`:

```php
'items.*.discount_type' => ['nullable', Rule::enum(DiscountType::class)],
'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
```

Add `use App\Enums\DiscountType;` and `use Illuminate\Validation\Rule;` to the top of the file. Make the identical addition to `UpdateSalesOrderRequest::rules()`.

- [ ] **Step 4: Update `SalesOrderService`**

Inject `DiscountService` in the constructor (alongside the existing `TaxService $taxService`):

```php
protected DiscountService $discountService,
```

(add `use App\Services\DiscountService;` — it's already in the same namespace `App\Services`, so just the `use` of the class isn't needed since it's a sibling class in the same namespace; Laravel's container resolves it automatically via type-hint).

Rewrite `replaceItems()` (lines 526-556):

```php
/** @return array{0: float, 1: float} [totalDiscount, totalTax] — the header's own cache columns. */
protected function replaceItems(SalesOrder $salesOrder, array $items): array
{
    $salesOrder->items()->delete();

    $itemsById = Item::query()->with('itemUoms')->whereIn('id', collect($items)->pluck('item_id')->unique())->get()->keyBy('id');
    $totalDiscount = 0.0;
    $totalTax = 0.0;

    foreach ($items as $line) {
        $grossAmount = $line['qty'] * $line['rate'];
        [$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
        [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $itemsById->get($line['item_id']), 'sales_tax_id', $netAmount);
        // qty/rate are in the chosen UOM; the factor is snapshotted from the item's own UOM list.
        $uomLine = $itemsById->get($line['item_id'])->resolveLineUom($line['uom_id'] ?? null);

        $this->salesOrderItemRepository->create([
            'sales_order_id' => $salesOrder->id,
            'item_id' => $line['item_id'],
            'uom_id' => $uomLine['uom_id'],
            'uom_factor' => $uomLine['uom_factor'],
            'qty' => $line['qty'],
            'rate' => $line['rate'],
            'amount' => $grossAmount,
            'discount_type' => $discountType->value,
            'discount_value' => $discountValue,
            'discount_amount' => $discountAmount,
            'net_amount' => $netAmount,
            'delivered_qty' => 0,
            'tax_id' => $taxId,
            'tax_amount' => $taxAmount,
        ]);

        $totalDiscount += $discountAmount;
        $totalTax += $taxAmount;
    }

    return [round($totalDiscount, 2), round($totalTax, 2)];
}
```

Update `create()` (lines 94-152) — `$taxAmount = $this->replaceItems(...)` becomes:

```php
[$totalDiscount, $taxAmount] = $this->replaceItems($salesOrder, $data['items']);

$this->salesOrderRepository->update($salesOrder, [
    'total_discount' => $totalDiscount,
    'tax_base' => round($subtotal - $totalDiscount, 2),
    'tax_amount' => $taxAmount,
    'grand_total' => round($subtotal - $totalDiscount + $taxAmount, 2),
]);
```

(`$subtotal` is still `$this->sumLines($data['items'])`, unchanged — it stays the gross total, same meaning as today.)

Update `update()` (lines 154-205) the same way — where it currently does `$taxAmount = $this->replaceItems($salesOrder, $data['items']);` followed by setting `$headerData['tax_amount']`/`$headerData['grand_total']`, change to:

```php
[$totalDiscount, $taxAmount] = $this->replaceItems($salesOrder, $data['items']);
$headerData['total_amount'] = $subtotal;
$headerData['total_discount'] = $totalDiscount;
$headerData['tax_base'] = round($subtotal - $totalDiscount, 2);
$headerData['tax_amount'] = $taxAmount;
$headerData['grand_total'] = round($subtotal - $totalDiscount + $taxAmount, 2);
```

Update `updateApproved()` (lines 218-267) — where it currently sums `$freshItems->sum('amount')` and `$freshItems->sum('tax_amount')`, add:

```php
$totalDiscount = round((float) $freshItems->sum('discount_amount'), 2);
$headerData['total_discount'] = $totalDiscount;
$headerData['tax_base'] = round($totalAmount - $totalDiscount, 2);
```

(keep the existing `total_amount`/`tax_amount`/`grand_total` lines, `grand_total` stays `round($totalAmount - $totalDiscount + $taxAmount, 2)` instead of `round($totalAmount + $taxAmount, 2)`).

Update `syncApprovedItems()` (lines 277-345) — the per-line write at the bottom of the loop, same change as `replaceItems()`:

```php
$grossAmount = $line['qty'] * $line['rate'];
[$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
[$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $itemsById->get($line['item_id']), 'sales_tax_id', $netAmount);

$uomLine = $itemsById->get($line['item_id'])->resolveLineUom($line['uom_id'] ?? null);

$attributes = [
    'sales_order_id' => $salesOrder->id,
    'item_id' => $line['item_id'],
    'uom_id' => $uomLine['uom_id'],
    'uom_factor' => $uomLine['uom_factor'],
    'qty' => $line['qty'],
    'rate' => $line['rate'],
    'amount' => $grossAmount,
    'discount_type' => $discountType->value,
    'discount_value' => $discountValue,
    'discount_amount' => $discountAmount,
    'net_amount' => $netAmount,
    'tax_id' => $taxId,
    'tax_amount' => $taxAmount,
];
```

Also extend the `$unchanged` check a few lines above (the locked-line guard) to additionally require the discount fields match:

```php
&& ($incomingLine['discount_type'] ?? 'amount') === $existingLine->discount_type
&& abs((float) ($incomingLine['discount_value'] ?? 0) - (float) $existingLine->discount_value) < 0.005
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SalesOrderDiscountTest`
Expected: PASS (4 tests)

- [ ] **Step 6: Run the full suite to catch any other Sales Order test that asserted the old gross-tax behavior**

Run: `php artisan test --filter=SalesOrder`
Expected: PASS — if any existing test fails, it was asserting tax computed on gross; since no existing test passes a discount, net_amount === gross amount for every pre-existing test case, so tax_amount should be numerically identical to before. Investigate any failure before proceeding (it likely means a line in this task's edit missed updating `$lineAmount` to `$netAmount`).

- [ ] **Step 7: Commit**

```bash
git add app/Services/SalesOrderService.php app/Http/Requests/StoreSalesOrderRequest.php app/Http/Requests/UpdateSalesOrderRequest.php tests/Feature/SalesOrderDiscountTest.php
git commit -m "feat(sales): per-line discount on Sales Order, PPN now computed on net amount"
```

---

## Task 5: Delivery — discount derived from the Sales Order line (allocation rule), or manual for Direct Delivery

**Files:**
- Modify: `app/Services/DeliveryService.php:227-251` (`buildDirectDeliveryLineAttributes()`), `:489-523` (`buildDeliveryLineAttributes()`)
- Modify: `app/Http/Requests/StoreDeliveryRequest.php`
- Test: `tests/Feature/DeliveryDiscountAllocationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryDiscountAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Item $item;
    protected Tax $ppn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Widget', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
        $this->ppn = Tax::query()->create(['name' => 'PPN 11%', 'code' => 'PPN11', 'type' => 'vat', 'rate' => 11, 'calculation_mode' => 'exclusive', 'is_active' => true]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100, 0);
    }

    public function test_percentage_discount_copies_as_is_to_every_partial_delivery(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id, 'branch_id' => Branch::query()->first()->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 100_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id]],
        ]);
        $this->salesOrderService->approve($order);
        $soItem = $order->fresh()->items->first();

        $delivery1 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 4]],
        ]);
        $delivery2 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 6]],
        ]);

        $line1 = $delivery1->items->first();
        $line2 = $delivery2->items->first();

        // 4 * 100.000 = 400.000 gross, 10% = 40.000 discount, net 360.000.
        $this->assertEquals(40_000, (float) $line1->discount_amount);
        $this->assertEquals(360_000, (float) $line1->net_amount);
        $this->assertEquals(39_600, (float) $line1->tax_amount); // 360.000 * 11%

        // 6 * 100.000 = 600.000 gross, 10% = 60.000 discount, net 540.000.
        $this->assertEquals(60_000, (float) $line2->discount_amount);
        $this->assertEquals(540_000, (float) $line2->net_amount);
        $this->assertEquals(59_400, (float) $line2->tax_amount);
    }

    public function test_nominal_discount_is_prorated_by_qty_shipped(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id, 'branch_id' => Branch::query()->first()->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            // SO line: qty 10 @ 100.000 = 1.000.000 gross, Rp 100.000 flat discount.
            'items' => [['item_id' => $this->item->id, 'qty' => 10, 'rate' => 100_000, 'discount_type' => 'amount', 'discount_value' => 100_000]],
        ]);
        $this->salesOrderService->approve($order);
        $soItem = $order->fresh()->items->first();

        // DO1 ships 4 of 10 -> 40% of the 100.000 discount = 40.000.
        $delivery1 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 4]],
        ]);
        // DO2 ships the remaining 6 of 10 -> 60% = 60.000.
        $delivery2 = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 6]],
        ]);

        $this->assertEquals(40_000, (float) $delivery1->items->first()->discount_amount);
        $this->assertEquals(360_000, (float) $delivery1->items->first()->net_amount);
        $this->assertEquals(60_000, (float) $delivery2->items->first()->discount_amount);
        $this->assertEquals(540_000, (float) $delivery2->items->first()->net_amount);
        // The two allocated discounts sum back exactly to the SO line's own discount here
        // (4+6=10 divides evenly) — DeliveryDiscountAllocationTest's own docblock on the
        // implementation documents that an uneven split is allowed to leave rounding dust.
        $this->assertEquals(100_000, (float) $delivery1->items->first()->discount_amount + (float) $delivery2->items->first()->discount_amount);
    }

    public function test_direct_delivery_line_accepts_a_manual_discount(): void
    {
        $delivery = $this->deliveryService->create([
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 5, 'rate' => 50_000, 'discount_type' => 'amount', 'discount_value' => 10_000, 'tax_id' => $this->ppn->id]],
        ]);

        $line = $delivery->items->first();
        $this->assertEquals(10_000, (float) $line->discount_amount);
        $this->assertEquals(240_000, (float) $line->net_amount); // 250.000 - 10.000
        $this->assertEquals(26_400, (float) $line->tax_amount); // 240.000 * 11%
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DeliveryDiscountAllocationTest`
Expected: FAIL — discount columns don't exist on the returned lines / tax computed on gross.

- [ ] **Step 3: Add validation**

In `StoreDeliveryRequest::rules()`, after `'items.*.tax_id'`:

```php
// Only meaningful for a Direct Delivery line (no sales_order_id) — SO-linked items always
// derive their discount from the linked sales_order_items row instead (DeliveryService's
// own allocation rule), never accept one directly.
'items.*.discount_type' => ['sometimes', 'nullable', Rule::enum(DiscountType::class)],
'items.*.discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
```

Add `use App\Enums\DiscountType;` and `use Illuminate\Validation\Rule;` imports.

- [ ] **Step 4: Update `DeliveryService`**

Inject `DiscountService $discountService` in the constructor.

Rewrite `buildDirectDeliveryLineAttributes()` (lines 227-251):

```php
protected function buildDirectDeliveryLineAttributes(Delivery $delivery, array $line): array
{
    $item = $this->itemRepository->findOrFail($line['item_id']);
    $this->qtyCategoryValidator->assertValid($item, $line['qty']);
    $qty = $this->qtyCategoryValidator->round($item, $line['qty']);
    $rate = (float) ($line['rate'] ?? 0);
    $grossAmount = $qty * $rate;
    [$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
    [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, null, '', $netAmount);

    return [
        'delivery_id' => $delivery->id,
        'sales_order_item_id' => null,
        'item_id' => $item->id,
        'item_code' => $item->item_code,
        'item_name' => $item->item_name,
        'uom' => $item->uom->name,
        'uom_factor' => 1,
        'rate' => $rate,
        'qty' => $qty,
        'qty_category' => $item->qty_category,
        'amount' => round($grossAmount, 2),
        'discount_type' => $discountType->value,
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'net_amount' => $netAmount,
        'tax_id' => $taxId,
        'tax_amount' => round($taxAmount, 2),
    ];
}
```

Rewrite `buildDeliveryLineAttributes()` (lines 489-523) — this is the SO-sourced line, where discount is **derived**, never accepted from `$line`:

```php
protected function buildDeliveryLineAttributes(Delivery $delivery, SalesOrderItem $soItem, int|float $qty, int|float|null $rateOverride, ?string $taxIdOverride): array
{
    $item = $soItem->item;
    $this->qtyCategoryValidator->assertValid($item, $qty);
    $qty = $this->qtyCategoryValidator->round($item, $qty);

    $rate = $rateOverride ?? $soItem->rate;
    $grossAmount = $qty * $rate;

    // Discount is derived from the SO line, never re-entered here — a percentage carries over
    // as-is (it scales naturally against this DO's own, possibly partial, gross amount); a
    // nominal (Rp) discount is pro-rated by this DO's share of the SO line's total qty, so
    // several partial Deliveries against the same SO line never sum to more than its own
    // discount_amount (small rounding dust aside — each line rounds independently, same
    // discipline tax_amount already uses everywhere in this codebase).
    $soDiscountType = $soItem->discount_type;
    $soDiscountValue = $soDiscountType === \App\Enums\DiscountType::PERCENTAGE->value
        ? (float) $soItem->discount_value
        : round((float) $soItem->discount_amount * ($qty / $soItem->qty), 2);

    ['discount_amount' => $discountAmount, 'net_amount' => $netAmount] = $this->discountService->calculate(
        $grossAmount,
        \App\Enums\DiscountType::from($soDiscountType),
        $soDiscountValue,
    );

    // tax_id carries forward as-is (or the caller's override); tax_amount is recomputed
    // against this delivery line's own (possibly partial, possibly overridden) net amount, not
    // simply copied — same "rate inherited, amount recomputed against the real quantity" rule
    // the old header-level inheritance used, now applied per line, now against net.
    $taxId = $taxIdOverride ?? $soItem->tax_id;
    $tax = $taxId !== null ? $this->taxRepository->findOrFail($taxId) : null;
    $taxAmount = $tax !== null ? $this->taxService->calculate($netAmount, $tax)['tax_amount'] : 0.0;

    return [
        'delivery_id' => $delivery->id,
        'sales_order_item_id' => $soItem->id,
        'item_id' => $item->id,
        'item_code' => $item->item_code,
        'item_name' => $item->item_name,
        'uom' => $soItem->uom?->name ?? $item->uom->name,
        'uom_factor' => $soItem->uom_factor,
        'rate' => $rate,
        'qty' => $qty,
        'qty_category' => $item->qty_category,
        'amount' => round($grossAmount, 2),
        'discount_type' => $soDiscountType,
        'discount_value' => $soDiscountValue,
        'discount_amount' => $discountAmount,
        'net_amount' => $netAmount,
        'tax_id' => $taxId,
        'tax_amount' => round($taxAmount, 2),
    ];
}
```

(The inline `\App\Enums\DiscountType::...` fully-qualified references avoid adding a new `use` that might collide — check the file's existing imports first and use a normal `use App\Enums\DiscountType;` at the top instead if there's no collision, which is the cleaner final form.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=DeliveryDiscountAllocationTest`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full Delivery test suite**

Run: `php artisan test --filter=Delivery`
Expected: PASS — every existing test has zero discount on its Sales Order lines, so `net_amount === amount` and tax is numerically unchanged.

- [ ] **Step 7: Commit**

```bash
git add app/Services/DeliveryService.php app/Http/Requests/StoreDeliveryRequest.php tests/Feature/DeliveryDiscountAllocationTest.php
git commit -m "feat(sales): derive per-line discount on Delivery from its Sales Order line (or manual for Direct Delivery)"
```

---

## Task 6: Invoice — Goods inherits, Direct Goods + Transportation go per-line, Submitted-edit gains discount

**Files:**
- Modify: `app/Services/InvoiceService.php` — `createGoods()` (~101-169), `createTransportation()` (~194-255), `createDirectGoods()` (~268-354), `applyItemChanges()` (~534-567), remove `resolveDiscount()`/`resolveTax()` (~614-657) call sites that no longer apply
- Modify: `app/Http/Requests/StoreInvoiceRequest.php`
- Test: `tests/Feature/InvoiceDiscountTest.php`

This is the largest task — do it in the sub-steps below, running the targeted test after each sub-step rather than all at once.

- [ ] **Step 1: Write the full failing test file**

```php
<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Enums\WarehouseType;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Tax;
use App\Models\UnitOfMeasurement;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\InvoiceService;
use App\Services\SalesOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected SalesOrderService $salesOrderService;
    protected DeliveryService $deliveryService;
    protected InvoiceService $invoiceService;
    protected Customer $customer;
    protected Warehouse $warehouse;
    protected Branch $branch;
    protected Item $item;
    protected Tax $ppn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->salesOrderService = app(SalesOrderService::class);
        $this->deliveryService = app(DeliveryService::class);
        $this->invoiceService = app(InvoiceService::class);

        $company = Company::query()->create(['name' => 'Test Co', 'code' => 'TC', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        $this->branch = Branch::query()->create(['company_id' => $company->id, 'name' => 'Main', 'code' => 'HQ']);
        $this->warehouse = Warehouse::query()->create(['name' => 'Main WH', 'code' => 'WH1', 'warehouse_type' => WarehouseType::MAIN]);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme']);
        $uom = UnitOfMeasurement::query()->create(['name' => 'PCS']);
        $this->item = Item::query()->create(['item_code' => 'ITEM1', 'item_name' => 'Widget', 'item_group_id' => ItemGroup::query()->create(['name' => 'General'])->id, 'uom_id' => $uom->id, 'standard_rate' => 0]);
        $this->ppn = Tax::query()->create(['name' => 'PPN 11%', 'code' => 'PPN11', 'type' => 'vat', 'rate' => 11, 'calculation_mode' => 'exclusive', 'is_active' => true]);

        $this->seedStock($this->item->id, $this->warehouse->id, 100, 0);
    }

    public function test_goods_invoice_inherits_discount_from_its_delivery_lines(): void
    {
        $order = $this->salesOrderService->create([
            'customer_id' => $this->customer->id, 'branch_id' => $this->branch->id, 'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id]],
        ]);
        $this->salesOrderService->approve($order);
        $soItem = $order->fresh()->items->first();

        $delivery = $this->deliveryService->create([
            'sales_order_id' => $order->id, 'warehouse_id' => $this->warehouse->id,
            'delivery_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
            'items' => [['sales_order_item_id' => $soItem->id, 'qty' => 1]],
        ]);
        $this->deliveryService->complete($delivery);

        $invoice = $this->invoiceService->create([
            'delivery_ids' => [$delivery->id],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(1_000_000, (float) $line->discount_amount);
        $this->assertEquals(9_000_000, (float) $line->net_amount);
        $this->assertEquals(990_000, (float) $line->tax_amount);

        $this->assertEquals(10_000_000, (float) $invoice->subtotal);
        $this->assertEquals(1_000_000, (float) $invoice->discount_amount);
        $this->assertEquals(9_000_000, (float) $invoice->tax_base);
        $this->assertEquals(990_000, (float) $invoice->tax_amount);
        $this->assertEquals(9_990_000, (float) $invoice->grand_total);
    }

    public function test_direct_goods_invoice_computes_tax_on_net_per_line(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id]],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $this->assertEquals(9_990_000, (float) $invoice->grand_total);
        $this->assertEquals(990_000, (float) $invoice->tax_amount);
    }

    public function test_transportation_invoice_computes_per_line_discount_and_per_line_tax(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [
                ['description' => 'Ongkos Angkut A', 'qty' => 1, 'rate' => 10_000_000, 'discount_type' => 'percentage', 'discount_value' => 10, 'tax_id' => $this->ppn->id],
                // Non-VAT line — never taxed regardless of discount.
                ['description' => 'Ongkos Angkut B', 'qty' => 1, 'rate' => 1_000_000, 'discount_type' => 'amount', 'discount_value' => 100_000],
            ],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        [$lineA, $lineB] = $invoice->items;
        $this->assertEquals(990_000, (float) $lineA->tax_amount);
        $this->assertEquals(0, (float) $lineB->tax_amount);

        // Subtotal 11.000.000, discount 1.100.000, tax_base 9.900.000, tax 990.000, grand 10.890.000.
        $this->assertEquals(11_000_000, (float) $invoice->subtotal);
        $this->assertEquals(1_100_000, (float) $invoice->discount_amount);
        $this->assertEquals(9_900_000, (float) $invoice->tax_base);
        $this->assertEquals(990_000, (float) $invoice->tax_amount);
        $this->assertEquals(10_890_000, (float) $invoice->grand_total);
    }

    public function test_hundred_percent_discount_line_produces_zero_tax_and_zero_net(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'items' => [['description' => 'Free service', 'qty' => 1, 'rate' => 500_000, 'discount_type' => 'percentage', 'discount_value' => 100, 'tax_id' => $this->ppn->id]],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);

        $line = $invoice->items->first();
        $this->assertEquals(500_000, (float) $line->discount_amount);
        $this->assertEquals(0, (float) $line->net_amount);
        $this->assertEquals(0, (float) $line->tax_amount);
        $this->assertEquals(0, (float) $invoice->grand_total);
    }

    public function test_editing_a_submitted_invoice_lines_discount_recomputes_and_reposts(): void
    {
        $invoice = $this->invoiceService->create([
            'invoice_type' => InvoiceType::GOODS->value,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => $this->branch->id,
            'items' => [['item_id' => $this->item->id, 'qty' => 1, 'rate' => 1_000_000, 'tax_id' => $this->ppn->id]],
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        ]);
        $this->invoiceService->submit($invoice);
        $lineId = $invoice->items->first()->id;

        $updated = $this->invoiceService->update($invoice, [
            'items' => [['id' => $lineId, 'discount_type' => 'amount', 'discount_value' => 100_000, 'tax_id' => $this->ppn->id]],
        ]);

        $line = $updated->items->first();
        $this->assertEquals(100_000, (float) $line->discount_amount);
        $this->assertEquals(900_000, (float) $line->net_amount);
        $this->assertEquals(99_000, (float) $line->tax_amount);
        $this->assertEquals(999_000, (float) $updated->grand_total);

        $ar = $updated->accountsReceivable;
        $this->assertEquals(999_000, (float) $ar->amount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InvoiceDiscountTest`
Expected: FAIL on every test.

- [ ] **Step 3: Update `StoreInvoiceRequest`**

Replace the existing header discount rules and `items.*` tax/description rules block with:

```php
// Discount is per-line only now (never a document-wide figure) — these three fields are no
// longer valid client input on create.
'discount_type' => ['prohibited'],
'discount_amount' => ['prohibited'],
'discount_percentage' => ['prohibited'],
...
'items.*.description' => [Rule::when($isTransportation, 'required', 'prohibited'), 'string'],
// Transportation only — the MiscellaneousItem's own UOM, carried along for display/print.
'items.*.uom' => [Rule::when($isTransportation, 'nullable', 'prohibited'), 'string', 'max:50'],
'items.*.item_id' => [Rule::when($isDirectGoods, 'required', 'prohibited'), 'uuid', 'exists:items,id'],
'items.*.qty' => ['required_with:items', 'integer', 'min:1'],
'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
// Transportation and Direct Goods only — per-line discount, replacing the old header field.
'items.*.discount_type' => [Rule::when($isTransportationOrDirectGoods, 'nullable', Rule::enum(\App\Enums\DiscountType::class)), 'prohibited_unless:invoice_type,'.\App\Enums\InvoiceType::TRANSPORTATION->value.','.\App\Enums\InvoiceType::GOODS->value],
'items.*.discount_value' => [Rule::when($isTransportationOrDirectGoods, 'nullable', 'numeric'), 'min:0'],
// Transportation gains a per-line tax select (previously header-only); Direct Goods already had this.
'items.*.tax_id' => [Rule::when($isTransportationOrDirectGoods, 'nullable', 'prohibited'), 'uuid', Rule::exists('taxes', 'id')->where('is_active', true)],
```

(The `discount_type`/`discount_value` conditional above is written slightly awkwardly to stay inside the existing `Rule::when($isTransportationOrDirectGoods, ...)` pattern used throughout this file — simplify to two plain `Rule::when($isTransportationOrDirectGoods, 'nullable', 'prohibited')` rules for `discount_type` and a matching one for `discount_value`, matching every other conditional field in this file exactly; don't introduce a new pattern.)

Note the existing single `'items.*.tax_id'` rule (today scoped to `$isDirectGoods` only, line 60) must be widened to `$isTransportationOrDirectGoods` — Transportation lines now carry their own tax instead of a header-level one.

- [ ] **Step 4: Update `createGoods()`**

Change the per-line copy (inside the `foreach ($deliveries as $delivery) { foreach ($delivery->items as $line) {...} }` loop) to also copy the discount fields verbatim (same frozen-snapshot treatment as `tax_id`/`tax_amount`):

```php
'discount_type' => $line->discount_type,
'discount_value' => $line->discount_value,
'discount_amount' => $line->discount_amount,
'net_amount' => $line->net_amount,
```

Change the header `$subtotal`/discount/tax block before `$invoice = $this->invoiceRepository->create([...])`:

```php
$subtotal = $deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('amount'));
$discountAmount = round($deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('discount_amount')), 2);
$taxAmount = round($deliveries->sum(fn ($delivery) => (float) $delivery->items->sum('tax_amount')), 2);
$grandTotal = round($subtotal - $discountAmount + $taxAmount, 2);
```

And in the `invoiceRepository->create([...])` call, replace the `resolveDiscount()`-derived fields:

```php
'subtotal' => $subtotal,
'discount_amount' => $discountAmount,
'discount_type' => \App\Enums\DiscountType::AMOUNT->value,
'discount_percentage' => null,
'tax_base' => round($subtotal - $discountAmount, 2),
'tax_id' => null,
'tax_amount' => $taxAmount,
'grand_total' => $grandTotal,
```

(delete the `[$discountAmount, $discountType, $discountPercentage] = $this->resolveDiscount($data, $subtotal);` line entirely — Goods invoices no longer accept a header discount input at all, enforced by Step 3's `prohibited` rule).

- [ ] **Step 5: Run the Goods-invoice test to verify it passes**

Run: `php artisan test --filter=test_goods_invoice_inherits_discount_from_its_delivery_lines`
Expected: PASS

- [ ] **Step 6: Update `createDirectGoods()`**

Inside the `foreach ($data['items'] as $line) { ... }` loop:

```php
$qty = (float) $line['qty'];
$rate = (float) $line['rate'];
$grossAmount = $qty * $rate;
[$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
[$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, $item, 'sales_tax_id', $netAmount);

$subtotal += $grossAmount;
$discountTotal += $discountAmount;
$taxAmountTotal += $taxAmount;
$lines[] = ['item' => $item, 'qty' => $qty, 'rate' => $rate, 'amount' => $grossAmount, 'discount_type' => $discountType, 'discount_value' => $discountValue, 'discount_amount' => $discountAmount, 'net_amount' => $netAmount, 'tax_id' => $taxId, 'tax_amount' => $taxAmount];
```

(add `$discountTotal = 0.0;` alongside the existing `$subtotal = 0.0; $taxAmountTotal = 0.0;` initializer). Replace the header-resolution block:

```php
$taxAmountTotal = round($taxAmountTotal, 2);
$discountTotal = round($discountTotal, 2);
$grandTotal = round($subtotal - $discountTotal + $taxAmountTotal, 2);
```

(remove the `resolveDiscount()` call). In the `invoiceRepository->create([...])` call:

```php
'subtotal' => $subtotal,
'discount_amount' => $discountTotal,
'discount_type' => \App\Enums\DiscountType::AMOUNT->value,
'discount_percentage' => null,
'tax_base' => round($subtotal - $discountTotal, 2),
'tax_id' => null,
'tax_amount' => $taxAmountTotal,
'grand_total' => $grandTotal,
```

In the `foreach ($lines as $line) { $this->invoiceItemRepository->create([...]) }` loop, add:

```php
'discount_type' => $line['discount_type']->value,
'discount_value' => $line['discount_value'],
'discount_amount' => $line['discount_amount'],
'net_amount' => $line['net_amount'],
```

- [ ] **Step 7: Run the Direct Goods test**

Run: `php artisan test --filter=test_direct_goods_invoice_computes_tax_on_net_per_line`
Expected: PASS

- [ ] **Step 8: Rewrite `createTransportation()`** — per-line discount AND per-line tax (replacing the header tax select entirely)

```php
protected function createTransportation(array $data): Invoice
{
    return DB::transaction(function () use ($data) {
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxAmountTotal = 0.0;
        $lines = [];

        foreach ($data['items'] as $line) {
            $qty = (float) $line['qty'];
            $rate = (float) $line['rate'];
            $grossAmount = $qty * $rate;
            [$discountType, $discountValue, $discountAmount, $netAmount] = $this->discountService->resolveLineDiscount($line, $grossAmount);
            [$taxId, $taxAmount] = $this->taxService->resolveLineTax($line, null, '', $netAmount);

            $subtotal += $grossAmount;
            $discountTotal += $discountAmount;
            $taxAmountTotal += $taxAmount;
            $lines[] = compact('qty', 'rate', 'grossAmount', 'discountType', 'discountValue', 'discountAmount', 'netAmount', 'taxId', 'taxAmount') + ['line' => $line];
        }

        $discountTotal = round($discountTotal, 2);
        $taxAmountTotal = round($taxAmountTotal, 2);
        $grandTotal = round($subtotal - $discountTotal + $taxAmountTotal, 2);

        if ($grandTotal < 0) {
            throw new BusinessException('Grand total cannot be negative.');
        }

        $invoice = $this->invoiceRepository->create([
            'delivery_id' => null,
            'sales_order_id' => null,
            'branch_id' => $data['branch_id'] ?? null,
            'customer_id' => $data['customer_id'],
            'sales_person_id' => $data['sales_person_id'] ?? null,
            'invoice_type' => InvoiceType::TRANSPORTATION->value,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'],
            'terms_of_payment_id' => $data['terms_of_payment_id'] ?? null,
            'subtotal' => $subtotal,
            'discount_amount' => $discountTotal,
            'discount_type' => DiscountType::AMOUNT->value,
            'discount_percentage' => null,
            'tax_base' => round($subtotal - $discountTotal, 2),
            // Header tax_id is no longer meaningful — tax is per-line now, same convention Goods
            // already uses. tax_amount above is the authoritative sum of the lines.
            'tax_id' => null,
            'tax_amount' => $taxAmountTotal,
            'grand_total' => $grandTotal,
            'remarks' => $data['remarks'] ?? null,
            'reference_1' => $data['reference_1'] ?? null,
            'reference_2' => $data['reference_2'] ?? null,
        ]);

        foreach ($lines as $built) {
            $this->invoiceItemRepository->create([
                'invoice_id' => $invoice->id,
                'delivery_item_id' => null,
                'item_id' => null,
                'item_code' => null,
                'item_name' => $built['line']['description'],
                'uom' => $built['line']['uom'] ?? null,
                'rate' => $built['rate'],
                'qty' => $built['qty'],
                'amount' => round($built['grossAmount'], 2),
                'discount_type' => $built['discountType']->value,
                'discount_value' => $built['discountValue'],
                'discount_amount' => $built['discountAmount'],
                'net_amount' => $built['netAmount'],
                'tax_id' => $built['taxId'],
                'tax_amount' => round($built['taxAmount'], 2),
            ]);
        }

        $invoice = $invoice->fresh(self::EAGER);
        $this->auditLogService->record('created', 'invoice', "Created Invoice \"{$invoice->document_number}\".");

        return $invoice;
    });
}
```

Add `use App\Enums\DiscountType;` to the top of `InvoiceService.php` if not already present (it likely already imports this for the old `resolveDiscount()`).

- [ ] **Step 9: Run the Transportation tests**

Run: `php artisan test --filter=InvoiceDiscountTest`
Expected: `test_transportation_invoice_computes_per_line_discount_and_per_line_tax` and `test_hundred_percent_discount_line_produces_zero_tax_and_zero_net` PASS. `test_editing_a_submitted_invoice_lines_discount_recomputes_and_reposts` still FAILs (Step 10 below).

- [ ] **Step 10: Add discount editing to `applyItemChanges()`**

In `applyItemChanges()` (the one shared place Draft and Submitted Invoice lines are edited), after computing `$amount = round($qty * $rate, 2);`, insert discount resolution before the tax calculation, and use the net amount for tax:

```php
$qty = (int) round((float) ($incoming['qty'] ?? $line->qty));

if ($qty <= 0) {
    throw new BusinessException("Qty untuk item \"{$line->item_name}\" harus lebih dari 0.");
}

$rate = (float) ($incoming['rate'] ?? $line->rate);
$amount = round($qty * $rate, 2);

$discountType = array_key_exists('discount_type', $incoming) ? \App\Enums\DiscountType::from($incoming['discount_type']) : \App\Enums\DiscountType::from($line->discount_type);
$discountValue = array_key_exists('discount_value', $incoming) ? (float) $incoming['discount_value'] : (float) $line->discount_value;
['discount_amount' => $discountAmount, 'net_amount' => $netAmount] = $this->discountService->calculate($amount, $discountType, $discountValue);

$taxId = array_key_exists('tax_id', $incoming) ? $incoming['tax_id'] : $line->tax_id;
$taxAmount = $taxId !== null ? round($this->taxService->calculate($netAmount, $this->taxRepository->findOrFail($taxId))['tax_amount'], 2) : 0.0;

$attributes = [
    'qty' => $qty,
    'rate' => $rate,
    'amount' => $amount,
    'discount_type' => $discountType->value,
    'discount_value' => $discountValue,
    'discount_amount' => $discountAmount,
    'net_amount' => $netAmount,
    'tax_id' => $taxId,
    'tax_amount' => $taxAmount,
];
```

Inject `DiscountService $discountService` into `InvoiceService`'s constructor alongside `TaxService $taxService`.

Then, in `updateSubmitted()`, change the header `$subtotal`/`$taxAmount` recompute block (where it currently does `$subtotal = round((float) $invoice->items()->sum('amount'), 2); $taxAmount = round((float) $invoice->items()->sum('tax_amount'), 2);`) to also recompute discount from the lines, replacing the old `resolveDiscount()`-based branch entirely:

```php
$subtotal = round((float) $invoice->items()->sum('amount'), 2);
$discountAmount = round((float) $invoice->items()->sum('discount_amount'), 2);
$taxAmount = round((float) $invoice->items()->sum('tax_amount'), 2);
$grandTotal = round($subtotal - $discountAmount + $taxAmount, 2);
```

(delete the entire `if (array_key_exists('discount_type', $data) || ...) { [$discountAmount, ...] = $this->resolveDiscount(...) } else { ... }` block — discount is now always derived from the lines, never a header input). Update `$headerData['discount_amount'] = $discountAmount;` accordingly and drop `discount_type`/`discount_percentage` from `$headerData` (they're no longer meaningful per-document values; leave the columns as-is on the row, don't null them out).

Apply the identical change to the plain `update()` (Draft) method's discount/tax recompute block.

- [ ] **Step 11: Remove the now-dead `resolveDiscount()`/`resolveTax()` methods**

Both are no longer called anywhere once Steps 4, 6, 8, and 10 are done — confirm with `grep -n "resolveDiscount\|resolveTax" app/Services/InvoiceService.php` (expect zero remaining call sites, only the method definitions themselves) and delete both method bodies (lines ~614-657).

- [ ] **Step 12: Run the full `InvoiceDiscountTest` file**

Run: `php artisan test --filter=InvoiceDiscountTest`
Expected: PASS (5 tests)

- [ ] **Step 13: Run the full existing Invoice test suite**

Run: `php artisan test --filter=Invoice`
Expected: PASS. Any failure here is almost certainly one of:
(a) a test that passed `discount_amount`/`discount_type`/`discount_percentage` directly in a create/update payload — now `prohibited` by `StoreInvoiceRequest`, but tests that call `InvoiceService` directly (bypassing the FormRequest, like `InvoiceWorkflowTest` does throughout) are unaffected by that rule and will just get `discount_amount` defaulting to 0 via `resolveLineDiscount()`'s "key absent" branch — check those still produce the same numeric result as before;
(b) a test asserting the old header tax select behavior for Transportation — Transportation no longer has one, tests must pass `tax_id` per line instead.
Fix any such test by updating it to the new per-line shape, matching this plan's own `InvoiceDiscountTest` examples.

- [ ] **Step 14: Run the entire backend suite**

Run: `php artisan test`
Expected: PASS, full count.

- [ ] **Step 15: Commit**

```bash
git add app/Services/InvoiceService.php app/Http/Requests/StoreInvoiceRequest.php tests/Feature/InvoiceDiscountTest.php tests/Feature/InvoiceWorkflowTest.php
git commit -m "feat(sales): per-line discount + tax on Invoice (Goods/Direct Goods/Transportation), PPN now on net"
```

---

## Self-Review Checklist (run before handing this plan off)

- **Spec coverage:** central calc service (Task 1) ✓, schema (Task 2) ✓, SO per-line (Task 4) ✓, DO allocation rule (Task 5) ✓, SI/TR per-line incl. inheritance from DO (Task 6) ✓, Submitted-Invoice discount editing (Task 6 Step 10) ✓, rounding rule documented and tested ✓, reference example tested in 3 places (DiscountService, SalesOrder, Invoice) ✓. **Not covered by this plan, by design (separate follow-up plans per the agreed phasing):** UI (discount input columns on SO/DO/SI editors), print (SI/TR paper DISC row wiring to real per-line data), reports/dashboard verification pass, the backfill command. Do not start those until this plan's tasks are all merged and reviewed.
- **Placeholder scan:** every step above has real, complete code — no TBD/TODO.
- **Type consistency:** `DiscountService::calculate()` and `resolveLineDiscount()` signatures are used identically across Tasks 4/5/6. Column names (`discount_type`, `discount_value`, `discount_amount`, `net_amount`) are identical across all three item tables and all call sites.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-10-02-discount-tax-phase-a-backend.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
