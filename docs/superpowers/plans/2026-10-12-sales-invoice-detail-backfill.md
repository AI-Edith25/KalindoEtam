# Sales Invoice Detail Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build an import feature that fills in real item-level detail (items, qty, price, header disc/tax/reference) on Invoices the Customer Outstanding Bills import already created, by matching document numbers against the old system's "Sales Invoice Listing - Detail" export — never creating a new Invoice.

**Architecture:** Restore the removed `SalesInvoiceHistoryParser` (pure file parsing, unchanged) and build a new `SalesInvoiceDetailBackfillService` with the usual `preflight()`/`commit()` shape used by every other importer in this app. A new thin controller + request + two routes expose it; a new frontend dialog (mirroring `CustomerOutstandingArchiveImportDialog`) triggers it from a button on the Sales Invoice list page.

**Tech Stack:** Laravel 11 (PHP), Maatwebsite Excel (via the existing `ImportFileReader`), React + TanStack Query + shadcn/ui on the frontend.

**Spec:** `docs/superpowers/specs/2026-10-12-sales-invoice-detail-backfill-design.md` — read it first for the *why* behind every rule below; this plan only covers the *how*.

---

## File Structure

| File | Responsibility |
|---|---|
| `backend/laravel-api/app/Services/Import/SalesInvoiceHistoryParser.php` | **Create** (restore verbatim from git history) — parses the "Sales Invoice Listing - Detail" file into invoice groups. Pure, no DB access. |
| `backend/laravel-api/app/Services/Import/SalesInvoiceDetailBackfillService.php` | **Create** — matches parsed groups to existing Invoices, classifies each (will update / 5 skip reasons), and writes the update on `commit()`. |
| `backend/laravel-api/app/Http/Requests/StoreSalesInvoiceDetailBackfillRequest.php` | **Create** — file upload validation (same rules as the sibling importer). |
| `backend/laravel-api/app/Http/Controllers/Api/V1/SalesInvoiceDetailBackfillController.php` | **Create** — `store()` (preflight) / `resolve()` (commit), orchestration only. |
| `backend/laravel-api/routes/api.php` | **Modify** — add the controller import + 2 routes, reusing the existing `sales.invoices.import` permission (already in `RolePermissionSeeder` — no seeder change needed). |
| `backend/laravel-api/tests/Feature/SalesInvoiceDetailBackfillServiceTest.php` | **Create** — service-level tests (this app's established convention for import services; no sibling feature has a dedicated HTTP-level test either). |
| `frontend/src/features/sales/types.ts` | **Modify** — add the preview/batch/result types. |
| `frontend/src/features/sales/api/invoiceDetailBackfillApi.ts` | **Create** — `storeSalesInvoiceDetailBackfill()` / `resolveSalesInvoiceDetailBackfill()`. |
| `frontend/src/features/sales/components/SalesInvoiceDetailBackfillImportDialog.tsx` | **Create** — upload → preview → confirm dialog, mirrors `CustomerOutstandingArchiveImportDialog.tsx`. |
| `frontend/src/features/sales/pages/InvoiceListPage.tsx` | **Modify** — add the "Import Detail" button + mount the dialog. |

---

## Task 1: Restore the file parser

**Files:**
- Create: `backend/laravel-api/app/Services/Import/SalesInvoiceHistoryParser.php`

- [ ] **Step 1: Restore the file from git history**

```bash
cd backend/laravel-api
git show 19570d3^:backend/laravel-api/app/Services/Import/SalesInvoiceHistoryParser.php > app/Services/Import/SalesInvoiceHistoryParser.php
```

- [ ] **Step 2: Verify it's byte-identical to the historical version and has no syntax errors**

```bash
git show 19570d3^:backend/laravel-api/app/Services/Import/SalesInvoiceHistoryParser.php | diff - app/Services/Import/SalesInvoiceHistoryParser.php
php -l app/Services/Import/SalesInvoiceHistoryParser.php
```

Expected: no diff output, `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add app/Services/Import/SalesInvoiceHistoryParser.php
git commit -m "feat(sales): restore SalesInvoiceHistoryParser for the detail backfill import

Pure file-parsing utility (no DB writes) — same \"Sales Invoice Listing -
Detail\" file shape, reused by the new SalesInvoiceDetailBackfillService.
Not reviving the old import feature itself (that created new Invoices);
this restore is parsing code only."
```

---

## Task 2: Service — happy path (will_update)

**Files:**
- Create: `backend/laravel-api/app/Services/Import/SalesInvoiceDetailBackfillService.php`
- Create: `backend/laravel-api/tests/Feature/SalesInvoiceDetailBackfillServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\MiscellaneousItem;
use App\Models\UnitOfMeasurement;
use App\Services\Import\SalesInvoiceDetailBackfillService;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exercises SalesInvoiceDetailBackfillService — see this file's own docblock and
 * docs/superpowers/specs/2026-10-12-sales-invoice-detail-backfill-design.md for the matching/
 * validation rules. Invoices are built directly via Eloquent to simulate the state
 * CustomerOutstandingArchiveImportService already leaves behind (one generic InvoiceItem line,
 * import_source_type = 'outstanding_bills_archive').
 */
class SalesInvoiceDetailBackfillServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalesInvoiceDetailBackfillService $service;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(DocumentEngineSeeder::class);
        $this->service = app(SalesInvoiceDetailBackfillService::class);
        $this->customer = Customer::query()->create(['customer_code' => 'C-1364', 'customer_name' => 'TK. DENDYA']);
    }

    /** @return Invoice the invoice with its one generic InvoiceItem line, as the Outstanding Bills import leaves it */
    private function makeOutstandingBillsInvoice(string $documentNumber, string $type, float $grandTotal): Invoice
    {
        $invoice = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => $type,
            'invoice_date' => '2026-09-12',
            'due_date' => '2026-10-12',
            'subtotal' => $grandTotal, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => $grandTotal,
            'source_document_number' => $documentNumber,
            'import_source_type' => 'outstanding_bills_archive',
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'item_name' => $documentNumber,
            'uom' => null, 'rate' => $grandTotal, 'qty' => 1, 'qty_category' => 'weight', 'amount' => $grandTotal,
        ]);

        return $invoice;
    }

    /** @return string path to a CSV fixture shaped like "Sales Invoice Listing - Detail" */
    private function writeFixture(string $content): string
    {
        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->path($path);
    }

    private function csv(array $rows): string
    {
        return implode("\r\n", array_map(
            fn (array $row) => implode(',', array_map(fn ($v) => $v ?? '', $row)),
            $rows
        ))."\r\n";
    }

    private const HEADER_ROW = ['DATE', 'DOCUMENT #', 'CUSTOMER#', 'NAME', '', 'DELIVERY TO', '', 'DISC', 'TAX', 'T.CODE', 'AMOUNT', 'REFERENCE 1 #', 'REFERENCE 2 #'];
    private const ITEM_SUB_HEADER = ['ITEM #', '', 'DESCRIPTION', '', 'UOM', 'QUANTITY', 'UNIT PRICE', 'DISC', 'TAX', 'T.CODE', 'LINE AMOUNT'];

    public function test_preflight_matches_and_will_update_a_goods_invoice_with_a_real_item(): void
    {
        $item = Item::query()->create([
            'item_code' => 'SC-50', 'item_name' => 'Semen Conch 50kg',
            'uom_id' => UnitOfMeasurement::query()->create(['name' => 'ZAK'])->id,
            'qty_category' => 'weight', 'standard_rate' => 0,
        ]);

        $invoice = $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 1050000.28);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [],
            [],
            ['PT. KALINDO ETAM'],
            [],
            [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 104054.08, '', 1050000.28, '', ''],
            ['SC-50', '', 'Semen Conch 50kg', '', 'ZAK', 30, 31531.54, 0, 104054.08, '', 1050000.28],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $path = $this->writeFixture($csv);
        $preflight = $this->service->preflight($path, 'csv');

        $this->assertSame(1, $preflight['will_update']);
        $this->assertSame([], $preflight['skipped_not_found']);
        $this->assertSame([], $preflight['skipped_customer_mismatch']);
        $this->assertSame([], $preflight['skipped_type_mismatch']);
        $this->assertSame([], $preflight['skipped_item_unresolved']);
        $this->assertSame([], $preflight['skipped_amount_mismatch']);

        $result = $this->service->commit($path, 'csv');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(['SI/KE/08111/09/2026'], $result['document_numbers']);

        $invoice->refresh();
        $this->assertEquals(945946.2, (float) $invoice->subtotal);
        $this->assertEquals(104054.08, (float) $invoice->tax_amount);
        $this->assertEquals(0, (float) $invoice->discount_amount);
        $this->assertEquals(1050000.28, (float) $invoice->grand_total, 'grand_total must stay untouched, already validated');

        $line = $invoice->items()->sole();
        $this->assertSame($item->id, $line->item_id);
        $this->assertSame('Semen Conch 50kg', $line->item_name);
        $this->assertSame('ZAK', $line->uom);
        $this->assertEquals(30, (float) $line->qty);
        $this->assertEquals(31531.54, (float) $line->rate);
        $this->assertEquals(945946.2, (float) $line->amount);
        $this->assertEquals(104054.08, (float) $line->tax_amount);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd backend/laravel-api
php artisan test --filter=SalesInvoiceDetailBackfillServiceTest
```

Expected: FAIL — `Target class [App\Services\Import\SalesInvoiceDetailBackfillService] does not exist.`

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services\Import;

use App\Enums\DiscountType;
use App\Enums\QtyCategory;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\MiscellaneousItem;
use App\Support\DocumentKeyNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Fills in real item-level detail on an Invoice CustomerOutstandingArchiveImportService already
 * created (import_source_type = 'outstanding_bills_archive') — those only ever get one freeform
 * line, since the Customer Outstanding Bills file has no item detail at all. This reads the old
 * system's full-detail export ("Sales Invoice Listing - Detail", the exact file shape
 * SalesInvoiceHistoryParser already parsed before the old import feature was removed 2026-10-08)
 * and, for every invoice it can confidently match back to one already in this system, replaces
 * the placeholder line with the file's real item rows and updates the header's
 * subtotal/discount_amount/tax_amount/reference_1/reference_2.
 *
 * Never creates an Invoice and never touches grand_total/customer_id/dates/status/warehouse/
 * stock/GL — those are already correct from the Outstanding Bills import. A group this can't
 * confidently match (wrong customer, wrong type, an item code with no master match, or a
 * recomputed total that doesn't reconcile) is skipped entirely and reported — never partially
 * applied. See docs/superpowers/specs/2026-10-12-sales-invoice-detail-backfill-design.md.
 */
class SalesInvoiceDetailBackfillService
{
    /** Not the usual 0.01 elsewhere in this app — this sums many lines from decades-old legacy
        data, so small per-line rounding drift compounds (same tolerance the old, now-removed
        SalesInvoiceImportService::crossCheckAmount() used for exactly this reason). */
    private const AMOUNT_TOLERANCE = 1.0;

    public function __construct(protected SalesInvoiceHistoryParser $parser) {}

    /**
     * @return array{
     *   total_rows: int, will_update: int,
     *   skipped_not_found: array<int, array{document_number: string, customer_code: string}>,
     *   skipped_customer_mismatch: array<int, array{document_number: string, file_customer_code: string, invoice_customer_code: string}>,
     *   skipped_type_mismatch: array<int, array{document_number: string, file_type: string, invoice_type: string}>,
     *   skipped_item_unresolved: array<int, array{document_number: string}>,
     *   skipped_amount_mismatch: array<int, array{document_number: string, file_amount: float, computed_amount: float}>,
     *   warnings: array<int, string>,
     * }
     */
    public function preflight(string $absolutePath, string $extension): array
    {
        $parsed = $this->parseFile($absolutePath, $extension);
        $classified = $this->classify($parsed['invoices']);

        return [...$this->summarize($classified), 'total_rows' => count($parsed['invoices']), 'warnings' => $parsed['warnings']];
    }

    /** @return array{updated: int, document_numbers: array<int, string>} */
    public function commit(string $absolutePath, string $extension): array
    {
        $parsed = $this->parseFile($absolutePath, $extension);
        $classified = $this->classify($parsed['invoices']);

        $documentNumbers = [];
        foreach ($classified as $entry) {
            if ($entry['outcome'] !== 'will_update') {
                continue;
            }

            DB::transaction(function () use ($entry) {
                $this->applyDetail($entry['invoice'], $entry['group'], $entry['resolvedItems'], $entry['totals']);
            });
            $documentNumbers[] = $entry['group']['document_number'];
        }

        return ['updated' => count($documentNumbers), 'document_numbers' => $documentNumbers];
    }

    /** @return array{invoices: array, warnings: array<int, string>} */
    private function parseFile(string $absolutePath, string $extension): array
    {
        $rawRows = ImportFileReader::readRaw($absolutePath, $extension);

        if (! is_string($rawRows[0][0] ?? null) || ! str_contains(mb_strtoupper($rawRows[0][0]), SalesInvoiceHistoryParser::TITLE)) {
            throw new BusinessException('File ini bukan format "Sales Invoice Listing - Detail" — periksa kembali file yang diupload.');
        }

        return $this->parser->parse($rawRows);
    }

    /**
     * One file group -> one classified entry. Read-only (no writes) so preflight() and commit()
     * share identical classification — commit() never trusts a cached preview, it reclassifies
     * fresh from the file every time, same posture as every other importer in this app.
     *
     * @param  array<int, array>  $groups  SalesInvoiceHistoryParser::parse()'s own 'invoices' shape
     * @return array<int, array{group: array, outcome: string, invoice?: Invoice, resolvedItems?: array, totals?: array}>
     */
    private function classify(array $groups): array
    {
        $normalizedNumbers = collect($groups)
            ->map(fn ($g) => DocumentKeyNormalizer::normalize($g['document_number']))
            ->filter()
            ->all();

        $invoicesByNormalized = Invoice::query()
            ->where('import_source_type', 'outstanding_bills_archive')
            ->whereIn('source_document_number_normalized', $normalizedNumbers)
            ->with('customer:id,customer_code')
            ->get()
            ->keyBy('source_document_number_normalized');

        $itemCodes = collect($groups)
            ->filter(fn ($g) => $g['type'] === 'goods')
            ->flatMap(fn ($g) => collect($g['items'])->pluck('item_code'))
            ->unique()
            ->all();

        $itemsByCode = Item::query()->whereIn('item_code', $itemCodes)->with('uom')->get()
            ->keyBy(fn (Item $i) => mb_strtoupper(trim($i->item_code)));
        $miscByCode = MiscellaneousItem::query()->whereIn('misc_code', $itemCodes)->get()
            ->keyBy(fn (MiscellaneousItem $m) => mb_strtoupper(trim($m->misc_code)));

        return array_map(
            fn (array $group) => $this->classifyOne($group, $invoicesByNormalized, $itemsByCode, $miscByCode),
            $groups
        );
    }

    /** @return array{group: array, outcome: string, invoice?: Invoice, resolvedItems?: array, totals?: array} */
    private function classifyOne(array $group, $invoicesByNormalized, $itemsByCode, $miscByCode): array
    {
        $normalized = DocumentKeyNormalizer::normalize($group['document_number']);
        $invoice = $normalized !== null ? $invoicesByNormalized->get($normalized) : null;

        if ($invoice === null) {
            return ['group' => $group, 'outcome' => 'skipped_not_found'];
        }

        if (mb_strtoupper(trim($invoice->customer->customer_code)) !== mb_strtoupper(trim($group['customer_code']))) {
            return ['group' => $group, 'outcome' => 'skipped_customer_mismatch', 'invoice' => $invoice];
        }

        if ($invoice->invoice_type->value !== $group['type']) {
            return ['group' => $group, 'outcome' => 'skipped_type_mismatch', 'invoice' => $invoice];
        }

        $resolvedItems = [];
        if ($group['type'] === 'goods') {
            foreach ($group['items'] as $itemRow) {
                $code = mb_strtoupper(trim($itemRow['item_code']));
                $resolved = $itemsByCode->get($code) ?? $miscByCode->get($code);

                if ($resolved === null) {
                    return ['group' => $group, 'outcome' => 'skipped_item_unresolved', 'invoice' => $invoice];
                }

                $resolvedItems[] = $resolved;
            }
        } else {
            $resolvedItems = array_fill(0, count($group['items']), null);
        }

        $totals = $this->computeTotals($group);

        if (abs($totals['grand_total'] - (float) $invoice->grand_total) > self::AMOUNT_TOLERANCE) {
            return ['group' => $group, 'outcome' => 'skipped_amount_mismatch', 'invoice' => $invoice, 'totals' => $totals];
        }

        return ['group' => $group, 'outcome' => 'will_update', 'invoice' => $invoice, 'resolvedItems' => $resolvedItems, 'totals' => $totals];
    }

    /**
     * Same formula the old, now-removed SalesInvoiceImportService::totals() used: subtotal is the
     * sum of qty*rate across item rows (pre-tax), tax_amount is the sum of each item's own TAX
     * (not the file's header TAX column — summing the lines keeps it consistent with subtotal),
     * discount_amount is the file's header DISC column directly.
     *
     * @return array{subtotal: float, tax_amount: float, discount_amount: float, grand_total: float}
     */
    private function computeTotals(array $group): array
    {
        $subtotal = 0.0;
        $taxTotal = 0.0;

        foreach ($group['items'] as $itemRow) {
            $subtotal += (float) $itemRow['qty'] * (float) $itemRow['rate'];
            $taxTotal += (float) $itemRow['tax'];
        }

        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $discountAmount = round((float) $group['header_disc'], 2);

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxTotal,
            'discount_amount' => $discountAmount,
            'grand_total' => round($subtotal - $discountAmount + $taxTotal, 2),
        ];
    }

    /** @param  array<int, Item|MiscellaneousItem|null>  $resolvedItems */
    private function applyDetail(Invoice $invoice, array $group, array $resolvedItems, array $totals): void
    {
        $invoice->items()->delete();

        foreach ($group['items'] as $i => $itemRow) {
            $resolved = $resolvedItems[$i];
            $item = $resolved instanceof Item ? $resolved : null;
            $misc = $resolved instanceof MiscellaneousItem ? $resolved : null;
            $qtyCategory = $item?->qty_category ?? QtyCategory::WEIGHT;

            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'delivery_item_id' => null,
                'item_id' => $item?->id,
                'item_code' => $item?->item_code,
                'item_name' => $item?->item_name ?? $misc?->description ?? $itemRow['description'],
                'uom' => $item?->uom?->name ?? $itemRow['uom'],
                'rate' => $itemRow['rate'],
                'qty' => round((float) $itemRow['qty'], $qtyCategory->decimalPlaces()),
                'qty_category' => $qtyCategory->value,
                'amount' => round((float) $itemRow['qty'] * (float) $itemRow['rate'], 2),
                'tax_id' => null,
                'tax_amount' => round((float) $itemRow['tax'], 2),
            ]);
        }

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_amount'],
            'discount_type' => DiscountType::AMOUNT->value,
            'tax_amount' => $totals['tax_amount'],
            'reference_1' => $group['reference_1'],
            'reference_2' => $group['reference_2'],
        ]);
    }

    /**
     * Full row detail for every skip outcome — doubles as the source for the rejected-rows CSV,
     * same pattern as CustomerOutstandingArchiveImportService::summarizeClassification().
     *
     * @param  array<int, array{group: array, outcome: string}>  $classified
     */
    private function summarize(array $classified): array
    {
        $pick = fn (string $outcome, callable $map) => array_values(array_map(
            $map,
            array_filter($classified, fn ($c) => $c['outcome'] === $outcome)
        ));

        return [
            'will_update' => count(array_filter($classified, fn ($c) => $c['outcome'] === 'will_update')),
            'skipped_not_found' => $pick('skipped_not_found', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'customer_code' => $c['group']['customer_code'],
            ]),
            'skipped_customer_mismatch' => $pick('skipped_customer_mismatch', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'file_customer_code' => $c['group']['customer_code'],
                'invoice_customer_code' => $c['invoice']->customer->customer_code,
            ]),
            'skipped_type_mismatch' => $pick('skipped_type_mismatch', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'file_type' => $c['group']['type'],
                'invoice_type' => $c['invoice']->invoice_type->value,
            ]),
            'skipped_item_unresolved' => $pick('skipped_item_unresolved', fn ($c) => [
                'document_number' => $c['group']['document_number'],
            ]),
            'skipped_amount_mismatch' => $pick('skipped_amount_mismatch', fn ($c) => [
                'document_number' => $c['group']['document_number'],
                'file_amount' => $c['group']['header_amount'],
                'computed_amount' => $c['totals']['grand_total'],
            ]),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=SalesInvoiceDetailBackfillServiceTest
```

Expected: PASS (1 test, all assertions green).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Import/SalesInvoiceDetailBackfillService.php tests/Feature/SalesInvoiceDetailBackfillServiceTest.php
git commit -m "feat(sales): add SalesInvoiceDetailBackfillService happy path

Matches a parsed Sales Invoice Listing - Detail group to an existing
Outstanding-Bills-imported Invoice and replaces its one generic line
with real item detail, updating subtotal/disc/tax/reference. grand_total
is validated against the recomputed total (1.00 tolerance) but never
overwritten."
```

---

## Task 3: Service — skip_not_found and skip_customer_mismatch

**Files:**
- Modify: `backend/laravel-api/tests/Feature/SalesInvoiceDetailBackfillServiceTest.php`

(No production code changes in this task — `classifyOne()` already handles both cases from Task 2's implementation. This task only adds the regression tests that prove it.)

- [ ] **Step 1: Add the failing tests**

```php
    public function test_preflight_skips_a_document_number_not_found_among_outstanding_bills_archive_invoices(): void
    {
        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/99999/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame(['document_number' => 'SI/KE/99999/09/2026', 'customer_code' => 'C-1364'], $preflight['skipped_not_found'][0]);
    }

    public function test_preflight_skips_when_the_files_customer_does_not_match_the_invoices_customer(): void
    {
        $otherCustomer = Customer::query()->create(['customer_code' => 'C-9999', 'customer_name' => 'Someone Else']);
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 500000);
        // Reassign to a different customer than the file will claim.
        Invoice::query()->where('source_document_number', 'SI/KE/08111/09/2026')->update(['customer_id' => $otherCustomer->id]);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('SI/KE/08111/09/2026', $preflight['skipped_customer_mismatch'][0]['document_number']);
        $this->assertSame('C-1364', $preflight['skipped_customer_mismatch'][0]['file_customer_code']);
        $this->assertSame('C-9999', $preflight['skipped_customer_mismatch'][0]['invoice_customer_code']);
    }
```

- [ ] **Step 2: Run to verify both pass immediately (no implementation gap expected)**

```bash
php artisan test --filter=SalesInvoiceDetailBackfillServiceTest
```

Expected: PASS, 3 tests. If either fails, the classification logic from Task 2 has a bug — fix `classifyOne()` before continuing, do not change the test's expectations.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/SalesInvoiceDetailBackfillServiceTest.php
git commit -m "test(sales): cover skip_not_found and skip_customer_mismatch for detail backfill"
```

---

## Task 4: Service — skip_type_mismatch, skip_item_unresolved, skip_amount_mismatch

**Files:**
- Modify: `backend/laravel-api/tests/Feature/SalesInvoiceDetailBackfillServiceTest.php`

(Same note as Task 3 — these exercise classification branches already written in Task 2.)

- [ ] **Step 1: Add the failing tests**

```php
    public function test_preflight_skips_when_the_files_document_type_does_not_match_the_invoices_type(): void
    {
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'transportation', 500000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('goods', $preflight['skipped_type_mismatch'][0]['file_type']);
        $this->assertSame('transportation', $preflight['skipped_type_mismatch'][0]['invoice_type']);
    }

    public function test_preflight_skips_a_goods_group_with_an_unresolvable_item_code(): void
    {
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 500000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 500000, '', ''],
            ['NOPE-999', '', 'Barang Entah', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('SI/KE/08111/09/2026', $preflight['skipped_item_unresolved'][0]['document_number']);
    }

    public function test_preflight_skips_when_the_recomputed_total_is_beyond_tolerance(): void
    {
        $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 999999);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 999999, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 500000, 0, 0, '', 500000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $preflight = $this->service->preflight($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $preflight['will_update']);
        $this->assertSame('SI/KE/08111/09/2026', $preflight['skipped_amount_mismatch'][0]['document_number']);
        $this->assertEquals(500000.0, $preflight['skipped_amount_mismatch'][0]['computed_amount']);
    }
```

Note: `MISC` must not resolve to any `Item`/`MiscellaneousItem` in these fixtures for the amount-mismatch and type-mismatch tests — none is created in `setUp()`, so it would normally hit `skipped_item_unresolved` first. Use a **Transportation** group there instead so item resolution never runs — fix the two tests above by setting the group type to transportation:

```php
    // In test_preflight_skips_when_the_files_document_type_does_not_match_the_invoices_type()
    // and test_preflight_skips_when_the_recomputed_total_is_beyond_tolerance(): change the
    // DOCUMENT # to a TR/ prefix so the parser classifies the group as 'transportation' and no
    // Item lookup is attempted — e.g. 'TR/KE/08111/09/2026' instead of 'SI/KE/08111/09/2026' in
    // both the header row's DOCUMENT # cell and makeOutstandingBillsInvoice()'s argument.
```

Apply that change directly to the two test methods' fixtures and assertions (replace every `SI/KE/08111/09/2026` with `TR/KE/08111/09/2026` and `'goods'` with `'transportation'` in those two tests only — leave `test_preflight_skips_a_goods_group_with_an_unresolvable_item_code` as `SI/` since that one is specifically testing the Goods item-resolution path).

- [ ] **Step 2: Run to verify all pass**

```bash
php artisan test --filter=SalesInvoiceDetailBackfillServiceTest
```

Expected: PASS, 6 tests.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/SalesInvoiceDetailBackfillServiceTest.php
git commit -m "test(sales): cover skip_type_mismatch, skip_item_unresolved, skip_amount_mismatch for detail backfill"
```

---

## Task 5: Service — Misc-matched item, Transportation happy path, and never touching a non-outstanding-bills-archive Invoice

**Files:**
- Modify: `backend/laravel-api/tests/Feature/SalesInvoiceDetailBackfillServiceTest.php`

- [ ] **Step 1: Add the failing tests**

```php
    public function test_commit_resolves_an_unmatched_item_code_against_miscellaneous_item(): void
    {
        $account = \App\Models\ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $misc = MiscellaneousItem::query()->create([
            'misc_code' => 'TRANSPORT', 'description' => 'Ongkos Angkut', 'rate' => 0,
            'charge_type' => 'addition', 'unit_cost' => 0,
            'sales_account_id' => $account->id, 'purchase_account_id' => $account->id,
        ]);
        $invoice = $this->makeOutstandingBillsInvoice('SI/KE/08111/09/2026', 'goods', 150000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 150000, '', ''],
            ['TRANSPORT', '', 'Ongkos Angkut', '', 'UNIT', 1, 150000, 0, 0, '', 150000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $this->service->commit($this->writeFixture($csv), 'csv');

        $line = $invoice->items()->sole();
        $this->assertNull($line->item_id);
        $this->assertSame('Ongkos Angkut', $line->item_name);
    }

    public function test_commit_writes_freeform_items_for_a_transportation_group_without_any_item_lookup(): void
    {
        $invoice = $this->makeOutstandingBillsInvoice('TR/KE/07644/10/2026', 'transportation', 2000000);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['10/10/2026', 'TR/KE/07644/10/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 2000000, '', ''],
            // col 0 ("ITEM #") is always the literal code "TRANSPORT" on a TR document, never a
            // real Item code — no Item/MiscellaneousItem lookup is ever attempted for it (see
            // SalesInvoiceHistoryParser's own docblock + this service's classifyOne()).
            ['TRANSPORT', '', 'Angkutan Semen ke Gudang', '', 'UNIT', 1, 2000000, 0, 0, '', 2000000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $result = $this->service->commit($this->writeFixture($csv), 'csv');

        $this->assertSame(1, $result['updated']);
        $line = $invoice->refresh()->items()->sole();
        $this->assertNull($line->item_id);
        $this->assertSame('Angkutan Semen ke Gudang', $line->item_name);
        $this->assertEquals(2000000, (float) $line->amount);
        $this->assertEquals(2000000, (float) $invoice->grand_total, 'grand_total must stay untouched');
    }

    public function test_commit_never_touches_an_invoice_that_is_not_from_the_outstanding_bills_archive(): void
    {
        $manual = Invoice::query()->create([
            'customer_id' => $this->customer->id,
            'invoice_type' => 'goods',
            'invoice_date' => '2026-09-12', 'due_date' => '2026-10-12',
            'subtotal' => 150000, 'discount_amount' => 0, 'tax_amount' => 0, 'grand_total' => 150000,
            'source_document_number' => 'SI/KE/08111/09/2026',
        ]);
        $originalItem = InvoiceItem::query()->create([
            'invoice_id' => $manual->id, 'item_name' => 'Manually Entered Line',
            'uom' => null, 'rate' => 150000, 'qty' => 1, 'qty_category' => 'weight', 'amount' => 150000,
        ]);

        $csv = $this->csv([
            ['SALES INVOICE LISTING - DETAIL'],
            ['12/09/2026 - 12/10/2026 - Base Currency'],
            [], [], ['PT. KALINDO ETAM'], [], [],
            self::HEADER_ROW,
            self::ITEM_SUB_HEADER,
            ['12/09/2026', 'SI/KE/08111/09/2026', 'C-1364', 'TK. DENDYA', '', '', '', 0, 0, '', 150000, '', ''],
            ['MISC', '', 'Something', '', 'UNIT', 1, 150000, 0, 0, '', 150000],
            ['TAX SUMMARY'],
            ['Printed By : Admin'],
        ]);

        $result = $this->service->commit($this->writeFixture($csv), 'csv');

        $this->assertSame(0, $result['updated']);
        $this->assertNotNull(InvoiceItem::query()->find($originalItem->id), 'the manually-entered line must survive untouched');
    }
```

- [ ] **Step 2: Run to verify all pass**

```bash
php artisan test --filter=SalesInvoiceDetailBackfillServiceTest
```

Expected: PASS, 9 tests.

- [ ] **Step 3: Run the full backend suite to check for regressions**

```bash
php -d max_execution_time=0 artisan test
```

Expected: all green except any pre-existing unrelated flake (none of this task's files touch anything else).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/SalesInvoiceDetailBackfillServiceTest.php
git commit -m "test(sales): cover Misc-matched items, Transportation freeform lines, and non-archive Invoice isolation for detail backfill"
```

---

## Task 6: Controller, request, routes

**Files:**
- Create: `backend/laravel-api/app/Http/Requests/StoreSalesInvoiceDetailBackfillRequest.php`
- Create: `backend/laravel-api/app/Http/Controllers/Api/V1/SalesInvoiceDetailBackfillController.php`
- Modify: `backend/laravel-api/routes/api.php`

No dedicated HTTP-level test — matches this app's established convention for import controllers (`CustomerOutstandingArchiveController` has none either; `SalesInvoiceDetailBackfillServiceTest` already covers the logic). This task is verified manually in Task 8.

- [ ] **Step 1: Create the request validator**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesInvoiceDetailBackfillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls'],
        ];
    }
}
```

- [ ] **Step 2: Create the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ImportBatchStatus;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSalesInvoiceDetailBackfillRequest;
use App\Http\Resources\ImportBatchResource;
use App\Models\ImportBatch;
use App\Services\AuditLogService;
use App\Services\Import\SalesInvoiceDetailBackfillService;
use App\Support\ImportErrorReportWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fills in real item-level detail on Invoices CustomerOutstandingArchiveImportService already
 * created — see SalesInvoiceDetailBackfillService's own docblock. Same preflight/resolve shape
 * as CustomerOutstandingArchiveController: store() always leaves the batch PREVIEWED, resolve()
 * commits synchronously (these files are small, same posture as that sibling controller).
 */
class SalesInvoiceDetailBackfillController extends Controller
{
    use ApiResponse;

    private const MODULE = 'sales-invoice-detail-backfill';

    public function __construct(protected SalesInvoiceDetailBackfillService $service) {}

    public function store(StoreSalesInvoiceDetailBackfillRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('imports', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $preflight = $this->service->preflight($absolutePath, $file->getClientOriginalExtension());
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        $batch = ImportBatch::query()->create([
            'module' => self::MODULE,
            'status' => ImportBatchStatus::PREVIEWED,
            'original_filename' => $file->getClientOriginalName(),
            'disk' => 'local',
            'file_path' => $path,
            'total_rows' => $preflight['total_rows'],
            'preview_summary' => $preflight,
            'created_by' => Auth::id(),
        ]);

        ImportErrorReportWriter::attachRejectedRows($batch, $this->rejectedRowsReport($preflight));

        return $this->success(new ImportBatchResource($batch), 'Menunggu konfirmasi import.', 201);
    }

    /**
     * Flattens preflight()'s 5 skip categories into one CSV-ready report — lets the operator
     * download exactly which invoices won't be updated, and why, from the preview step.
     *
     * @param  array{skipped_not_found: array, skipped_customer_mismatch: array, skipped_type_mismatch: array, skipped_item_unresolved: array, skipped_amount_mismatch: array}  $preflight
     */
    private function rejectedRowsReport(array $preflight): array
    {
        $reasonByOutcome = [
            'skipped_not_found' => 'Nomor dokumen tidak ditemukan pada Invoice hasil import Customer Outstanding Bills',
            'skipped_customer_mismatch' => 'Customer pada file tidak cocok dengan Invoice yang ditemukan',
            'skipped_type_mismatch' => 'Jenis dokumen (Goods/Transportation) tidak cocok dengan Invoice yang ditemukan',
            'skipped_item_unresolved' => 'Ada kode ITEM yang tidak ditemukan di master Item maupun Miscellaneous Item',
            'skipped_amount_mismatch' => 'Total hasil hitung ulang dari file berbeda dari grand_total Invoice yang sudah ada',
        ];

        $report = [];
        foreach ($reasonByOutcome as $key => $reason) {
            foreach ($preflight[$key] as $row) {
                $report[] = [...$row, 'status' => $key, 'reason' => $reason];
            }
        }

        return $report;
    }

    public function resolve(ImportBatch $batch, AuditLogService $auditLogService): JsonResponse
    {
        abort_unless($batch->module === self::MODULE, 404);
        abort_unless($batch->status === ImportBatchStatus::PREVIEWED, 422, 'This batch is not awaiting resolution.');

        $absolutePath = Storage::disk($batch->disk)->path($batch->file_path);
        $extension = pathinfo($batch->file_path, PATHINFO_EXTENSION);

        try {
            $result = $this->service->commit($absolutePath, $extension);
        } finally {
            Storage::disk($batch->disk)->delete($batch->file_path);
        }

        $batch->update(['status' => ImportBatchStatus::COMPLETED, 'success_rows' => $result['updated']]);

        $auditLogService->record(
            'imported',
            'sales_invoice_detail_backfill',
            "Updated item detail on {$result['updated']} Invoice(s) from Sales Invoice Listing - Detail.",
            userId: $batch->created_by,
        );

        return $this->success($result, 'Detail invoice berhasil disinkronkan.', 201);
    }
}
```

- [ ] **Step 3: Register the routes**

In `routes/api.php`, add the import alphabetically among the other `App\Http\Controllers\Api\V1\...` use statements (near `SalesPurchaseJournalImportController`):

```php
use App\Http\Controllers\Api\V1\SalesInvoiceDetailBackfillController;
```

Then add these two lines directly after the existing `invoices/{invoice}/branch` route (currently line 418 — search for that route to find the exact spot):

```php
    // Sales Invoice detail backfill: fills in real item detail on Invoices the Customer
    // Outstanding Bills import already created — never creates a new Invoice. Reuses the
    // existing sales.invoices.import permission (already in RolePermissionSeeder, unused until
    // now — no seeder change needed).
    Route::post('invoices/import-detail', [SalesInvoiceDetailBackfillController::class, 'store'])->middleware('permission:sales.invoices.import');
    Route::post('invoices/import-detail/{batch}/resolve', [SalesInvoiceDetailBackfillController::class, 'resolve'])->middleware('permission:sales.invoices.import');
```

- [ ] **Step 4: Verify routes are registered and PHP has no syntax errors**

```bash
php -l app/Http/Requests/StoreSalesInvoiceDetailBackfillRequest.php
php -l app/Http/Controllers/Api/V1/SalesInvoiceDetailBackfillController.php
php artisan route:list --path=invoices/import-detail
```

Expected: both `php -l` calls print `No syntax errors detected`; `route:list` shows both POST routes with `permission:sales.invoices.import` in their middleware.

- [ ] **Step 5: Run the full backend suite**

```bash
php -d max_execution_time=0 artisan test
```

Expected: all green except any pre-existing unrelated flake.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/StoreSalesInvoiceDetailBackfillRequest.php app/Http/Controllers/Api/V1/SalesInvoiceDetailBackfillController.php routes/api.php
git commit -m "feat(sales): expose Sales Invoice detail backfill via API

POST invoices/import-detail (preflight) + POST invoices/import-detail/
{batch}/resolve (commit), mirroring CustomerOutstandingArchiveController's
shape. Reuses the existing sales.invoices.import permission."
```

---

## Task 7: Frontend — types, API client

**Files:**
- Modify: `frontend/src/features/sales/types.ts`
- Create: `frontend/src/features/sales/api/invoiceDetailBackfillApi.ts`

- [ ] **Step 1: Add the types**

Append to `frontend/src/features/sales/types.ts`:

```typescript
export interface SalesInvoiceDetailBackfillSkippedRow {
  document_number: string
  customer_code?: string
  file_customer_code?: string
  invoice_customer_code?: string
  file_type?: string
  invoice_type?: string
  file_amount?: number
  computed_amount?: number
}

export interface SalesInvoiceDetailBackfillPreview {
  total_rows: number
  will_update: number
  skipped_not_found: SalesInvoiceDetailBackfillSkippedRow[]
  skipped_customer_mismatch: SalesInvoiceDetailBackfillSkippedRow[]
  skipped_type_mismatch: SalesInvoiceDetailBackfillSkippedRow[]
  skipped_item_unresolved: SalesInvoiceDetailBackfillSkippedRow[]
  skipped_amount_mismatch: SalesInvoiceDetailBackfillSkippedRow[]
  warnings: string[]
}

export interface SalesInvoiceDetailBackfillImportBatch {
  id: string
  status: string
  total_rows: number
  preview_summary: SalesInvoiceDetailBackfillPreview | null
  has_failed_rows: boolean
}

export interface SalesInvoiceDetailBackfillResult {
  updated: number
  document_numbers: string[]
}
```

- [ ] **Step 2: Create the API client**

```typescript
import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import type { SalesInvoiceDetailBackfillImportBatch, SalesInvoiceDetailBackfillResult } from '../types'

/** Preflight only — parses and validates, returns a batch awaiting confirmation. Nothing is committed yet. */
export async function storeSalesInvoiceDetailBackfill(file: File): Promise<SalesInvoiceDetailBackfillImportBatch> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ApiResponse<SalesInvoiceDetailBackfillImportBatch>>('/invoices/import-detail', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Commits the previously previewed batch — runs synchronously and returns the final counts. */
export async function resolveSalesInvoiceDetailBackfill(batchId: string): Promise<SalesInvoiceDetailBackfillResult> {
  const { data } = await apiClient.post<ApiResponse<SalesInvoiceDetailBackfillResult>>(`/invoices/import-detail/${batchId}/resolve`)
  return data.data
}
```

- [ ] **Step 3: Type-check**

```bash
cd frontend
npx tsc -b
```

Expected: no output (clean).

- [ ] **Step 4: Commit**

```bash
git add src/features/sales/types.ts src/features/sales/api/invoiceDetailBackfillApi.ts
git commit -m "feat(sales): add frontend types + API client for Sales Invoice detail backfill"
```

---

## Task 8: Frontend — import dialog + list page button

**Files:**
- Create: `frontend/src/features/sales/components/SalesInvoiceDetailBackfillImportDialog.tsx`
- Modify: `frontend/src/features/sales/pages/InvoiceListPage.tsx`

- [ ] **Step 1: Create the dialog component**

```tsx
import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Download, Loader2, Upload } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { toast } from 'sonner'
import { formatCurrency, formatNumber } from '@/lib/utils'
import { toastApiError } from '@/shared/services/errorHandler'
import { downloadImportBatchFailedRows } from '@/shared/lib/downloadImportBatchFailedRows'
import { resolveSalesInvoiceDetailBackfill, storeSalesInvoiceDetailBackfill } from '../api/invoiceDetailBackfillApi'
import type { SalesInvoiceDetailBackfillImportBatch, SalesInvoiceDetailBackfillSkippedRow } from '../types'

const MODULE = 'sales-invoice-detail-backfill'

function SkippedRowsList({ rows }: { rows: SalesInvoiceDetailBackfillSkippedRow[] }) {
  return (
    <ul className="mt-1 list-disc pl-5">
      {rows.slice(0, 10).map((row, i) => (
        <li key={i}>{row.document_number}</li>
      ))}
      {rows.length > 10 && (
        <li className="list-none text-xs italic">...dan {formatNumber(rows.length - 10)} baris lainnya — unduh CSV untuk daftar lengkap.</li>
      )}
    </ul>
  )
}

type Step = 'setup' | 'preview'

interface SalesInvoiceDetailBackfillImportDialogProps {
  open: boolean
  onClose: () => void
  onImported: () => void
}

/**
 * Upload -> preview -> confirm. Only fills in real item detail on Invoices already created by
 * the Customer Outstanding Bills import — never creates a new Invoice. See
 * SalesInvoiceDetailBackfillService's own docblock for the matching/validation rules.
 */
export function SalesInvoiceDetailBackfillImportDialog({ open, onClose, onImported }: SalesInvoiceDetailBackfillImportDialogProps) {
  const queryClient = useQueryClient()
  const [step, setStep] = useState<Step>('setup')
  const [file, setFile] = useState<File | null>(null)
  const [batch, setBatch] = useState<SalesInvoiceDetailBackfillImportBatch | null>(null)

  const reset = () => {
    setStep('setup')
    setFile(null)
    setBatch(null)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const uploadMutation = useMutation({
    mutationFn: () => storeSalesInvoiceDetailBackfill(file as File),
    onSuccess: (result) => {
      setBatch(result)
      setStep('preview')
    },
    onError: (error) => toastApiError(error),
  })

  const resolveMutation = useMutation({
    mutationFn: () => resolveSalesInvoiceDetailBackfill(batch?.id ?? ''),
    onSuccess: (result) => {
      toast.success(`Detail invoice berhasil disinkronkan: ${result.updated} Invoice diperbarui.`)
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      onImported()
      handleClose()
    },
    onError: (error) => toastApiError(error),
  })

  const downloadMutation = useMutation({
    mutationFn: () => downloadImportBatchFailedRows(batch?.id ?? '', MODULE),
    onError: (error) => toastApiError(error),
  })

  const preview = batch?.preview_summary ?? null
  const hasSkipped =
    !!preview &&
    (preview.skipped_not_found.length > 0 ||
      preview.skipped_customer_mismatch.length > 0 ||
      preview.skipped_type_mismatch.length > 0 ||
      preview.skipped_item_unresolved.length > 0 ||
      preview.skipped_amount_mismatch.length > 0)

  return (
    <Dialog open={open} onOpenChange={(next) => !next && handleClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Import Detail — Sales Invoice Listing</DialogTitle>
          <DialogDescription>
            {step === 'setup' &&
              'Upload file export "Sales Invoice Listing - Detail" (.xlsx) apa adanya. Hanya invoice yang sudah ada di sistem ini (hasil import Customer Outstanding Bills) dan nomor dokumennya cocok akan diperbarui detailnya — tidak ada invoice baru yang dibuat.'}
            {step === 'preview' && 'Ringkasan sebelum import — periksa dulu sebelum melanjutkan.'}
          </DialogDescription>
        </DialogHeader>

        {step === 'setup' && (
          <div className="flex flex-col gap-1.5">
            <Label>File</Label>
            <input type="file" accept=".csv,.xlsx,.xls" onChange={(event) => setFile(event.target.files?.[0] ?? null)} className="text-sm" />
          </div>
        )}

        {step === 'preview' && preview && (
          <div className="flex max-h-[28rem] flex-col gap-4 overflow-y-auto">
            <div className="flex flex-col gap-2 rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium">Akan diperbarui</span>
                <Badge variant="secondary">
                  {formatNumber(preview.will_update)} dari {formatNumber(preview.total_rows)} invoice di file
                </Badge>
              </div>
            </div>

            {hasSkipped && (
              <Alert variant="destructive">
                <AlertTitle>Dilewati</AlertTitle>
                <AlertDescription>
                  <div className="flex flex-col gap-2 text-sm">
                    {preview.skipped_not_found.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_not_found.length}</Badge>
                        Nomor dokumen tidak ditemukan pada Invoice hasil import Customer Outstanding Bills:
                        <SkippedRowsList rows={preview.skipped_not_found} />
                      </div>
                    )}
                    {preview.skipped_customer_mismatch.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_customer_mismatch.length}</Badge>
                        Customer pada file tidak cocok dengan Invoice yang ditemukan:
                        <SkippedRowsList rows={preview.skipped_customer_mismatch} />
                      </div>
                    )}
                    {preview.skipped_type_mismatch.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_type_mismatch.length}</Badge>
                        Jenis dokumen tidak cocok dengan Invoice yang ditemukan:
                        <SkippedRowsList rows={preview.skipped_type_mismatch} />
                      </div>
                    )}
                    {preview.skipped_item_unresolved.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_item_unresolved.length}</Badge>
                        Ada kode ITEM yang tidak ditemukan di master Item maupun Miscellaneous Item:
                        <SkippedRowsList rows={preview.skipped_item_unresolved} />
                      </div>
                    )}
                    {preview.skipped_amount_mismatch.length > 0 && (
                      <div>
                        <Badge variant="secondary" className="mr-1.5">{preview.skipped_amount_mismatch.length}</Badge>
                        Total hasil hitung ulang berbeda dari grand_total Invoice yang sudah ada:
                        <ul className="mt-1 list-disc pl-5">
                          {preview.skipped_amount_mismatch.slice(0, 10).map((row, i) => (
                            <li key={i}>
                              {row.document_number}: file {formatCurrency(row.file_amount ?? 0)}, hasil hitung {formatCurrency(row.computed_amount ?? 0)}
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </div>
                </AlertDescription>
              </Alert>
            )}
          </div>
        )}

        <DialogFooter>
          {step === 'setup' && (
            <Button type="button" onClick={() => uploadMutation.mutate()} disabled={!file || uploadMutation.isPending}>
              {uploadMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              <Upload className="size-4" />
              Upload
            </Button>
          )}
          {step === 'preview' && batch?.has_failed_rows && (
            <Button type="button" variant="outline" onClick={() => downloadMutation.mutate()} disabled={downloadMutation.isPending}>
              {downloadMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
              Unduh Baris Dilewati
            </Button>
          )}
          {step === 'preview' && (
            <Button type="button" onClick={() => resolveMutation.mutate()} disabled={resolveMutation.isPending}>
              {resolveMutation.isPending && <Loader2 className="size-4 animate-spin" />}
              Konfirmasi & Sinkronkan
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
```

- [ ] **Step 2: Wire it into `InvoiceListPage.tsx`**

In `frontend/src/features/sales/pages/InvoiceListPage.tsx`:

1. Add `Upload` to the existing lucide-react import (line 5):

```typescript
import { Ban, Download, Eye, Pencil, Plus, Printer, RotateCw, Send, Trash2, Upload } from 'lucide-react'
```

2. Add the dialog import, right after the `InvoiceFiltersBar` import:

```typescript
import { SalesInvoiceDetailBackfillImportDialog } from '../components/SalesInvoiceDetailBackfillImportDialog'
```

3. Add the permission check and dialog-open state, right after the existing `canEditSubmitted` line:

```typescript
  const canImportDetail = useHasPermission('sales.invoices.import')
  const [importDetailOpen, setImportDetailOpen] = useState(false)
```

4. Add the button into the `ActionBar`'s `actions` array (currently just `Refresh`):

```tsx
            <ActionBar
              actions={[
                { label: 'Refresh', icon: RotateCw, onClick: () => listQuery.refetch(), disabled: listQuery.isFetching },
                ...(canImportDetail ? [{ label: 'Import Detail', icon: Upload, onClick: () => setImportDetailOpen(true) }] : []),
              ]}
              primary={canCreate ? { label: 'New Invoice', icon: Plus, onClick: () => navigate('/sales/invoices/new') } : undefined}
            />
```

5. Mount the dialog at the end of the component, right after the existing `<DeleteDialog ... />`:

```tsx
      <SalesInvoiceDetailBackfillImportDialog
        open={importDetailOpen}
        onClose={() => setImportDetailOpen(false)}
        onImported={() => listQuery.refetch()}
      />
```

- [ ] **Step 3: Type-check**

```bash
cd frontend
npx tsc -b
```

Expected: no output (clean).

- [ ] **Step 4: Commit**

```bash
git add src/features/sales/components/SalesInvoiceDetailBackfillImportDialog.tsx src/features/sales/pages/InvoiceListPage.tsx
git commit -m "feat(sales): add Import Detail button + dialog to the Invoice list page

Upload -> preview (will_update count + per-reason skip breakdown +
rejected-rows download) -> confirm. Reuses ImportBatchResource/
ImportController's generic failed-rows endpoint, no new backend routes
needed for the download."
```

---

## Task 9: Final verification and push

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend suite one more time**

```bash
cd backend/laravel-api
php -d max_execution_time=0 artisan test
```

Expected: all green except any pre-existing unrelated flake (confirm by checking the failing test's file isn't one this plan touched).

- [ ] **Step 2: Run Pint on every new/changed PHP file**

```bash
./vendor/bin/pint app/Services/Import/SalesInvoiceHistoryParser.php app/Services/Import/SalesInvoiceDetailBackfillService.php app/Http/Requests/StoreSalesInvoiceDetailBackfillRequest.php app/Http/Controllers/Api/V1/SalesInvoiceDetailBackfillController.php tests/Feature/SalesInvoiceDetailBackfillServiceTest.php routes/api.php
```

Expected: `"result":"passed"` or `"result":"fixed"`. If `"fixed"`, re-run the full test suite from Step 1 once more before continuing.

- [ ] **Step 3: Final frontend type-check**

```bash
cd frontend
npx tsc -b
```

Expected: no output.

- [ ] **Step 4: Push everything**

```bash
cd ..
git push origin main
```

- [ ] **Step 5: Tell the user to deploy and manually verify**

After the user runs `git pull && composer install && npm run build` (no new PHP or npm dependencies in this plan, so `composer install`/`npm install` are no-ops, but `npm run build` is required — this is a frontend change and the VPS never auto-builds) on production:

1. Open **Sales > Invoices**, confirm the **Import Detail** button appears next to **New Invoice** (requires the logged-in user's role to have `sales.invoices.import` — Admin already does).
2. Upload the real `xlsSalesInvoiceListing_Detail (1).xlsx` file, check the preview's `will_update` count and skip breakdown look sane for a 3,778-row file.
3. Confirm the import, then open the printed paper for `C-1364 / SI/KE/03827/05/2026` (or any invoice the preview reported as updated) and confirm it now shows real items/qty/price instead of the single generic line, with `grand_total` unchanged from before.
