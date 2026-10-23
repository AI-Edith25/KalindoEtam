# Purchase Invoice: Merge GR/Direct Flows Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge Purchase Invoice's "From Goods Receipt" and "Direct/Non-Stock" creation flows into one flow where Goods Receipt selection is optional and non-stock lines can be added regardless, producing `goods_receipt` / `direct` / `mixed` invoices from the same form.

**Architecture:** Backend: `PurchaseInvoiceSource` gains a `MIXED` case computed from which line groups are actually present; `PurchaseInvoiceService::create()`/`update()` collapse from two branched code paths into one that independently builds a stock-line group (from `goods_receipt_ids`) and a non-stock-line group (from `items`), whichever are present; `PurchaseInvoice::journalLines()` emits both existing posting algorithms side by side instead of branching on `source`. Frontend: the two zod schemas/forms/line-tables collapse into one discriminated-union schema, one form, one table rendering both row kinds with a distinguishing badge, and the create flow becomes Supplier → optional Goods Receipt selection (with Skip) → unified line items.

**Tech Stack:** Laravel 11 (PHP), PHPUnit + sqlite in-memory (`php artisan test`), React + TypeScript, react-hook-form + zod, TanStack Query.

**Reference spec:** `docs/superpowers/specs/2026-10-23-purchase-invoice-merged-flow-design.md`

---

## Backend

### Task 1: Add `MIXED` to `PurchaseInvoiceSource`

**Files:**
- Modify: `backend/laravel-api/app/Enums/PurchaseInvoiceSource.php`

- [ ] **Step 1: Edit the enum**

```php
<?php

namespace App\Enums;

/**
 * GOODS_RECEIPT — every line is stock, copied from one or more Goods Receipts. DIRECT — every
 * line is non-stock, posts straight to a Chart-of-Accounts Expense/Liability account (vehicle
 * repairs, services, withholding-tax deductions, etc.). MIXED — both kinds of line on the same
 * invoice. All three share the same 'purchase_invoice' Naming Series — see
 * PurchaseInvoice::documentType(). Not accepted from the client — PurchaseInvoiceService::create()/
 * update() always derive this from which line groups are actually present.
 */
enum PurchaseInvoiceSource: string
{
    case GOODS_RECEIPT = 'goods_receipt';
    case DIRECT = 'direct';
    case MIXED = 'mixed';
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/laravel-api/app/Enums/PurchaseInvoiceSource.php
git commit -m "feat(purchase): add MIXED case to PurchaseInvoiceSource"
```

---

### Task 2: Derive `line_type` on `PurchaseInvoiceItem` and expose it in the Resource

**Files:**
- Modify: `backend/laravel-api/app/Models/PurchaseInvoiceItem.php`
- Modify: `backend/laravel-api/app/Http/Resources/PurchaseInvoiceItemResource.php`
- Test: `backend/laravel-api/tests/Feature/PurchaseInvoiceWorkflowTest.php` (assertion added in Task 4's new Mixed test, not a standalone test here — this accessor has no business logic of its own to fail on, it's a one-line derivation covered by that later assertion)

- [ ] **Step 1: Add the accessor**

Edit `backend/laravel-api/app/Models/PurchaseInvoiceItem.php`:

```php
    /** Direct/Non-Stock lines only — the expense account this line posts to. Null for a Goods Receipt-sourced line. */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    /**
     * 'stock' vs 'non_stock' — derived from goods_receipt_item_id rather than a stored column:
     * the invariant already holds for every row (enforced by
     * PurchaseInvoiceService::resolveDirectLineAccount() on the non-stock path), so a redundant
     * column would only risk drifting out of sync.
     */
    public function getLineTypeAttribute(): string
    {
        return $this->goods_receipt_item_id !== null ? 'stock' : 'non_stock';
    }
```

- [ ] **Step 2: Expose it in the Resource**

Edit `backend/laravel-api/app/Http/Resources/PurchaseInvoiceItemResource.php`:

```php
            'item_qty_category' => $this->whenLoaded('item', fn () => $this->item->qty_category),
            'line_type' => $this->line_type,
            'chart_of_account_id' => $this->chart_of_account_id,
```

- [ ] **Step 3: Commit**

```bash
git add backend/laravel-api/app/Models/PurchaseInvoiceItem.php backend/laravel-api/app/Http/Resources/PurchaseInvoiceItemResource.php
git commit -m "feat(purchase): derive line_type (stock/non_stock) on PurchaseInvoiceItem"
```

---

### Task 3: Rewrite Store/Update/Index Purchase Invoice validation for the unified contract

**Files:**
- Modify: `backend/laravel-api/app/Http/Requests/StorePurchaseInvoiceRequest.php`
- Modify: `backend/laravel-api/app/Http/Requests/UpdatePurchaseInvoiceRequest.php`
- Modify: `backend/laravel-api/app/Http/Requests/IndexPurchaseInvoiceRequest.php`

- [ ] **Step 1: Rewrite `StorePurchaseInvoiceRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Supplier is always required up front now — both the Goods Receipt list (Step 2 of
            // the editor) and the non-stock Account dropdown filter against it. `source` is no
            // longer accepted from the client — PurchaseInvoiceService::create() derives
            // goods_receipt/direct/mixed from whichever of goods_receipt_ids/items is present.
            'supplier_id' => ['required', 'uuid', 'exists:suppliers,id'],

            // Goods Receipt-sourced (stock) lines — optional. All selected Goods Receipts must
            // belong to the chosen Supplier, be submitted, and not yet invoiced — enforced in
            // PurchaseInvoiceService::buildStockLines() (business logic).
            'goods_receipt_ids' => ['sometimes', 'array'],
            'goods_receipt_ids.*' => ['uuid', 'distinct', 'exists:goods_receipts,id'],
            // Rate overrides for stock lines — Qty/Item still come from the Goods Receipt
            // untouched, only Rate is user-editable. A separate key from `items` below, which is
            // the non-stock line shape — the two never overlap.
            'item_rates' => ['sometimes', 'array'],
            'item_rates.*.goods_receipt_item_id' => ['required', 'uuid', 'exists:goods_receipt_items,id'],
            'item_rates.*.rate' => ['required', 'numeric', 'min:0'],
            // Manual header tax figure for the stock portion only — Goods Receipt items carry no
            // tax snapshot to resolve this from. Non-stock lines carry their own tax_id instead
            // (summed server-side).
            'tax_amount' => ['nullable', 'numeric', 'min:0'],

            // Non-stock lines — optional. Each posts straight to a Chart-of-Accounts Expense or
            // Liability account. Account type/active-ness is validated in PurchaseInvoiceService
            // (needs the Chart of Account lookup, not available here).
            'items' => ['sometimes', 'array'],
            'items.*.chart_of_account_id' => ['required_with:items', 'uuid', 'exists:chart_of_accounts,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            'invoice_date' => ['required', 'date'],
            // Nullable — defaults to the Supplier's Terms of Payment when omitted
            // (PurchaseInvoiceService::resolveDueDate()).
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'attention' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
```

(The "at least one of goods_receipt_ids/items must be non-empty" rule is enforced in the Service via `BusinessException`, matching this codebase's existing convention of pushing cross-field business rules — e.g. `resolveDirectLineAccount()` — to the Service layer rather than the Request.)

- [ ] **Step 2: Rewrite `UpdatePurchaseInvoiceRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_date' => ['sometimes', 'required', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],

            // Only takes effect while the invoice has no stock (Goods Receipt-sourced) lines —
            // PurchaseInvoiceService::update() otherwise leaves supplier_id untouched.
            'supplier_id' => ['sometimes', 'uuid', 'exists:suppliers,id'],
            'attention' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Full replace of this invoice's non-stock lines only — stock lines are immutable
            // post-create and untouched by this key. Omit entirely to leave existing non-stock
            // lines as they are.
            'items' => ['sometimes', 'array'],
            'items.*.chart_of_account_id' => ['required_with:items', 'uuid', 'exists:chart_of_accounts,id'],
            'items.*.description' => ['required_with:items', 'string', 'max:255'],
            'items.*.uom' => ['nullable', 'string', 'max:50'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.rate' => ['required_with:items', 'numeric', 'min:0'],
            'items.*.tax_id' => ['sometimes', 'nullable', 'uuid', 'exists:taxes,id'],

            // Rate overrides for existing stock lines — see Store rule's own comment.
            'item_rates' => ['sometimes', 'array'],
            'item_rates.*.goods_receipt_item_id' => ['required', 'uuid', 'exists:goods_receipt_items,id'],
            'item_rates.*.rate' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

- [ ] **Step 3: Allow `mixed` on the list/export filter**

Edit `backend/laravel-api/app/Http/Requests/IndexPurchaseInvoiceRequest.php`:

```php
            'source' => ['sometimes', 'nullable', 'in:goods_receipt,direct,mixed'],
```

- [ ] **Step 4: Commit**

```bash
git add backend/laravel-api/app/Http/Requests/StorePurchaseInvoiceRequest.php backend/laravel-api/app/Http/Requests/UpdatePurchaseInvoiceRequest.php backend/laravel-api/app/Http/Requests/IndexPurchaseInvoiceRequest.php
git commit -m "feat(purchase): unify Purchase Invoice request validation for mixed lines"
```

---

### Task 4: Rewrite `PurchaseInvoiceService::create()` to build both line groups at once

This is the core service rewrite. We update the existing test call sites first (mechanical — they all need an explicit `supplier_id` now, since Supplier is always chosen up front), then add new Mixed-specific tests, then make them all pass with the rewritten service.

**Files:**
- Modify: `backend/laravel-api/app/Services/PurchaseInvoiceService.php`
- Test: `backend/laravel-api/tests/Feature/PurchaseInvoiceWorkflowTest.php`

- [ ] **Step 1: Update existing GR-creation test call sites to pass `supplier_id`**

These seven call sites share byte-identical bodies (`'goods_receipt_ids' => [$goodsReceipt->id], 'invoice_date' => ..., 'due_date' => ...`) across `test_rate_can_be_edited_when_updating_a_draft_goods_receipt_sourced_invoice`, `test_deleting_a_draft_invoice_frees_its_goods_receipt_for_reinvoicing` (both calls), `test_already_invoiced_goods_receipt_cannot_be_invoiced_again` (both calls), `test_cancel_deletes_accounts_payable_when_unpaid`, and `test_cancel_blocked_once_payment_applied`. One `replace_all` edit covers all seven:

Old:
```php
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

New:
```php
            'goods_receipt_ids' => [$goodsReceipt->id],
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

(Apply with `replace_all: true` — all seven occurrences get the identical fix.)

- [ ] **Step 2: Fix the two `[$first->id, $second->id]` call sites**

These appear in `test_multiple_goods_receipts_from_same_supplier_can_be_combined_into_one_invoice` and `test_mixed_supplier_goods_receipts_cannot_be_combined` with byte-identical text (both use variables literally named `$first`/`$second`). Both need the same addition — in the mixed-supplier test, `$this->supplier->id` becomes "the chosen Supplier", and `$second` (built with `supplier: $otherSupplier`) now fails the new "Goods Receipt does not belong to the selected Supplier" check instead of the old "GRs span multiple suppliers" check, but still throws `BusinessException` either way, so the test's assertion is unaffected.

Old:
```php
            'goods_receipt_ids' => [$first->id, $second->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

New:
```php
            'goods_receipt_ids' => [$first->id, $second->id],
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

(Apply with `replace_all: true` — both occurrences get the identical fix.)

- [ ] **Step 3: Fix the remaining three unique call sites**

In `test_purchase_invoice_created_from_goods_receipt_and_submit_creates_accounts_payable_and_posts_journal`:

Old:
```php
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => 10000,
        ]);
```

New:
```php
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'tax_amount' => 10000,
        ]);
```

In `test_rate_can_be_edited_at_create_while_qty_stays_locked_to_the_goods_receipt`:

Old:
```php
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'item_rates' => [['goods_receipt_item_id' => $grItem->id, 'rate' => 21000]],
        ]);
```

New:
```php
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$goodsReceipt->id],
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'item_rates' => [['goods_receipt_item_id' => $grItem->id, 'rate' => 21000]],
        ]);
```

In `test_direct_and_po_linked_goods_receipts_from_same_supplier_can_be_combined_into_one_invoice`:

Old:
```php
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$poLinked->id, $direct->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

New:
```php
        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$poLinked->id, $direct->id],
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

In `test_non_submitted_goods_receipt_cannot_be_invoiced`:

Old:
```php
        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$draftGoodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

New:
```php
        $this->purchaseInvoiceService->create([
            'goods_receipt_ids' => [$draftGoodsReceipt->id],
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
        ]);
```

- [ ] **Step 4: Drop the now-unnecessary `'source' => 'direct'` from every Direct test**

That exact line appears identically in all eight Direct-invoice tests. One `replace_all` edit removes it everywhere (the Service no longer reads `source` from input at all — it's always derived):

Old:
```php
                'source' => 'direct',
```

New: *(delete the line entirely — replace with nothing)*

(Apply with `replace_all: true`.)

- [ ] **Step 5: Add new tests for Mixed, zero-line, and cross-supplier-after-pick scenarios**

Add these methods to `PurchaseInvoiceWorkflowTest` (anywhere after `accountId()`, e.g. right before `test_direct_invoice_creates_and_submits_without_stock_or_goods_receipt`):

```php
    public function test_mixed_invoice_combines_goods_receipt_and_non_stock_lines_and_posts_combined_journal(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000); // 100000

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'supplier_id' => $this->supplier->id,
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
            'items' => [
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Biaya Pengangkutan', 'qty' => 1, 'rate' => 50000],
            ],
        ]);

        $this->assertEquals('mixed', $purchaseInvoice->source->value);
        $this->assertEquals(150000, (float) $purchaseInvoice->subtotal);
        $this->assertEquals(150000, (float) $purchaseInvoice->grand_total);
        $this->assertCount(2, $purchaseInvoice->items);

        $stockItem = $purchaseInvoice->items->firstWhere('goods_receipt_item_id', '!=', null);
        $nonStockItem = $purchaseInvoice->items->firstWhere('goods_receipt_item_id', null);
        $this->assertEquals('stock', $stockItem->line_type);
        $this->assertEquals('non_stock', $nonStockItem->line_type);

        $purchaseInvoice = $this->purchaseInvoiceService->submit($purchaseInvoice);

        $journalEntry = JournalEntry::query()->where('reference_type', 'purchase_invoice')->where('reference_id', $purchaseInvoice->id)->firstOrFail();
        $this->assertEquals(150000, (float) $journalEntry->total_debit);
        $this->assertEquals(150000, (float) $journalEntry->total_credit);

        $lines = $journalEntry->lines()->with('chartOfAccount')->get();
        $this->assertEquals(100000, (float) $lines->firstWhere('chartOfAccount.code', '5100')->debit);
        $this->assertEquals(50000, (float) $lines->firstWhere('chartOfAccount.code', '6100')->debit);
        $this->assertEquals(150000, (float) $lines->firstWhere('chartOfAccount.code', '210.01')->credit);
    }

    public function test_create_rejects_an_invoice_with_zero_lines(): void
    {
        $this->expectException(BusinessException::class);

        $this->purchaseInvoiceService->create([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
        ]);
    }

    public function test_goods_receipt_from_a_different_supplier_than_the_chosen_one_is_rejected(): void
    {
        $otherSupplier = Supplier::query()->create(['supplier_code' => 'S003', 'supplier_name' => 'Third Supplier']);
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 2, rate: 20000, supplier: $otherSupplier);

        $this->expectException(BusinessException::class);

        $this->purchaseInvoiceService->create([
            'supplier_id' => $this->supplier->id,
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
        ]);
    }
```

- [ ] **Step 6: Run the tests to confirm they fail against the old service**

Run: `cd backend/laravel-api && php artisan test --filter=PurchaseInvoiceWorkflowTest`
Expected: FAIL — the new tests fail because `source`/`MIXED` handling doesn't exist yet, and several of Step 1–3's edited tests fail because the old service never reads `supplier_id` for the GR path (it derives supplier from the anchor Goods Receipt, so the new `supplier_id` key is simply ignored, which is harmless there) — but the two new tests and the Mixed test will clearly fail.

- [ ] **Step 7: Rewrite `PurchaseInvoiceService::create()` and add `buildStockLines()`**

Edit `backend/laravel-api/app/Services/PurchaseInvoiceService.php` — replace the `create()` method and the `createFromGoodsReceipts()` method (keep `createDirect()` removed too — its logic folds into the new `create()`; `buildDirectLines()`, `resolveDirectLineAccount()`, `assertPositiveGrandTotal()`, `resolveDueDate()` are all kept unchanged):

Old (the whole `create()` through the end of `createDirect()`, i.e. original lines 60–196):
```php
    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $source = isset($data['source']) ? PurchaseInvoiceSource::from($data['source']) : PurchaseInvoiceSource::GOODS_RECEIPT;

            return $source === PurchaseInvoiceSource::DIRECT
                ? $this->createDirect($data)
                : $this->createFromGoodsReceipts($data);
        });
    }

    protected function createFromGoodsReceipts(array $data): PurchaseInvoice
    {
        // Row-locks each Goods Receipt for the rest of this transaction, then reloads its
        // relations fresh (not from any pre-lock snapshot) — closes a race where two concurrent
        // requests invoicing the same Goods Receipt could otherwise both pass the "not yet
        // invoiced" check below before either's purchase_invoice_goods_receipts row actually
        // commits, crashing the second one on that pivot's unique constraint instead of getting
        // a clean "already invoiced" message.
        $goodsReceipts = collect($data['goods_receipt_ids'])
            ->map(fn (string $id) => GoodsReceipt::query()->lockForUpdate()->findOrFail($id)->load(['supplier', 'purchaseOrder', 'items', 'purchaseInvoices']))
            ->sortBy(fn ($goodsReceipt) => [$goodsReceipt->receipt_date, $goodsReceipt->id])
            ->values();

        foreach ($goodsReceipts as $goodsReceipt) {
            if ($goodsReceipt->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} must be submitted before it can be invoiced.");
            }

            if ($goodsReceipt->purchaseInvoices->isNotEmpty()) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} has already been invoiced.");
            }
        }

        if ($goodsReceipts->pluck('supplier_id')->unique()->count() > 1) {
            throw new BusinessException('All selected Goods Receipts must belong to the same Supplier.');
        }

        $anchor = $goodsReceipts->first();

        // Rate overrides keyed by the source Goods Receipt item — see StorePurchaseInvoiceRequest's
        // own comment. Qty/item identity always come from the Goods Receipt line untouched.
        $rateOverrides = collect($data['item_rates'] ?? [])->pluck('rate', 'goods_receipt_item_id');

        $lines = $goodsReceipts->flatMap(fn ($goodsReceipt) => $goodsReceipt->items)->map(function ($line) use ($rateOverrides) {
            $rate = $rateOverrides->has($line->id) ? (float) $rateOverrides->get($line->id) : (float) $line->rate;

            return [
                'goods_receipt_item_id' => $line->id,
                'item_id' => $line->item_id,
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
                'uom' => $line->uom,
                'uom_factor' => $line->uom_factor,
                'rate' => $rate,
                'qty' => $line->qty,
                'amount' => round((float) $line->qty * $rate, 2),
            ];
        });

        $subtotal = round((float) $lines->sum('amount'), 2);
        $taxAmount = (float) ($data['tax_amount'] ?? 0);
        $grandTotal = $subtotal + $taxAmount;

        if ($grandTotal < 0) {
            throw new BusinessException('Grand total cannot be negative.');
        }

        $purchaseInvoice = $this->purchaseInvoiceRepository->create([
            'source' => PurchaseInvoiceSource::GOODS_RECEIPT,
            'goods_receipt_id' => $anchor->id,
            'purchase_order_id' => $anchor->purchase_order_id,
            'supplier_id' => $anchor->supplier_id,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'],
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $grandTotal,
            'reference_number' => $data['reference_number'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        foreach ($lines as $line) {
            $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
        }

        $purchaseInvoice->goodsReceipts()->sync($goodsReceipts->pluck('id')->all());
        // A Direct Receipt (no source PO) has a null purchase_order_id — filter it out before sync(),
        // otherwise the pivot insert fails its FK constraint on a null/empty id.
        $purchaseInvoice->purchaseOrders()->sync($goodsReceipts->pluck('purchase_order_id')->filter()->unique()->all());

        $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
        $this->auditLogService->record('created', 'purchase_invoice', "Created Purchase Invoice \"{$purchaseInvoice->document_number}\".");

        return $purchaseInvoice;
    }

    /**
     * No Goods Receipt/Purchase Order/stock involved at all — lines post straight to a
     * Chart-of-Accounts expense account (vehicle repairs, services, etc.). Never touches
     * GoodsReceiptRepository, StockLedgerService or FifoLayerService.
     */
    protected function createDirect(array $data): PurchaseInvoice
    {
        $supplier = $this->supplierRepository->findOrFail($data['supplier_id']);
        $dueDate = $this->resolveDueDate($data, $supplier->id, $data['invoice_date']);

        $lines = $this->buildDirectLines($data['items']);
        $subtotal = round((float) array_sum(array_column($lines, 'amount')), 2);
        $taxAmount = round((float) array_sum(array_column($lines, 'tax_amount')), 2);
        $this->assertPositiveGrandTotal($subtotal + $taxAmount);

        $purchaseInvoice = $this->purchaseInvoiceRepository->create([
            'source' => PurchaseInvoiceSource::DIRECT,
            'goods_receipt_id' => null,
            'purchase_order_id' => null,
            'supplier_id' => $supplier->id,
            'invoice_date' => $data['invoice_date'],
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $subtotal + $taxAmount,
            'reference_number' => $data['reference_number'] ?? null,
            'attention' => $data['attention'] ?? null,
            'department' => $data['department'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ]);

        foreach ($lines as $line) {
            $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
        }

        $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
        $this->auditLogService->record('created', 'purchase_invoice', "Created Purchase Invoice \"{$purchaseInvoice->document_number}\".");

        return $purchaseInvoice;
    }
```

New:
```php
    /**
     * Builds whichever of a stock-line group (from goods_receipt_ids) and a non-stock-line group
     * (from items) are present, derives goods_receipt/direct/mixed from the result, and combines
     * their totals into one invoice. Supplier is always chosen up front now (the editor's Step 1,
     * before Goods Receipt selection), so it's always present in $data regardless of which groups
     * end up populated.
     */
    public function create(array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($data) {
            $supplier = $this->supplierRepository->findOrFail($data['supplier_id']);

            $goodsReceiptIds = $data['goods_receipt_ids'] ?? [];
            $directItems = $data['items'] ?? [];

            if (empty($goodsReceiptIds) && empty($directItems)) {
                throw new BusinessException('Invoice must have at least one line item.');
            }

            [$stockLines, $goodsReceipts] = ! empty($goodsReceiptIds)
                ? $this->buildStockLines($goodsReceiptIds, $supplier, $data['item_rates'] ?? [])
                : [[], collect()];

            $nonStockLines = ! empty($directItems) ? $this->buildDirectLines($directItems) : [];

            $source = match (true) {
                ! empty($stockLines) && ! empty($nonStockLines) => PurchaseInvoiceSource::MIXED,
                ! empty($stockLines) => PurchaseInvoiceSource::GOODS_RECEIPT,
                default => PurchaseInvoiceSource::DIRECT,
            };

            $stockSubtotal = round((float) array_sum(array_column($stockLines, 'amount')), 2);
            $nonStockSubtotal = round((float) array_sum(array_column($nonStockLines, 'amount')), 2);
            $subtotal = round($stockSubtotal + $nonStockSubtotal, 2);

            // Header tax_amount covers only the stock portion (manual — Goods Receipt items carry
            // no tax snapshot); non-stock lines each resolve and store their own tax_amount in
            // buildDirectLines(), summed here into the one combined figure this invoice stores.
            $headerTaxAmount = (float) ($data['tax_amount'] ?? 0);
            $lineTaxAmount = round((float) array_sum(array_column($nonStockLines, 'tax_amount')), 2);
            $taxAmount = round($headerTaxAmount + $lineTaxAmount, 2);
            $grandTotal = $subtotal + $taxAmount;
            $this->assertPositiveGrandTotal($grandTotal);

            $dueDate = $this->resolveDueDate($data, $supplier->id, $data['invoice_date']);
            $anchor = $goodsReceipts->first();

            $purchaseInvoice = $this->purchaseInvoiceRepository->create([
                'source' => $source,
                'goods_receipt_id' => $anchor?->id,
                'purchase_order_id' => $anchor?->purchase_order_id,
                'supplier_id' => $supplier->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'reference_number' => $data['reference_number'] ?? null,
                'attention' => $data['attention'] ?? null,
                'department' => $data['department'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ([...$stockLines, ...$nonStockLines] as $line) {
                $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
            }

            if ($goodsReceipts->isNotEmpty()) {
                $purchaseInvoice->goodsReceipts()->sync($goodsReceipts->pluck('id')->all());
                // A Direct Receipt (no source PO) has a null purchase_order_id — filter it out
                // before sync(), otherwise the pivot insert fails its FK constraint on a null id.
                $purchaseInvoice->purchaseOrders()->sync($goodsReceipts->pluck('purchase_order_id')->filter()->unique()->all());
            }

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('created', 'purchase_invoice', "Created Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    /**
     * Row-locks each Goods Receipt for the rest of this transaction, then reloads its relations
     * fresh (not from any pre-lock snapshot) — closes a race where two concurrent requests
     * invoicing the same Goods Receipt could otherwise both pass the "not yet invoiced" check
     * below before either's purchase_invoice_goods_receipts row actually commits, crashing the
     * second one on that pivot's unique constraint instead of getting a clean "already invoiced"
     * message. Every selected Goods Receipt must belong to $supplier — the Supplier chosen in
     * Step 1 of the editor, before this Goods Receipt list was ever shown.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: \Illuminate\Support\Collection}
     */
    protected function buildStockLines(array $goodsReceiptIds, Supplier $supplier, array $itemRates): array
    {
        $goodsReceipts = collect($goodsReceiptIds)
            ->map(fn (string $id) => GoodsReceipt::query()->lockForUpdate()->findOrFail($id)->load(['supplier', 'purchaseOrder', 'items', 'purchaseInvoices']))
            ->sortBy(fn ($goodsReceipt) => [$goodsReceipt->receipt_date, $goodsReceipt->id])
            ->values();

        foreach ($goodsReceipts as $goodsReceipt) {
            if ($goodsReceipt->status !== DocumentStatus::SUBMITTED) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} must be submitted before it can be invoiced.");
            }

            if ($goodsReceipt->purchaseInvoices->isNotEmpty()) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} has already been invoiced.");
            }

            if ($goodsReceipt->supplier_id !== $supplier->id) {
                throw new BusinessException("Goods Receipt {$goodsReceipt->document_number} does not belong to the selected Supplier.");
            }
        }

        // Rate overrides keyed by the source Goods Receipt item — see StorePurchaseInvoiceRequest's
        // own comment. Qty/item identity always come from the Goods Receipt line untouched.
        $rateOverrides = collect($itemRates)->pluck('rate', 'goods_receipt_item_id');

        $lines = $goodsReceipts->flatMap(fn ($goodsReceipt) => $goodsReceipt->items)->map(function ($line) use ($rateOverrides) {
            $rate = $rateOverrides->has($line->id) ? (float) $rateOverrides->get($line->id) : (float) $line->rate;

            return [
                'goods_receipt_item_id' => $line->id,
                'item_id' => $line->item_id,
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
                'uom' => $line->uom,
                'uom_factor' => $line->uom_factor,
                'rate' => $rate,
                'qty' => $line->qty,
                'amount' => round((float) $line->qty * $rate, 2),
            ];
        })->all();

        return [$lines, $goodsReceipts];
    }
```

- [ ] **Step 8: Run the tests**

Run: `cd backend/laravel-api && php artisan test --filter=PurchaseInvoiceWorkflowTest`
Expected: PASS for every `create()`-related test (the three new tests plus all pre-existing ones from Steps 1–4). `update()`-related tests still fail — that's Task 5.

- [ ] **Step 9: Commit**

```bash
git add backend/laravel-api/app/Services/PurchaseInvoiceService.php backend/laravel-api/tests/Feature/PurchaseInvoiceWorkflowTest.php
git commit -m "feat(purchase): unify PurchaseInvoiceService::create() for stock+non-stock+mixed invoices"
```

---

### Task 5: Rewrite `PurchaseInvoiceService::update()` to handle both groups independently

**Files:**
- Modify: `backend/laravel-api/app/Services/PurchaseInvoiceService.php`
- Test: `backend/laravel-api/tests/Feature/PurchaseInvoiceWorkflowTest.php`

- [ ] **Step 1: Add a test for updating a Goods-Receipt-only invoice into Mixed**

Add this method next to the Mixed create test from Task 4:

```php
    public function test_updating_a_goods_receipt_invoice_with_new_non_stock_items_turns_it_mixed(): void
    {
        $goodsReceipt = $this->submittedGoodsReceipt(qty: 5, rate: 20000); // 100000

        $purchaseInvoice = $this->purchaseInvoiceService->create([
            'supplier_id' => $this->supplier->id,
            'goods_receipt_ids' => [$goodsReceipt->id],
            'invoice_date' => now()->toDateString(),
        ]);

        $purchaseInvoice = $this->purchaseInvoiceService->update($purchaseInvoice, [
            'items' => [
                ['chart_of_account_id' => $this->accountId('6100'), 'description' => 'Extra charge', 'qty' => 1, 'rate' => 25000],
            ],
        ]);

        $this->assertEquals('mixed', $purchaseInvoice->source->value);
        $this->assertEquals(125000, (float) $purchaseInvoice->subtotal);
        $this->assertCount(2, $purchaseInvoice->items);

        // Re-updating item_rates still only touches the stock line, never the non-stock one.
        $stockItem = $purchaseInvoice->items->firstWhere('goods_receipt_item_id', '!=', null);
        $purchaseInvoice = $this->purchaseInvoiceService->update($purchaseInvoice, [
            'item_rates' => [['goods_receipt_item_id' => $stockItem->goods_receipt_item_id, 'rate' => 19000]],
        ]);

        $this->assertEquals(120000, (float) $purchaseInvoice->subtotal); // (5*19000) + 25000
        $this->assertCount(2, $purchaseInvoice->items);
    }
```

- [ ] **Step 2: Run the tests to confirm this one fails**

Run: `cd backend/laravel-api && php artisan test --filter=test_updating_a_goods_receipt_invoice_with_new_non_stock_items_turns_it_mixed`
Expected: FAIL — the old `update()` routes a Goods-Receipt-sourced invoice's `items` key nowhere (`updateFromGoodsReceipts()` never reads `data['items']`).

- [ ] **Step 3: Rewrite `update()` and add the two helper methods**

Replace the entire block from `update()` through the end of `updateDirect()` (original lines 279–379):

Old:
```php
    /**
     * A Goods Receipt-sourced invoice only has header fields editable — never goods_receipt_id,
     * never items (immutable once created from a Goods Receipt, same posture as
     * Invoice::update()). A Direct invoice additionally allows a full items replace, since it
     * has no source document to stay in sync with.
     */
    public function update(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice, $data) {
            $this->assertDraft($purchaseInvoice, 'updated');

            return $purchaseInvoice->source === PurchaseInvoiceSource::DIRECT
                ? $this->updateDirect($purchaseInvoice, $data)
                : $this->updateFromGoodsReceipts($purchaseInvoice, $data);
        });
    }

    /**
     * Header fields plus, as of the Rate-editing ticket, Rate-only overrides on existing items
     * (updated in place by goods_receipt_item_id — Qty/item identity never change, so this is
     * never a delete+recreate like updateDirect()'s items replace). subtotal is recomputed from
     * the (possibly just-edited) items rather than trusted from the stored column.
     */
    protected function updateFromGoodsReceipts(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        if (isset($data['item_rates'])) {
            $rateOverrides = collect($data['item_rates'])->pluck('rate', 'goods_receipt_item_id');

            foreach ($purchaseInvoice->items as $item) {
                if (! $rateOverrides->has($item->goods_receipt_item_id)) {
                    continue;
                }

                $rate = (float) $rateOverrides->get($item->goods_receipt_item_id);
                $this->purchaseInvoiceItemRepository->update($item, [
                    'rate' => $rate,
                    'amount' => round((float) $item->qty * $rate, 2),
                ]);
            }

            $purchaseInvoice->unsetRelation('items');
        }

        $subtotal = round((float) $purchaseInvoice->items->sum('amount'), 2);
        $taxAmount = array_key_exists('tax_amount', $data) ? (float) $data['tax_amount'] : (float) $purchaseInvoice->tax_amount;
        $grandTotal = $subtotal + $taxAmount;

        if ($grandTotal < 0) {
            throw new BusinessException('Grand total cannot be negative.');
        }

        $this->purchaseInvoiceRepository->update($purchaseInvoice, [
            'invoice_date' => $data['invoice_date'] ?? $purchaseInvoice->invoice_date,
            'due_date' => $data['due_date'] ?? $purchaseInvoice->due_date,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $grandTotal,
            'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $purchaseInvoice->reference_number,
            'remarks' => $data['remarks'] ?? $purchaseInvoice->remarks,
        ]);

        $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
        $this->auditLogService->record('updated', 'purchase_invoice', "Updated Purchase Invoice \"{$purchaseInvoice->document_number}\".");

        return $purchaseInvoice;
    }

    protected function updateDirect(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        $supplierId = $data['supplier_id'] ?? $purchaseInvoice->supplier_id;
        $invoiceDate = $data['invoice_date'] ?? $purchaseInvoice->invoice_date->toDateString();
        $dueDate = $this->resolveDueDate($data, $supplierId, $invoiceDate);

        $subtotal = (float) $purchaseInvoice->subtotal;
        $taxAmount = (float) $purchaseInvoice->tax_amount;

        if (isset($data['items'])) {
            $lines = $this->buildDirectLines($data['items']);
            $subtotal = round((float) array_sum(array_column($lines, 'amount')), 2);
            $taxAmount = round((float) array_sum(array_column($lines, 'tax_amount')), 2);
            $this->assertPositiveGrandTotal($subtotal + $taxAmount);

            $purchaseInvoice->items()->delete();

            foreach ($lines as $line) {
                $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
            }
        }

        $this->purchaseInvoiceRepository->update($purchaseInvoice, [
            'supplier_id' => $supplierId,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'grand_total' => $subtotal + $taxAmount,
            'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $purchaseInvoice->reference_number,
            'attention' => array_key_exists('attention', $data) ? $data['attention'] : $purchaseInvoice->attention,
            'department' => array_key_exists('department', $data) ? $data['department'] : $purchaseInvoice->department,
            'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $purchaseInvoice->remarks,
        ]);

        $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
        $this->auditLogService->record('updated', 'purchase_invoice', "Updated Purchase Invoice \"{$purchaseInvoice->document_number}\".");

        return $purchaseInvoice;
    }
```

New:
```php
    /**
     * Stock lines are immutable post-create (never gained/lost via update, only Rate-overridden
     * in place via item_rates); non-stock lines can be fully replaced via items. Both can be
     * touched in the same call — recomputes totals and re-derives source from whatever line mix
     * results. due_date is preserved when omitted rather than re-derived from the Supplier's
     * Terms of Payment (that auto-derivation only applies at create() time) — an unrelated field
     * edit should never silently move a previously-set due date.
     */
    public function update(PurchaseInvoice $purchaseInvoice, array $data): PurchaseInvoice
    {
        return DB::transaction(function () use ($purchaseInvoice, $data) {
            $this->assertDraft($purchaseInvoice, 'updated');

            if (isset($data['item_rates'])) {
                $this->applyRateOverrides($purchaseInvoice, $data['item_rates']);
            }

            if (isset($data['items'])) {
                $this->replaceNonStockLines($purchaseInvoice, $data['items']);
            }

            $purchaseInvoice->unsetRelation('items');
            $items = $purchaseInvoice->items;

            if ($items->isEmpty()) {
                throw new BusinessException('Invoice must have at least one line item.');
            }

            $hasStockLines = $items->contains(fn ($item) => $item->goods_receipt_item_id !== null);
            $hasNonStockLines = $items->contains(fn ($item) => $item->goods_receipt_item_id === null);
            $source = match (true) {
                $hasStockLines && $hasNonStockLines => PurchaseInvoiceSource::MIXED,
                $hasStockLines => PurchaseInvoiceSource::GOODS_RECEIPT,
                default => PurchaseInvoiceSource::DIRECT,
            };

            $stockSubtotal = round((float) $items->whereNotNull('goods_receipt_item_id')->sum('amount'), 2);
            $nonStockSubtotal = round((float) $items->whereNull('goods_receipt_item_id')->sum('amount'), 2);
            $subtotal = round($stockSubtotal + $nonStockSubtotal, 2);

            $headerTaxAmount = (float) ($data['tax_amount'] ?? 0);
            $lineTaxAmount = round((float) $items->whereNull('goods_receipt_item_id')->sum('tax_amount'), 2);
            $taxAmount = round($headerTaxAmount + $lineTaxAmount, 2);
            $grandTotal = $subtotal + $taxAmount;
            $this->assertPositiveGrandTotal($grandTotal);

            // supplier_id can only change while there are no stock lines — a stock line's
            // ownership was fixed at create() time by the Goods Receipt it came from.
            $supplierId = $hasStockLines ? $purchaseInvoice->supplier_id : ($data['supplier_id'] ?? $purchaseInvoice->supplier_id);
            $invoiceDate = $data['invoice_date'] ?? $purchaseInvoice->invoice_date->toDateString();
            $dueDate = $data['due_date'] ?? $purchaseInvoice->due_date->toDateString();

            $this->purchaseInvoiceRepository->update($purchaseInvoice, [
                'source' => $source,
                'supplier_id' => $supplierId,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'reference_number' => array_key_exists('reference_number', $data) ? $data['reference_number'] : $purchaseInvoice->reference_number,
                'attention' => array_key_exists('attention', $data) ? $data['attention'] : $purchaseInvoice->attention,
                'department' => array_key_exists('department', $data) ? $data['department'] : $purchaseInvoice->department,
                'remarks' => array_key_exists('remarks', $data) ? $data['remarks'] : $purchaseInvoice->remarks,
            ]);

            $purchaseInvoice = $purchaseInvoice->fresh(self::EAGER);
            $this->auditLogService->record('updated', 'purchase_invoice', "Updated Purchase Invoice \"{$purchaseInvoice->document_number}\".");

            return $purchaseInvoice;
        });
    }

    protected function applyRateOverrides(PurchaseInvoice $purchaseInvoice, array $itemRates): void
    {
        $rateOverrides = collect($itemRates)->pluck('rate', 'goods_receipt_item_id');

        foreach ($purchaseInvoice->items as $item) {
            if ($item->goods_receipt_item_id === null || ! $rateOverrides->has($item->goods_receipt_item_id)) {
                continue;
            }

            $rate = (float) $rateOverrides->get($item->goods_receipt_item_id);
            $this->purchaseInvoiceItemRepository->update($item, [
                'rate' => $rate,
                'amount' => round((float) $item->qty * $rate, 2),
            ]);
        }
    }

    /** Full delete+recreate of non-stock lines only — stock lines (goods_receipt_item_id set) are untouched. */
    protected function replaceNonStockLines(PurchaseInvoice $purchaseInvoice, array $items): void
    {
        $purchaseInvoice->items()->whereNull('goods_receipt_item_id')->delete();

        foreach ($this->buildDirectLines($items) as $line) {
            $this->purchaseInvoiceItemRepository->create(['purchase_invoice_id' => $purchaseInvoice->id, ...$line]);
        }
    }
```

- [ ] **Step 4: Run the full workflow test file**

Run: `cd backend/laravel-api && php artisan test --filter=PurchaseInvoiceWorkflowTest`
Expected: PASS — every test in the file, including the new Mixed-update test.

- [ ] **Step 5: Commit**

```bash
git add backend/laravel-api/app/Services/PurchaseInvoiceService.php backend/laravel-api/tests/Feature/PurchaseInvoiceWorkflowTest.php
git commit -m "feat(purchase): unify PurchaseInvoiceService::update() for stock+non-stock+mixed invoices"
```

---

### Task 6: Update `PurchaseInvoice::journalLines()` to post both algorithms side by side

The Mixed create/update tests in Tasks 4–5 already assert the combined journal posting works — this task is the model change those tests exercise against.

**Files:**
- Modify: `backend/laravel-api/app/Models/PurchaseInvoice.php`

- [ ] **Step 1: Rewrite `journalLines()`**

Old:
```php
    /**
     * Debit/credit breakdown derived from already-stored fields, for
     * AccountingService::postForDocument() to post (see
     * PurchaseInvoiceService::submit()). Mirrors Invoice::journalLines(),
     * AP-side. tax_amount is debited here to net against Sales' output-tax
     * credit on the same shared 2100 Tax Payable account.
     *
     * GOODS_RECEIPT: single Dr 5100 (subtotal) — Goods Receipt items carry no per-line expense
     * account, tax_amount is a manual header figure. DIRECT: one Dr/Cr line per distinct
     * account chosen on the invoice's own lines (items must be loaded with chartOfAccount),
     * subtotal/tax_amount are sums of the lines instead — see PurchaseInvoiceService::createDirect().
     * A DIRECT line's account is Expense (debited, adds to what's owed) or Liability (credited,
     * e.g. Hutang PPh 23 — deducts from what's owed to the supplier; its group sum is already
     * negative, per PurchaseInvoiceService::buildDirectLines()).
     */
    public function journalLines(): array
    {
        $lines = $this->source === PurchaseInvoiceSource::DIRECT
            ? $this->loadMissing('items.chartOfAccount')->items
                ->groupBy('chart_of_account_id')
                ->map(function ($group) {
                    $account = $group->first()->chartOfAccount;
                    $amount = (float) $group->sum('amount');

                    return $account->account_type === AccountType::LIABILITY
                        ? ['account' => $account->code, 'type' => 'credit', 'amount' => abs($amount)]
                        : ['account' => $account->code, 'type' => 'debit', 'amount' => $amount];
                })
                ->values()
                ->all()
            : [['account' => '5100', 'type' => 'debit', 'amount' => (float) $this->subtotal]]; // Purchase Expense

        $lines[] = ['account' => '210.01', 'type' => 'credit', 'amount' => (float) $this->grand_total]; // Accounts Payable

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '213.01', 'type' => 'debit', 'amount' => (float) $this->tax_amount]; // Tax Payable
        }

        return $lines;
    }
```

New:
```php
    /**
     * Debit/credit breakdown derived from already-stored fields, for
     * AccountingService::postForDocument() to post (see
     * PurchaseInvoiceService::submit()). Mirrors Invoice::journalLines(),
     * AP-side. tax_amount is debited here to net against Sales' output-tax
     * credit on the same shared 2100 Tax Payable account.
     *
     * Built from line presence, not from $this->source directly — this naturally covers
     * GOODS_RECEIPT (stock lines only), DIRECT (non-stock lines only), and MIXED (both) with the
     * same two algorithms run side by side instead of three branches: stock lines post a single
     * aggregate Dr 5100 (Goods Receipt items carry no per-line expense account); non-stock lines
     * post one Dr/Cr line per distinct Chart-of-Accounts account chosen on the invoice (items
     * must be loaded with chartOfAccount) — Expense is debited (adds to what's owed), Liability is
     * credited (e.g. Hutang PPh 23 deducts from what's owed; its group sum is already negative,
     * per PurchaseInvoiceService::buildDirectLines()).
     */
    public function journalLines(): array
    {
        $this->loadMissing('items.chartOfAccount');

        $stockItems = $this->items->whereNotNull('goods_receipt_item_id');
        $nonStockItems = $this->items->whereNull('goods_receipt_item_id');

        $lines = [];

        if ($stockItems->isNotEmpty()) {
            $lines[] = ['account' => '5100', 'type' => 'debit', 'amount' => (float) $stockItems->sum('amount')]; // Purchase Expense
        }

        foreach ($nonStockItems->groupBy('chart_of_account_id') as $group) {
            $account = $group->first()->chartOfAccount;
            $amount = (float) $group->sum('amount');

            $lines[] = $account->account_type === AccountType::LIABILITY
                ? ['account' => $account->code, 'type' => 'credit', 'amount' => abs($amount)]
                : ['account' => $account->code, 'type' => 'debit', 'amount' => $amount];
        }

        $lines[] = ['account' => '210.01', 'type' => 'credit', 'amount' => (float) $this->grand_total]; // Accounts Payable

        if ((float) $this->tax_amount > 0) {
            $lines[] = ['account' => '213.01', 'type' => 'debit', 'amount' => (float) $this->tax_amount]; // Tax Payable
        }

        return $lines;
    }
```

- [ ] **Step 2: Run the full workflow test file again**

Run: `cd backend/laravel-api && php artisan test --filter=PurchaseInvoiceWorkflowTest`
Expected: PASS — this rewrite is behavior-preserving for pure GOODS_RECEIPT/DIRECT invoices (same output, computed a different way) and is what makes the Mixed journal assertions from Tasks 4–5 pass.

- [ ] **Step 3: Commit**

```bash
git add backend/laravel-api/app/Models/PurchaseInvoice.php
git commit -m "feat(purchase): post both stock and non-stock journal lines for Mixed invoices"
```

---

### Task 7: Label "Mixed" in the Purchase Invoices export

**Files:**
- Modify: `backend/laravel-api/app/Exports/PurchaseInvoiceExport.php`

- [ ] **Step 1: Edit the `map()` method**

Old:
```php
            $row->source === PurchaseInvoiceSource::DIRECT ? 'Direct' : 'Goods Receipt',
```

New:
```php
            match ($row->source) {
                PurchaseInvoiceSource::DIRECT => 'Direct',
                PurchaseInvoiceSource::MIXED => 'Mixed',
                default => 'Goods Receipt',
            },
```

- [ ] **Step 2: Commit**

```bash
git add backend/laravel-api/app/Exports/PurchaseInvoiceExport.php
git commit -m "feat(purchase): label Mixed invoices in the Purchase Invoices export"
```

---

### Task 8: Full backend regression run

**Files:** none (verification only)

- [ ] **Step 1: Run the entire backend test suite**

Run: `cd backend/laravel-api && php artisan test`
Expected: PASS — no regressions anywhere else in the suite (Purchase Return, Accounts Payable, Payment Entry, etc. all depend on `PurchaseInvoice`/`PurchaseInvoiceItem` but none of their own tests pass `source` into `create()`/`update()` directly, so they're unaffected by the contract change).

- [ ] **Step 2: If anything outside `PurchaseInvoiceWorkflowTest` fails**, read the failure, fix the regression (do not skip or weaken the failing assertion), and re-run until green. No commit step here — any fix belongs folded into whichever Task above introduced it, or as its own small commit if the failure is in a file not already touched.

---

## Frontend

### Task 9: Extend `PurchaseInvoiceSource` and `PurchaseInvoiceItem` types

**Files:**
- Modify: `frontend/src/features/purchase/types.ts`

- [ ] **Step 1: Add `'mixed'` to the source union**

Old:
```ts
export type PurchaseInvoiceSource = 'goods_receipt' | 'direct'
```

New:
```ts
export type PurchaseInvoiceSource = 'goods_receipt' | 'direct' | 'mixed'
```

- [ ] **Step 2: Add `line_type` to `PurchaseInvoiceItem`**

Old:
```ts
  uom: string | null
  /** From the line's Item — decides whether qty_returned must be a whole number or may carry decimals. */
  item_qty_category?: 'unit' | 'weight'
  /** Direct/Non-Stock line only — the expense account it posts to. */
  chart_of_account_id?: string | null
```

New:
```ts
  uom: string | null
  /** From the line's Item — decides whether qty_returned must be a whole number or may carry decimals. */
  item_qty_category?: 'unit' | 'weight'
  /** Derived server-side from goods_receipt_item_id — see PurchaseInvoiceItem::getLineTypeAttribute(). */
  line_type: 'stock' | 'non_stock'
  /** Direct/Non-Stock line only — the expense account it posts to. */
  chart_of_account_id?: string | null
```

- [ ] **Step 3: Build-check and commit**

Run: `cd frontend && npm run build`
Expected: FAIL at this point — `PurchaseInvoiceEditorPage.tsx` and its siblings still reference the old two-schema shape; that's resolved by Tasks 10–14. This build check is just confirming the type edit itself has no stray syntax error — eyeball the error output to be sure it's only unrelated downstream files complaining, not this file.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/features/purchase/types.ts
git commit -m "feat(purchase): add mixed source and line_type to PurchaseInvoice types"
```

---

### Task 10: Rewrite the unified zod schema and the line-amount helper

**Files:**
- Modify: `frontend/src/features/purchase/lib/purchaseInvoiceFormSchema.ts` (full rewrite)
- Modify: `frontend/src/features/purchase/lib/directLineAmount.ts`

- [ ] **Step 1: Rewrite `purchaseInvoiceFormSchema.ts`**

```ts
import { z } from 'zod'

/**
 * Goods Receipt-sourced Purchase Invoice line ("stock") — Item/Qty are locked copies of the
 * source Goods Receipt line (Qty always reflects what was actually received into stock), but
 * Rate is free to edit: the supplier's physical invoice amount can differ from what was recorded
 * at receipt time. No price-variance accounting, the edited Rate just becomes this Invoice's own
 * Rate. goods_receipt_item_id ties the row back to its source Goods Receipt item for the Rate
 * override payload.
 */
export const purchaseInvoiceStockLineRowSchema = z.object({
  kind: z.literal('stock'),
  goods_receipt_item_id: z.string(),
  item_code: z.string().nullable(),
  item_name: z.string(),
  uom: z.string().nullable(),
  qty: z.union([z.string(), z.number()]),
  qty_category: z.enum(['unit', 'weight']).optional(),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
})

/**
 * Non-stock ("manual") Purchase Invoice line — no Item, no Goods Receipt. Posts straight to a
 * Chart-of-Accounts Expense or Liability account instead of an Item, types its own free-text
 * Description/UOM, and its own optional Tax.
 */
export const purchaseInvoiceNonStockLineRowSchema = z.object({
  kind: z.literal('non_stock'),
  chart_of_account_id: z.string().min(1, 'Account is required'),
  description: z.string().min(1, 'Description is required'),
  uom: z.string().optional().or(z.literal('')),
  qty: z
    .string()
    .min(1, 'Qty is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) > 0, 'Must be greater than zero'),
  rate: z
    .string()
    .min(1, 'Rate is required')
    .refine((value) => !Number.isNaN(Number(value)) && Number(value) >= 0, 'Must be zero or greater'),
  tax_id: z.string().optional().or(z.literal('')),
})

export const purchaseInvoiceLineRowSchema = z.discriminatedUnion('kind', [
  purchaseInvoiceStockLineRowSchema,
  purchaseInvoiceNonStockLineRowSchema,
])

/**
 * Unified Purchase Invoice form — one or more stock rows (from a selected Goods Receipt), one or
 * more non-stock rows (manually added), or both ("Mixed"). tax_amount is a manual header figure
 * covering only the stock portion — Goods Receipt items carry no tax snapshot to derive it from;
 * each non-stock row carries its own tax instead, summed server-side.
 */
export const purchaseInvoiceFormSchema = z.object({
  supplier_id: z.string().min(1, 'Supplier is required'),
  invoice_date: z.string().min(1, 'Invoice date is required'),
  due_date: z.string().optional().or(z.literal('')),
  tax_amount: z.string().refine((value) => value === '' || (!Number.isNaN(Number(value)) && Number(value) >= 0), 'Must be zero or greater'),
  reference_number: z.string().optional().or(z.literal('')),
  attention: z.string().optional().or(z.literal('')),
  department: z.string().optional().or(z.literal('')),
  remarks: z.string().optional().or(z.literal('')),
  items: z.array(purchaseInvoiceLineRowSchema).min(1, 'Add at least one line item — select a Goods Receipt or add a non-stock line.'),
})

export type PurchaseInvoiceEditorValues = z.infer<typeof purchaseInvoiceFormSchema>
export type PurchaseInvoiceLineRow = z.infer<typeof purchaseInvoiceLineRowSchema>
export type PurchaseInvoiceStockLineRow = z.infer<typeof purchaseInvoiceStockLineRowSchema>
export type PurchaseInvoiceNonStockLineRow = z.infer<typeof purchaseInvoiceNonStockLineRowSchema>

export const emptyPurchaseInvoiceEditorValues: PurchaseInvoiceEditorValues = {
  supplier_id: '',
  invoice_date: '',
  due_date: '',
  tax_amount: '',
  reference_number: '',
  attention: '',
  department: '',
  remarks: '',
  items: [],
}
```

(This replaces the file's entire previous content — both old schemas, `GoodsReceiptSourcedLineRow`, `DirectPurchaseInvoiceEditorValues`, `DirectPurchaseInvoiceLineRow`, `directPurchaseInvoiceFormSchema`, and `emptyDirectPurchaseInvoiceEditorValues` are all gone, folded into the one unified set above. Confirmed via grep that no file outside this one, `PurchaseInvoiceEditorPage.tsx`, and the two line-table components — both deleted in Task 11 — references any of those old names.)

- [ ] **Step 2: Add the unified line-amount helper to `directLineAmount.ts`**

Old (full file):
```ts
import { lineAmount } from '@/shared/lib/documentTotals'
import type { ChartOfAccount } from '@/features/master/types'

interface DirectLineLike {
  chart_of_account_id: string
  qty: string | number
  rate: string | number
}

export function isLiabilityLine(item: DirectLineLike, accounts: ChartOfAccount[]): boolean {
  return accounts.find((account) => account.id === item.chart_of_account_id)?.account_type === 'liability'
}

/**
 * A Liability line (e.g. Hutang PPh 23) deducts from what's owed to the supplier — user always
 * types a positive rate, this is the one place the sign flip happens for both the row display
 * and the header totals preview, mirroring PurchaseInvoiceService::buildDirectLines() server-side.
 */
export function directLineAmount(item: DirectLineLike, accounts: ChartOfAccount[]): number {
  const amount = lineAmount(item)

  return isLiabilityLine(item, accounts) ? -amount : amount
}
```

New (full file):
```ts
import { lineAmount } from '@/shared/lib/documentTotals'
import type { ChartOfAccount } from '@/features/master/types'
import type { PurchaseInvoiceLineRow } from './purchaseInvoiceFormSchema'

interface DirectLineLike {
  chart_of_account_id: string
  qty: string | number
  rate: string | number
}

export function isLiabilityLine(item: DirectLineLike, accounts: ChartOfAccount[]): boolean {
  return accounts.find((account) => account.id === item.chart_of_account_id)?.account_type === 'liability'
}

/**
 * A Liability line (e.g. Hutang PPh 23) deducts from what's owed to the supplier — user always
 * types a positive rate, this is the one place the sign flip happens for both the row display
 * and the header totals preview, mirroring PurchaseInvoiceService::buildDirectLines() server-side.
 */
export function directLineAmount(item: DirectLineLike, accounts: ChartOfAccount[]): number {
  const amount = lineAmount(item)

  return isLiabilityLine(item, accounts) ? -amount : amount
}

/** Stock (Goods Receipt-sourced) rows are always qty*rate — the Liability sign flip only ever applies to a manually chosen Chart-of-Accounts line. */
export function purchaseInvoiceLineAmount(row: PurchaseInvoiceLineRow, accounts: ChartOfAccount[]): number {
  if (row.kind === 'stock') return Number(row.qty) * Number(row.rate || 0)
  return directLineAmount(row, accounts)
}
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/features/purchase/lib/purchaseInvoiceFormSchema.ts frontend/src/features/purchase/lib/directLineAmount.ts
git commit -m "feat(purchase): unify Purchase Invoice line schema into one discriminated union"
```

(The build will still fail after this commit — `PurchaseInvoiceEditorPage.tsx` and the two old table components haven't been updated yet. That's expected; Tasks 11–12 fix it. We commit here anyway to keep each task's diff reviewable on its own, consistent with this plan's frequent-commits convention — just don't stop here if asked to "ship what's done so far".)

---

### Task 11: Build the unified line-items table, delete the two old ones

**Files:**
- Create: `frontend/src/features/purchase/components/PurchaseInvoiceLineItemTable.tsx`
- Delete: `frontend/src/features/purchase/components/GoodsReceiptSourcedLineItemTable.tsx`
- Delete: `frontend/src/features/purchase/components/DirectPurchaseInvoiceLineItemTable.tsx`

- [ ] **Step 1: Create the unified table component**

```tsx
import { useFieldArray, useWatch, type UseFormReturn } from 'react-hook-form'
import { Plus, Trash2 } from 'lucide-react'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField, FormItem, FormMessage } from '@/components/ui/form'
import { EmptyState } from '@/components/shared/EmptyState'
import { LineItemTableScroll, STICKY_FIRST_COL } from '@/components/shared/LineItemTableScroll'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { SearchableSelect } from '@/components/shared/SearchableSelect'
import { formatCurrency } from '@/lib/utils'
import { formatQty } from '@/shared/lib/qty'
import { isLiabilityLine, purchaseInvoiceLineAmount } from '../lib/directLineAmount'
import { isHeaderAccount } from '@/features/master/lib/chartOfAccountOptions'
import type { PurchaseInvoiceEditorValues } from '../lib/purchaseInvoiceFormSchema'
import type { ChartOfAccount, Tax } from '@/features/master/types'

const NO_TAX = '__none__'

interface PurchaseInvoiceLineItemTableProps {
  form: UseFormReturn<PurchaseInvoiceEditorValues>
  accounts: ChartOfAccount[]
  accountsLoading?: boolean
  taxes: Tax[]
}

/**
 * Unified Purchase Invoice line items table — renders Goods Receipt-sourced ("From GR") rows
 * locked (Item/Qty read-only, Rate editable) alongside manually added ("Manual") non-stock rows
 * (Account/Description/Qty/UOM/Rate/Tax). "+ Add Non-Stock Line" is always available regardless
 * of whether any stock rows are present — stock rows themselves have no Add/Remove, the set is
 * fixed by which Goods Receipt(s) were selected before reaching this screen.
 */
export function PurchaseInvoiceLineItemTable({ form, accounts, accountsLoading, taxes }: PurchaseInvoiceLineItemTableProps) {
  const { control } = form
  const { fields, append, remove } = useFieldArray({ control, name: 'items' })
  const watchedItems = useWatch({ control, name: 'items' }) ?? []

  const accountOptions = accounts.map((account) => ({
    value: account.id,
    label: account.account_type === 'liability' ? `${account.code} — ${account.name} (Liability)` : `${account.code} — ${account.name}`,
    disabled: isHeaderAccount(account),
  }))

  return (
    <div className="flex flex-col gap-3">
      <LineItemTableScroll>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10" />
              <TableHead className={STICKY_FIRST_COL}>Item / Account</TableHead>
              <TableHead className="w-56">Description</TableHead>
              <TableHead className="w-24">Qty</TableHead>
              <TableHead className="w-24">UOM</TableHead>
              <TableHead className="w-36">Rate</TableHead>
              <TableHead className="w-44">Tax</TableHead>
              <TableHead className="w-36 text-right">Amount</TableHead>
              <TableHead className="w-10" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {fields.length === 0 ? (
              <TableRow>
                <TableCell colSpan={9} className="p-0">
                  <EmptyState message="No line items yet." description="Select a Goods Receipt or use Add Non-Stock Line to start building this invoice." />
                </TableCell>
              </TableRow>
            ) : (
              fields.map((field, index) => {
                const line = watchedItems[index]

                if (!line || line.kind === 'stock') {
                  return (
                    <TableRow key={field.id}>
                      <TableCell>
                        <Badge variant="secondary">From GR</Badge>
                      </TableCell>
                      <TableCell className={STICKY_FIRST_COL}>{line?.item_code ?? '—'}</TableCell>
                      <TableCell>{line?.item_name ?? ''}</TableCell>
                      <TableCell>{formatQty(line?.qty ?? 0, line?.qty_category ?? 'unit')}</TableCell>
                      <TableCell>{line?.uom ?? '—'}</TableCell>
                      <TableCell className="min-w-36">
                        <FormField
                          control={control}
                          name={`items.${index}.rate`}
                          render={({ field: rateField }) => (
                            <FormItem className="gap-0">
                              <RupiahInput value={rateField.value} onChange={rateField.onChange} decimals={2} aria-label="Rate" />
                              <FormMessage />
                            </FormItem>
                          )}
                        />
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">—</TableCell>
                      <TableCell className="text-right font-medium">{line ? formatCurrency(purchaseInvoiceLineAmount(line, accounts)) : '—'}</TableCell>
                      <TableCell />
                    </TableRow>
                  )
                }

                const isLiability = isLiabilityLine(line, accounts)

                return (
                  <TableRow key={field.id}>
                    <TableCell>
                      <Badge variant="outline">Manual</Badge>
                    </TableCell>
                    <TableCell className={STICKY_FIRST_COL}>
                      <FormField
                        control={control}
                        name={`items.${index}.chart_of_account_id`}
                        render={({ field: accountField }) => (
                          <FormItem className="gap-0">
                            <SearchableSelect
                              options={accountOptions}
                              value={accountField.value}
                              onChange={(value) => {
                                accountField.onChange(value ?? '')
                                if (accounts.find((account) => account.id === value)?.account_type === 'liability') {
                                  form.setValue(`items.${index}.tax_id`, '')
                                }
                              }}
                              loading={accountsLoading}
                              placeholder="Select account"
                              aria-label="Expense Account"
                            />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-56">
                      <FormField
                        control={control}
                        name={`items.${index}.description`}
                        render={({ field: descriptionField }) => (
                          <FormItem className="gap-0">
                            <Input placeholder="Description" {...descriptionField} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-24">
                      <FormField
                        control={control}
                        name={`items.${index}.qty`}
                        render={({ field: qtyField }) => (
                          <FormItem className="gap-0">
                            <Input type="number" min={0.01} step="0.01" {...qtyField} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-24">
                      <FormField
                        control={control}
                        name={`items.${index}.uom`}
                        render={({ field: uomField }) => (
                          <FormItem className="gap-0">
                            <Input placeholder="Optional" {...uomField} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="min-w-36">
                      <FormField
                        control={control}
                        name={`items.${index}.rate`}
                        render={({ field: rateField }) => (
                          <FormItem className="gap-0">
                            <RupiahInput value={rateField.value} onChange={rateField.onChange} decimals={2} />
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell>
                      <FormField
                        control={control}
                        name={`items.${index}.tax_id`}
                        render={({ field: taxField }) => (
                          <FormItem className="gap-0">
                            <Select
                              value={isLiability ? NO_TAX : taxField.value || NO_TAX}
                              onValueChange={(value) => taxField.onChange(value === NO_TAX ? '' : value)}
                              disabled={isLiability}
                            >
                              <SelectTrigger className="w-full">
                                <SelectValue placeholder="No tax" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value={NO_TAX}>No tax</SelectItem>
                                {taxes.map((t) => (
                                  <SelectItem key={t.id} value={t.id}>
                                    {t.name}
                                  </SelectItem>
                                ))}
                              </SelectContent>
                            </Select>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </TableCell>
                    <TableCell className="text-right font-medium">{formatCurrency(purchaseInvoiceLineAmount(line, accounts))}</TableCell>
                    <TableCell>
                      <Button type="button" variant="ghost" size="icon" className="size-8 text-destructive hover:text-destructive" onClick={() => remove(index)}>
                        <Trash2 className="size-4" />
                        <span className="sr-only">Remove row</span>
                      </Button>
                    </TableCell>
                  </TableRow>
                )
              })
            )}
          </TableBody>
        </Table>
      </LineItemTableScroll>

      <Button
        type="button"
        variant="outline"
        size="sm"
        className="self-start"
        onClick={() => append({ kind: 'non_stock', chart_of_account_id: '', description: '', uom: '', qty: '1', rate: '0', tax_id: '' })}
      >
        <Plus className="size-4" />
        Add Non-Stock Line
      </Button>
    </div>
  )
}
```

- [ ] **Step 2: Delete the two old table components**

```bash
git rm frontend/src/features/purchase/components/GoodsReceiptSourcedLineItemTable.tsx frontend/src/features/purchase/components/DirectPurchaseInvoiceLineItemTable.tsx
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/features/purchase/components/PurchaseInvoiceLineItemTable.tsx
git commit -m "feat(purchase): unify Goods-Receipt and Direct line item tables into one component"
```

(The build is still red — `PurchaseInvoiceEditorPage.tsx` references both deleted components and the old schema names until Task 12. Expected at this point in the plan.)

---

### Task 12: Rewrite `PurchaseInvoiceEditorPage.tsx` — Supplier → optional Goods Receipt → unified form

**Files:**
- Modify: `frontend/src/features/purchase/pages/PurchaseInvoiceEditorPage.tsx` (full rewrite)

- [ ] **Step 1: Replace the entire file**

```tsx
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import type { NavigateFunction } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Loader2, Save, Send } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { RupiahInput } from '@/components/shared/RupiahInput'
import { Textarea } from '@/components/ui/textarea'
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Checkbox } from '@/components/ui/checkbox'
import { PageHeader } from '@/components/shared/PageHeader'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { SearchableSelect, type SearchableSelectOption } from '@/components/shared/SearchableSelect'
import { toastApiError } from '@/shared/services/errorHandler'
import { formatCurrency } from '@/lib/utils'
import { computeLineTaxTotal } from '@/shared/lib/documentTotals'
import { purchaseInvoiceLineAmount } from '../lib/directLineAmount'
import { useChartOfAccountsLookup } from '@/features/master/hooks/useLookups'
import { fetchTaxesLookup, searchSuppliersLookup } from '@/features/master/api/lookupsApi'
import { fetchGoodsReceipts } from '../api/goodsReceiptApi'
import { createPurchaseInvoice, fetchPurchaseInvoice, submitPurchaseInvoice, updatePurchaseInvoice } from '../api/purchaseInvoiceApi'
import { PurchaseInvoiceLineItemTable } from '../components/PurchaseInvoiceLineItemTable'
import {
  emptyPurchaseInvoiceEditorValues,
  purchaseInvoiceFormSchema,
  type PurchaseInvoiceEditorValues,
  type PurchaseInvoiceLineRow,
} from '../lib/purchaseInvoiceFormSchema'
import type { GoodsReceipt, PurchaseInvoice, PurchaseInvoiceFormValues, PurchaseInvoiceItem } from '../types'

export function PurchaseInvoiceEditorPage() {
  const { id } = useParams<{ id: string }>()
  const isEdit = !!id
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [supplierOption, setSupplierOption] = useState<SearchableSelectOption | undefined>()
  const [supplierConfirmed, setSupplierConfirmed] = useState(false)
  const [selectedGoodsReceiptIds, setSelectedGoodsReceiptIds] = useState<Set<string>>(new Set())
  const [selectionConfirmed, setSelectionConfirmed] = useState(false)

  const toggleGoodsReceipt = (goodsReceiptId: string, checked: boolean) => {
    setSelectedGoodsReceiptIds((prev) => {
      const next = new Set(prev)
      if (checked) next.add(goodsReceiptId)
      else next.delete(goodsReceiptId)
      return next
    })
  }

  const loadSupplierOptions = async (query: string) => {
    const suppliers = await searchSuppliersLookup(query)
    return suppliers.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name }))
  }

  const invoiceQuery = useQuery({
    queryKey: ['purchase-invoices', id],
    queryFn: () => fetchPurchaseInvoice(id!),
    enabled: isEdit,
  })

  // Eligible = submitted (stock already moved) and not yet invoiced. Fetched only in create mode,
  // once a Supplier has been chosen and before the selection is confirmed (Skip or Continue).
  const eligibleGoodsReceiptsQuery = useQuery({
    queryKey: ['goods-receipts-eligible-for-invoice'],
    queryFn: () => fetchGoodsReceipts({ page: 1, per_page: 100, status: 'submitted' }),
    enabled: !isEdit && supplierConfirmed && !selectionConfirmed,
  })
  const eligibleGoodsReceipts = (eligibleGoodsReceiptsQuery.data?.data ?? []).filter((goodsReceipt) => !goodsReceipt.is_invoiced)
  const selectableGoodsReceipts = supplierOption
    ? eligibleGoodsReceipts.filter((goodsReceipt) => goodsReceipt.supplier_id === supplierOption.value)
    : eligibleGoodsReceipts
  const selectedGoodsReceipts = selectableGoodsReceipts.filter((goodsReceipt) => selectedGoodsReceiptIds.has(goodsReceipt.id))

  useEffect(() => {
    const invoice = invoiceQuery.data
    if (!invoice) return

    if (invoice.status !== 'draft') {
      toast.error('Only draft invoices can be edited.')
      navigate(`/purchase/invoices/${invoice.id}`, { replace: true })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [invoiceQuery.data])

  if (isEdit && invoiceQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  // Step 1 (create mode only): choose the Supplier.
  if (!isEdit && !supplierConfirmed) {
    return (
      <div className="flex flex-col gap-4">
        <PageHeader
          title="New Invoice"
          description="Pick the Supplier being invoiced, then optionally select one or more received Goods Receipts and/or add non-stock lines."
        />
        <Card>
          <CardHeader>
            <CardTitle>Select Supplier</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <SearchableSelect
              loadOptions={loadSupplierOptions}
              selectedOption={supplierOption}
              value={supplierOption?.value}
              onChange={(_value, option) => setSupplierOption(option)}
              clearable={false}
              placeholder="Select supplier"
              aria-label="Supplier"
            />
            <div className="flex gap-2">
              <Button type="button" disabled={!supplierOption} onClick={() => setSupplierConfirmed(true)}>
                Continue
              </Button>
              <Button type="button" variant="outline" onClick={() => navigate('/purchase/invoices')}>
                Cancel
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  // Step 2 (create mode only): select Goods Receipt(s) — optional, Skip is always available.
  if (!isEdit && !selectionConfirmed) {
    const allSelectableChecked = selectableGoodsReceipts.length > 0 && selectableGoodsReceipts.every((gr) => selectedGoodsReceiptIds.has(gr.id))

    return (
      <div className="flex flex-col gap-4">
        <PageHeader title="New Invoice" description={`Invoicing ${supplierOption?.label ?? ''}.`} />
        <Card>
          <CardHeader>
            <CardTitle>Select Goods Receipt (optional)</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              {eligibleGoodsReceiptsQuery.isLoading ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="size-6 animate-spin text-muted-foreground" />
                </div>
              ) : selectableGoodsReceipts.length === 0 ? (
                <p className="text-sm text-muted-foreground">No goods receipts available to invoice for this supplier.</p>
              ) : (
                <div className="overflow-x-auto rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-10">
                          <Checkbox
                            checked={allSelectableChecked}
                            onCheckedChange={(checked) => selectableGoodsReceipts.forEach((gr) => toggleGoodsReceipt(gr.id, checked === true))}
                            aria-label="Select all eligible goods receipts"
                          />
                        </TableHead>
                        <TableHead>Document Number</TableHead>
                        <TableHead>Receipt Date</TableHead>
                        <TableHead className="text-right">Items</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {selectableGoodsReceipts.map((goodsReceipt) => {
                        const checked = selectedGoodsReceiptIds.has(goodsReceipt.id)
                        return (
                          <TableRow key={goodsReceipt.id} data-state={checked ? 'selected' : undefined}>
                            <TableCell>
                              <Checkbox
                                checked={checked}
                                onCheckedChange={(value) => toggleGoodsReceipt(goodsReceipt.id, value === true)}
                                aria-label={`Select ${goodsReceipt.document_number}`}
                              />
                            </TableCell>
                            <TableCell className="font-medium">{goodsReceipt.document_number}</TableCell>
                            <TableCell>{goodsReceipt.receipt_date}</TableCell>
                            <TableCell className="text-right">{goodsReceipt.items.length}</TableCell>
                          </TableRow>
                        )
                      })}
                    </TableBody>
                  </Table>
                </div>
              )}
              <p className="text-sm text-muted-foreground">
                Only submitted Goods Receipts from this Supplier that have not already been invoiced are shown. You can also skip this step and/or
                add non-stock lines on the next screen.
              </p>
            </div>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => setSupplierConfirmed(false)}>
                Back
              </Button>
              <Button type="button" variant="outline" onClick={() => setSelectionConfirmed(true)}>
                Skip / Continue without Goods Receipt
              </Button>
              <Button type="button" disabled={selectedGoodsReceiptIds.size === 0} onClick={() => setSelectionConfirmed(true)}>
                Continue
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    )
  }

  if (isEdit && !invoiceQuery.data) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <PurchaseInvoiceForm
      key={isEdit ? id : `${supplierOption?.value ?? ''}:${Array.from(selectedGoodsReceiptIds).sort().join(',')}`}
      isEdit={isEdit}
      id={id}
      invoice={invoiceQuery.data}
      supplierOption={supplierOption}
      selectedGoodsReceipts={selectedGoodsReceipts}
      navigate={navigate}
      queryClient={queryClient}
    />
  )
}

function PurchaseInvoiceForm({
  isEdit,
  id,
  invoice,
  supplierOption,
  selectedGoodsReceipts,
  navigate,
  queryClient,
}: {
  isEdit: boolean
  id: string | undefined
  invoice: PurchaseInvoice | undefined
  supplierOption: SearchableSelectOption | undefined
  selectedGoodsReceipts: GoodsReceipt[]
  navigate: NavigateFunction
  queryClient: QueryClient
}) {
  const accounts = useChartOfAccountsLookup()
  const directLineAccounts = (accounts.data ?? []).filter(
    (account) => (account.account_type === 'expense' || account.account_type === 'liability') && account.is_active,
  )
  const taxesQuery = useQuery({ queryKey: ['taxes-lookup'], queryFn: fetchTaxesLookup })
  const activePurchaseTaxOptions = (taxesQuery.data ?? []).filter((t) => t.is_active && t.transaction_type === 'purchase')

  // Stock lines are immutable post-create — Supplier can only change while there are none yet
  // (mirrors PurchaseInvoiceService::update()'s own rule).
  const hasStockLines = isEdit ? (invoice?.items ?? []).some((line) => line.goods_receipt_item_id !== null) : selectedGoodsReceipts.length > 0
  const supplierEditable = isEdit && !hasStockLines

  const [selectedSupplierOption, setSelectedSupplierOption] = useState<SearchableSelectOption | undefined>(
    isEdit && invoice?.supplier ? { value: invoice.supplier.id, label: invoice.supplier.supplier_name } : supplierOption,
  )
  const loadSupplierOptions = async (query: string) => {
    const suppliers = await searchSuppliersLookup(query)
    return suppliers.map((supplier) => ({ value: supplier.id, label: supplier.supplier_name }))
  }

  const stockRows: PurchaseInvoiceLineRow[] = isEdit
    ? ((invoice?.items ?? []) as PurchaseInvoiceItem[])
        .filter((line) => line.goods_receipt_item_id !== null)
        .map((line) => ({
          kind: 'stock' as const,
          goods_receipt_item_id: line.goods_receipt_item_id!,
          item_code: line.item_code,
          item_name: line.item_name,
          uom: line.uom,
          qty: line.qty,
          qty_category: line.item_qty_category,
          rate: String(line.rate),
        }))
    : selectedGoodsReceipts.flatMap((gr) =>
        gr.items.map((line) => ({
          kind: 'stock' as const,
          goods_receipt_item_id: line.id,
          item_code: line.item_code,
          item_name: line.item_name,
          uom: line.uom,
          qty: line.qty,
          qty_category: line.qty_category,
          rate: String(line.rate),
        })),
      )

  const nonStockRows: PurchaseInvoiceLineRow[] = isEdit
    ? ((invoice?.items ?? []) as PurchaseInvoiceItem[])
        .filter((line) => line.goods_receipt_item_id === null)
        .map((line) => ({
          kind: 'non_stock' as const,
          chart_of_account_id: line.chart_of_account_id ?? '',
          description: line.item_name,
          uom: line.uom ?? '',
          qty: String(line.qty),
          rate: String(line.rate),
          tax_id: line.tax_id ?? '',
        }))
    : []

  const form = useForm<PurchaseInvoiceEditorValues>({
    resolver: zodResolver(purchaseInvoiceFormSchema),
    defaultValues: invoice
      ? {
          supplier_id: invoice.supplier_id,
          invoice_date: invoice.invoice_date,
          due_date: invoice.due_date,
          tax_amount: String(invoice.tax_amount),
          reference_number: invoice.reference_number ?? '',
          attention: invoice.attention ?? '',
          department: invoice.department ?? '',
          remarks: invoice.remarks ?? '',
          items: [...stockRows, ...nonStockRows],
        }
      : { ...emptyPurchaseInvoiceEditorValues, supplier_id: supplierOption?.value ?? '', items: stockRows },
  })

  const toPayload = (values: PurchaseInvoiceEditorValues): PurchaseInvoiceFormValues => {
    const stockLines = values.items.filter((line) => line.kind === 'stock')
    const nonStockLines = values.items.filter((line) => line.kind === 'non_stock')

    return {
      supplier_id: values.supplier_id,
      // Immutable once created — only sent on create.
      ...(isEdit ? {} : { goods_receipt_ids: selectedGoodsReceipts.map((gr) => gr.id) }),
      invoice_date: values.invoice_date,
      due_date: values.due_date || null,
      tax_amount: values.tax_amount === '' ? 0 : Number(values.tax_amount),
      reference_number: values.reference_number || null,
      attention: values.attention || null,
      department: values.department || null,
      remarks: values.remarks || null,
      ...(stockLines.length > 0
        ? { item_rates: stockLines.map((line) => ({ goods_receipt_item_id: line.goods_receipt_item_id, rate: Number(line.rate) })) }
        : {}),
      ...(nonStockLines.length > 0
        ? {
            items: nonStockLines.map((line) => ({
              chart_of_account_id: line.chart_of_account_id,
              description: line.description,
              uom: line.uom || null,
              qty: Number(line.qty),
              rate: Number(line.rate),
              tax_id: line.tax_id || null,
            })),
          }
        : {}),
    }
  }

  const saveMutation = useMutation({
    mutationFn: (values: PurchaseInvoiceEditorValues) => {
      const payload = toPayload(values)
      return isEdit ? updatePurchaseInvoice(id!, payload) : createPurchaseInvoice(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      toast.success(isEdit ? 'Purchase Invoice updated.' : 'Purchase Invoice saved as draft.')
      navigate('/purchase/invoices')
    },
    onError: (error) => toastApiError(error),
  })

  const submitMutation = useMutation({
    mutationFn: () => submitPurchaseInvoice(id!),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })
      queryClient.invalidateQueries({ queryKey: ['accounts-payables'] })
      toast.success('Purchase Invoice submitted — Accounts Payable created.')
      navigate('/purchase/invoices')
    },
    onError: (error) => toastApiError(error),
  })

  const watchedTaxAmount = Number(form.watch('tax_amount') || 0)
  const watchedItems = form.watch('items')
  const subtotal = (watchedItems ?? []).reduce((sum, line) => sum + purchaseInvoiceLineAmount(line, directLineAccounts), 0)
  const lineTax = computeLineTaxTotal(watchedItems ?? [], (line) =>
    line.kind === 'non_stock' ? activePurchaseTaxOptions.find((t) => t.id === line.tax_id) : undefined,
  )
  const tax = watchedTaxAmount + lineTax
  const grandTotal = subtotal + tax

  const onSubmit = form.handleSubmit((values) => saveMutation.mutate(values))

  const goodsReceiptLabel = isEdit
    ? invoice?.goods_receipts?.map((gr) => gr.document_number).join(', ')
    : selectedGoodsReceipts.map((gr) => gr.document_number).join(', ')

  return (
    <div className="flex flex-col gap-4">
      <PageHeader
        title={isEdit ? `Edit ${invoice?.document_number ?? 'Invoice'}` : 'New Invoice'}
        description="Invoice one or more Goods Receipts, add non-stock lines, or both."
      />

      <Form {...form}>
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Invoice Details</CardTitle>
              <StatusBadge status={isEdit ? (invoice?.display_status ?? 'draft') : 'draft'} />
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div className="flex flex-col gap-0.5">
                <span className="text-xs text-muted-foreground">Invoice Number</span>
                <span className="text-sm font-medium">{isEdit ? (invoice?.document_number ?? '—') : 'Assigned when saved'}</span>
              </div>
              {goodsReceiptLabel && (
                <div className="flex flex-col gap-0.5">
                  <span className="text-xs text-muted-foreground">Goods Receipt</span>
                  <span className="text-sm font-medium">{goodsReceiptLabel}</span>
                </div>
              )}
              <FormField
                control={form.control}
                name="supplier_id"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Supplier</FormLabel>
                    <SearchableSelect
                      loadOptions={loadSupplierOptions}
                      selectedOption={selectedSupplierOption}
                      value={field.value}
                      onChange={(value, option) => {
                        field.onChange(value ?? '')
                        setSelectedSupplierOption(option)
                      }}
                      clearable={false}
                      disabled={!supplierEditable}
                      placeholder="Select supplier"
                      aria-label="Supplier"
                    />
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="invoice_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Invoice Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="due_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Due Date</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} />
                    </FormControl>
                    <p className="text-xs text-muted-foreground">Leave blank to use the Supplier's Terms of Payment.</p>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="reference_number"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Reference Number</FormLabel>
                    <FormControl>
                      <Input placeholder="Supplier's own invoice number" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="tax_amount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tax Amount (Goods Receipt lines)</FormLabel>
                    <FormControl>
                      <RupiahInput value={field.value} onChange={field.onChange} decimals={2} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="attention"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Attention</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g. vehicle plate number" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="department"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Department</FormLabel>
                    <FormControl>
                      <Input placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="remarks"
                render={({ field }) => (
                  <FormItem className="sm:col-span-2">
                    <FormLabel>Notes</FormLabel>
                    <FormControl>
                      <Textarea placeholder="Optional" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Line Items</CardTitle>
            </CardHeader>
            <CardContent>
              <PurchaseInvoiceLineItemTable
                form={form}
                accounts={directLineAccounts}
                accountsLoading={accounts.isLoading}
                taxes={activePurchaseTaxOptions}
              />
              {form.formState.errors.items?.root && <p className="mt-2 text-sm text-destructive">{form.formState.errors.items.root.message}</p>}
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col items-end gap-1.5 py-4">
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Subtotal</span>
                <span>{formatCurrency(subtotal)}</span>
              </div>
              <div className="flex w-full max-w-64 justify-between text-sm">
                <span className="text-muted-foreground">Tax</span>
                <span>{formatCurrency(tax)}</span>
              </div>
              <Separator className="w-full max-w-64" />
              <div className="flex w-full max-w-64 justify-between text-base font-semibold">
                <span>Grand Total</span>
                <span>{formatCurrency(grandTotal)}</span>
              </div>
            </CardContent>
          </Card>

          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => navigate('/purchase/invoices')}>
              Cancel
            </Button>
            <Button type="submit" variant="outline" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
              Save Draft
            </Button>
            {isEdit && invoice?.status === 'draft' && (
              <Button type="button" onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}>
                {submitMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                Submit
              </Button>
            )}
          </div>
        </form>
      </Form>
    </div>
  )
}
```

- [ ] **Step 2: Build-check**

Run: `cd frontend && npm run build`
Expected: the Purchase Invoice editor files now compile. Any remaining errors should only be in `PurchaseInvoiceListPage.tsx`/`PurchaseInvoiceDetailPage.tsx` (Tasks 13–14) — if an error points inside this file or `PurchaseInvoiceLineItemTable.tsx`, fix it before moving on.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/features/purchase/pages/PurchaseInvoiceEditorPage.tsx
git commit -m "feat(purchase): merge New Invoice flow into Supplier -> optional Goods Receipt -> unified line items"
```

---

### Task 13: Add "Mixed" to the list page badge and filter

**Files:**
- Modify: `frontend/src/features/purchase/pages/PurchaseInvoiceListPage.tsx`
- Modify: `frontend/src/features/purchase/components/PurchaseInvoiceFiltersBar.tsx`

- [ ] **Step 1: Update the Type column**

Old:
```tsx
    { header: 'Type', accessor: (row) => <Badge variant={row.source === 'direct' ? 'outline' : 'secondary'}>{row.source === 'direct' ? 'Direct' : 'Goods Receipt'}</Badge> },
```

New:
```tsx
    {
      header: 'Type',
      accessor: (row) => (
        <Badge variant={row.source === 'goods_receipt' ? 'secondary' : 'outline'}>
          {row.source === 'direct' ? 'Direct' : row.source === 'mixed' ? 'Mixed' : 'Goods Receipt'}
        </Badge>
      ),
    },
```

- [ ] **Step 2: Add the Mixed option to the Type filter**

Old:
```tsx
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="goods_receipt">Goods Receipt</SelectItem>
            <SelectItem value="direct">Direct</SelectItem>
          </SelectContent>
```

New:
```tsx
          <SelectContent>
            <SelectItem value={ALL}>All types</SelectItem>
            <SelectItem value="goods_receipt">Goods Receipt</SelectItem>
            <SelectItem value="direct">Direct</SelectItem>
            <SelectItem value="mixed">Mixed</SelectItem>
          </SelectContent>
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/features/purchase/pages/PurchaseInvoiceListPage.tsx frontend/src/features/purchase/components/PurchaseInvoiceFiltersBar.tsx
git commit -m "feat(purchase): add Mixed badge and filter option to Purchase Invoices listing"
```

---

### Task 14: Update the detail page — Mixed badge, unified Item List, stock-line-based return eligibility

**Files:**
- Modify: `frontend/src/features/purchase/pages/PurchaseInvoiceDetailPage.tsx`

- [ ] **Step 1: Replace the two column-set constants with one unified set**

Old:
```tsx
const goodsReceiptLineColumns: DataTableColumn<PurchaseInvoiceItem>[] = [
  { header: 'Item Code', accessor: (row) => row.item_code },
  { header: 'Item Name', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.item_qty_category ?? 'unit'), className: 'text-right' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]

const directLineColumns: DataTableColumn<PurchaseInvoiceItem>[] = [
  { header: 'Account', accessor: (row) => (row.chart_of_account ? `${row.chart_of_account.code} — ${row.chart_of_account.name}` : '—') },
  { header: 'Description', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, 'unit') },
  { header: 'UOM', accessor: (row) => row.uom || '—' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Tax', accessor: (row) => (Number(row.tax_amount ?? 0) > 0 ? formatCurrency(row.tax_amount ?? 0) : '—'), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]
```

New:
```tsx
const unifiedLineColumns: DataTableColumn<PurchaseInvoiceItem>[] = [
  {
    header: 'Type',
    accessor: (row) => <Badge variant={row.line_type === 'stock' ? 'secondary' : 'outline'}>{row.line_type === 'stock' ? 'From GR' : 'Manual'}</Badge>,
  },
  {
    header: 'Item / Account',
    accessor: (row) => (row.line_type === 'stock' ? row.item_code ?? '—' : row.chart_of_account ? `${row.chart_of_account.code} — ${row.chart_of_account.name}` : '—'),
  },
  { header: 'Description', accessor: (row) => row.item_name },
  { header: 'Qty', accessor: (row) => formatQty(row.qty, row.item_qty_category ?? 'unit'), className: 'text-right' },
  { header: 'UOM', accessor: (row) => row.uom || '—' },
  { header: 'Rate', accessor: (row) => formatCurrency(row.rate), className: 'text-right' },
  { header: 'Tax', accessor: (row) => (Number(row.tax_amount ?? 0) > 0 ? formatCurrency(row.tax_amount ?? 0) : '—'), className: 'text-right' },
  { header: 'Amount', accessor: (row) => formatCurrency(row.amount), className: 'text-right' },
]
```

- [ ] **Step 2: Replace `isDirect` with stock/non-stock presence checks**

Old:
```tsx
  const invoice = invoiceQuery.data
  if (!invoice) return null

  const isDirect = invoice.source === 'direct'
```

New:
```tsx
  const invoice = invoiceQuery.data
  if (!invoice) return null

  const hasStockLines = invoice.items.some((item) => item.line_type === 'stock')
  const isDirect = invoice.source === 'direct'
```

(`isDirect` is kept only for the Attention/Department-vs-Location header toggle, which still makes sense per-type; `hasStockLines` drives Return eligibility and the Goods Receipt/Purchase Order cards, since those are about whether stock lines exist, not about the invoice's overall label.)

- [ ] **Step 3: Update the Create Return button condition**

Old:
```tsx
            {!isDirect && invoice.status === 'submitted' && Number(invoice.returnable_amount) > 0 && (
```

New:
```tsx
            {hasStockLines && invoice.status === 'submitted' && Number(invoice.returnable_amount) > 0 && (
```

- [ ] **Step 4: Update the badge to support Mixed**

Old:
```tsx
            <Badge variant={isDirect ? 'outline' : 'secondary'}>{isDirect ? 'Direct' : 'Goods Receipt'}</Badge>
```

New:
```tsx
            <Badge variant={invoice.source === 'goods_receipt' ? 'secondary' : 'outline'}>
              {invoice.source === 'direct' ? 'Direct' : invoice.source === 'mixed' ? 'Mixed' : 'Goods Receipt'}
            </Badge>
```

- [ ] **Step 5: Update the Goods Receipt/Purchase Order Information cards' visibility**

Old:
```tsx
      {!isDirect && (
        <Card>
          <CardHeader>
            <CardTitle>Goods Receipt Information</CardTitle>
          </CardHeader>
```

New:
```tsx
      {hasStockLines && (
        <Card>
          <CardHeader>
            <CardTitle>Goods Receipt Information</CardTitle>
          </CardHeader>
```

Old:
```tsx
      {!isDirect && (
        <Card>
          <CardHeader>
            <CardTitle>Purchase Order Information</CardTitle>
          </CardHeader>
```

New:
```tsx
      {hasStockLines && (
        <Card>
          <CardHeader>
            <CardTitle>Purchase Order Information</CardTitle>
          </CardHeader>
```

- [ ] **Step 6: Switch the Item List table to the unified columns**

Old:
```tsx
          <DataTable columns={isDirect ? directLineColumns : goodsReceiptLineColumns} data={invoice.items} rowKey={(row) => row.id} emptyMessage="No line items." />
```

New:
```tsx
          <DataTable columns={unifiedLineColumns} data={invoice.items} rowKey={(row) => row.id} emptyMessage="No line items." />
```

- [ ] **Step 7: Build-check**

Run: `cd frontend && npm run build`
Expected: PASS — the full Purchase Invoice feature now compiles clean.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/features/purchase/pages/PurchaseInvoiceDetailPage.tsx
git commit -m "feat(purchase): support Mixed invoices on the detail page (badge, unified item list, return eligibility)"
```

---

### Task 15: Manual acceptance walkthrough

This app has no frontend test harness (confirmed: no `test` script in `frontend/package.json`, no `*.test.*` files under `features/purchase`) and backend tests run fully offline against sqlite in-memory (no local MySQL needed — see Task 8). This task is the manual pass against the spec's acceptance criteria, to run once the user has deployed/built the frontend (per this project's standing rule: never call a frontend feature done from a local build — verify in the browser against a real build, and only claim production-verified after the user deploys to `ke.edithclinicofficial.com`).

**Files:** none (verification only)

- [ ] **Step 1: Build the frontend and start it locally for a smoke pass**

Run: `cd frontend && npm run build`
Expected: PASS, zero TypeScript errors.

- [ ] **Step 2: Walk through every acceptance criterion from the spec**

Using the running app (`npm run dev` or the built output, supplier/goods-receipt/account test data already in the dev DB):

1. Purchase > Invoices > New Invoice goes straight to Select Supplier, no From-Goods-Receipt-vs-Direct picker.
2. After picking a Supplier with outstanding Goods Receipts, the Select Goods Receipt screen shows a visible Skip button alongside Continue.
3. Create an invoice with only Goods-Receipt lines — saves and submits normally (no regression).
4. Skip Goods Receipt entirely, add only a non-stock line — saves and submits normally (replaces the old "Direct" flow).
5. Pick a Goods Receipt AND add a non-stock line on the same invoice — saves, and the detail page shows a "Mixed" badge.
6. On the unified Line Items table, stock rows show "From GR" and are rate-only-editable; non-stock rows show "Manual" and are fully editable with a Remove button.
7. The non-stock Account dropdown only lists Expense/Liability accounts (same as before — unchanged).
8. Stock rows never show an Account field.
9. Try to save/submit with zero total lines — a clear error appears (either the zod "Add at least one line item" message client-side, or the `BusinessException` message surfaced via the API if a request somehow bypasses the client form).
10. Listing page's Type filter now has a Mixed option, and Mixed invoices show the Mixed badge there too.
11. Submit a Mixed invoice and confirm (via the Journal Entry list/detail, or by reading the `journal_entries`/`journal_entry_lines` tables) that it posts both the 5100 stock aggregate line and the per-account non-stock lines in one entry.
12. Open a handful of the pre-existing (pre-migration) Direct and Goods-Receipt invoices from before this change — confirm they still display correctly on listing/detail, and that a Draft one among them can still be edited and submitted.

- [ ] **Step 3: Report back any criterion that fails** rather than silently patching around it — since this plan's Tasks 1–14 are the full intended implementation, a failure here likely means a task's code needs a fix, not a workaround bolted onto this task.

No commit for this task — it's verification, not code.
