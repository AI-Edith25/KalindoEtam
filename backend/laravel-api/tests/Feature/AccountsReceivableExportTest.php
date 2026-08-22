<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\AccountsReceivable;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\ReceiptEntry;
use App\Models\SalesOrder;
use App\Models\SalesPerson;
use App\Models\User;
use App\Repositories\CreditNoteRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\PaymentAllocationRepository;
use App\Services\PaymentAllocationService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DocumentEngineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * C2 (UAT review 2026-08-12), rebuilt to match the client's real legacy-system export files
 * ("Customer Detail Aging" / "Customer Summary Aging") — see AccountsReceivableAgingReportService's
 * docblock for the verified aging-bucket rule (calendar-month diff of invoice date vs "as at",
 * NOT a days-overdue threshold) and the row/footer structure this replicates.
 */
class AccountsReceivableExportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DocumentEngineSeeder::class);

        $this->company = Company::query()->create(['code' => 'TC', 'name' => 'Test Co', 'fiscal_year_start' => now()->startOfYear()->toDateString()]);
        Branch::query()->create(['code' => 'HQ', 'company_id' => $this->company->id, 'name' => 'Main']);
        $this->customer = Customer::query()->create(['customer_code' => 'C001', 'customer_name' => 'Acme', 'phone' => '0812', 'credit_limit' => 20000000]);
    }

    protected function actingUserWithArDetailView(): void
    {
        Permission::query()->firstOrCreate(['name' => 'reports.ar_detail.view', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->givePermissionTo('reports.ar_detail.view');
        Sanctum::actingAs($user);
    }

    protected function makeReceivable(Customer $customer, float $amount, float $paid, Carbon $invoiceDate, Carbon $dueDate, ?SalesPerson $salesPerson = null, string $status = 'unpaid'): AccountsReceivable
    {
        $salesOrderId = null;
        if ($salesPerson) {
            $salesOrderId = SalesOrder::query()->create([
                'customer_id' => $customer->id,
                'sales_person_id' => $salesPerson->id,
                'status' => 'submitted',
                'order_date' => $invoiceDate->toDateString(),
            ])->id;
        }

        $invoice = Invoice::query()->create([
            'invoice_type' => 'goods',
            'status' => 'submitted',
            'sales_order_id' => $salesOrderId,
            'customer_id' => $customer->id,
            'invoice_date' => $invoiceDate->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'subtotal' => $amount,
            'discount_amount' => 0,
            'discount_type' => 'amount',
            'tax_amount' => 0,
            'grand_total' => $amount,
        ]);

        return AccountsReceivable::query()->create([
            'customer_id' => $customer->id,
            'sales_order_id' => $salesOrderId,
            'invoice_id' => $invoice->id,
            'reference_number' => $invoice->document_number,
            'amount' => $amount,
            'paid_amount' => $paid,
            'due_date' => $dueDate->toDateString(),
            'status' => $status,
        ]);
    }

    protected function downloadXlsx(string $query): Worksheet
    {
        $response = $this->get("/api/v1/accounts-receivables/export?{$query}");
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'ar-aging').'.xlsx';
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getActiveSheet();
        unlink($tmpPath);

        return $sheet;
    }

    public function test_detail_xlsx_title_headers_customer_block_and_grand_total(): void
    {
        $this->actingUserWithArDetailView();
        $salesPerson = SalesPerson::query()->create(['code' => 'SP1', 'name' => 'Budi']);
        $this->makeReceivable($this->customer, 100000, 0, now(), now()->addDays(30), $salesPerson);

        $sheet = $this->downloadXlsx('type=detail&format=xlsx');

        $this->assertEquals('Customers Detail Aging - By Customer', $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString('Filter By Date : as at '.now()->format('d/m/Y'), $sheet->getCell('A2')->getValue());
        $this->assertEquals('Test Co', $sheet->getCell('A3')->getValue());

        // Header row 5 — exact text incl. leading-space quirk on 2/3/4 Month, Arial 9 bold on J:L.
        $this->assertEquals('Document No.', $sheet->getCell('A5')->getValue());
        $this->assertEquals('1 Month', $sheet->getCell('D5')->getValue());
        $this->assertEquals(' 2 Month', $sheet->getCell('E5')->getValue());
        $this->assertEquals(' 3 Month', $sheet->getCell('F5')->getValue());
        $this->assertEquals(' 4 Month', $sheet->getCell('G5')->getValue());
        $this->assertEquals('>4 Months', $sheet->getCell('H5')->getValue());
        $this->assertEquals('Arial', $sheet->getStyle('K5')->getFont()->getName());
        $this->assertEquals(9, $sheet->getStyle('K5')->getFont()->getSize());
        $this->assertEquals('Calibri', $sheet->getStyle('A5')->getFont()->getName());

        // Customer header block — always-blank Sales Person/Ctn/Tel/Fax, merged A:D and E:L.
        $this->assertEquals('Customer : C001 - Acme, Sales Person : ', $sheet->getCell('A7')->getValue());
        $this->assertEquals('Ctn: , 0812, Tel : , Fax : , Terms : 0 days, Credit Limit : 20,000,000.00, Currency Code : RP', $sheet->getCell('E7')->getValue());

        // Data row — invoiced this month (bucket = Current), not yet due.
        $this->assertEquals(100000, $sheet->getCell('C8')->getValue());
        $this->assertEquals(0, $sheet->getCell('D8')->getValue()); // 1 Month bucket — genuinely zero, must not render blank
        $this->assertEquals(100000, $sheet->getCell('I8')->getValue()); // Total Outstanding
        $this->assertEquals('-', $sheet->getCell('K8')->getValue()); // Overdue Days — not yet due
        $this->assertEquals(0, $sheet->getCell('L8')->getValue());

        // Subtotal row (9) bold, then blank (10), then Grand Total (11) — label NOT bold (verified from the real reference file).
        $this->assertTrue($sheet->getStyle('C9')->getFont()->getBold());
        $this->assertEquals('Grand Total', $sheet->getCell('A11')->getValue());
        $this->assertFalse($sheet->getStyle('A11')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('C11')->getFont()->getBold());
        $this->assertEquals(100000, $sheet->getCell('C11')->getValue());
        $this->assertEquals(100000, $sheet->getCell('I11')->getValue());

        // Summary footer block — BALANCE == sum of the 6 bucket totals == Grand Total's Total Outstanding.
        $this->assertEquals('Summary', $sheet->getCell('A13')->getValue());
        $this->assertEquals('Current', $sheet->getCell('B14')->getValue());
        $this->assertEquals(100000, $sheet->getCell('C14')->getValue());
        $this->assertEquals('100.00%', $sheet->getCell('D14')->getValue());
        $this->assertEquals('BALANCE ', $sheet->getCell('B20')->getValue());
        $this->assertEquals(100000, $sheet->getCell('C20')->getValue());
        $this->assertTrue($sheet->getStyle('C20')->getFont()->getBold());
    }

    public function test_summary_xlsx_headers_and_grand_total_bold_columns(): void
    {
        $this->actingUserWithArDetailView();
        $this->makeReceivable($this->customer, 100000, 0, now(), now()->addDays(30));

        $sheet = $this->downloadXlsx('type=summary&format=xlsx');

        $this->assertEquals('Customers Summary Aging', $sheet->getCell('A1')->getValue());
        $this->assertEquals('No', $sheet->getCell('A5')->getValue());
        $this->assertEquals('Ledger Balance', $sheet->getCell('N5')->getValue());
        $this->assertEquals(' 2 Month', $sheet->getCell('H5')->getValue());

        $this->assertEquals(1, $sheet->getCell('A6')->getValue());
        $this->assertEquals('C001', $sheet->getCell('B6')->getValue());
        $this->assertEquals('Acme', $sheet->getCell('C6')->getValue());
        $this->assertEquals(100000, $sheet->getCell('F6')->getValue()); // Current bucket
        $this->assertEquals(100000, $sheet->getCell('N6')->getValue()); // Ledger Balance
        $this->assertEquals(20000000, $sheet->getCell('O6')->getValue()); // Credit Limit

        // Grand Total: F-N bold+bordered (Ledger Balance summed too, verified from the real file), E/O left alone.
        $this->assertEquals('Grand Total', $sheet->getCell('A8')->getValue());
        $this->assertTrue($sheet->getStyle('F8')->getFont()->getBold());
        $this->assertTrue($sheet->getStyle('N8')->getFont()->getBold());
        $this->assertEquals(100000, $sheet->getCell('N8')->getValue());
        $this->assertEquals('', $sheet->getCell('O8')->getValue());
    }

    public function test_bucket_rule_is_calendar_month_difference_between_invoice_date_and_as_at_not_days_overdue(): void
    {
        $this->actingUserWithArDetailView();
        $asAt = now();

        // One invoice per bucket offset (0..5 months back), same due date (irrelevant to bucket) — proves bucket tracks invoice month, not overdue days.
        foreach (range(0, 5) as $monthsBack) {
            $this->makeReceivable($this->customer, 10000 + $monthsBack, 0, $asAt->copy()->subMonthsNoOverflow($monthsBack), $asAt->copy()->addDays(5));
        }

        $sheet = $this->downloadXlsx('type=detail&format=xlsx');

        // Single customer block: header row 7, 6 data rows 8-13.
        $bucketColumns = ['C', 'D', 'E', 'F', 'G', 'H'];
        for ($row = 8; $row <= 13; $row++) {
            $monthsBack = $row - 8;
            $expectedAmount = 10000 + $monthsBack;
            $expectedCol = $bucketColumns[$monthsBack];
            foreach ($bucketColumns as $col) {
                $expected = $col === $expectedCol ? $expectedAmount : 0;
                $this->assertEquals($expected, $sheet->getCell("{$col}{$row}")->getValue(), "row {$row} col {$col} (monthsBack={$monthsBack})");
            }
        }
    }

    public function test_ledger_balance_reflects_full_unfiltered_balance_not_just_the_filtered_set(): void
    {
        $this->actingUserWithArDetailView();
        $this->makeReceivable($this->customer, 50000, 0, now(), now()->addDays(30)); // due within range, included by the filter below
        $this->makeReceivable($this->customer, 30000, 0, now(), now()->addDays(200)); // due far out, excluded by the date_to filter below — but still outstanding

        $sheet = $this->downloadXlsx('type=summary&format=xlsx&date_to='.now()->addDays(60)->toDateString());

        $this->assertEquals(50000, $sheet->getCell('L6')->getValue()); // Total Outstanding — filtered set only
        $this->assertEquals(80000, $sheet->getCell('N6')->getValue()); // Ledger Balance — full unfiltered balance, ignores the date_to filter
    }

    public function test_customer_with_no_sales_person_renders_blank_summary_column(): void
    {
        $this->actingUserWithArDetailView();
        $this->makeReceivable($this->customer, 50000, 0, now(), now()->addDays(30)); // no SalesPerson passed -> no SalesOrder at all

        $sheet = $this->downloadXlsx('type=summary&format=xlsx');

        $this->assertNull($sheet->getCell('D6')->getValue());
    }

    public function test_negative_outstanding_from_an_overpaid_receivable_lands_in_the_correct_bucket(): void
    {
        $this->actingUserWithArDetailView();
        $this->makeReceivable($this->customer, 50000, 80000, now(), now()->addDays(30)); // overpaid: outstanding = -30000

        $sheet = $this->downloadXlsx('type=detail&format=xlsx');

        $this->assertEquals(-30000, $sheet->getCell('C8')->getValue()); // Current bucket, negative
        $this->assertEquals(-30000, $sheet->getCell('I8')->getValue());
    }

    public function test_invoice_not_yet_due_renders_dash_for_overdue_days_and_zero_overdue_amount(): void
    {
        $this->actingUserWithArDetailView();
        $this->makeReceivable($this->customer, 50000, 0, now(), now()->addDays(10));

        $sheet = $this->downloadXlsx('type=detail&format=xlsx');

        $this->assertEquals('-', $sheet->getCell('K8')->getValue());
        $this->assertEquals(0, $sheet->getCell('L8')->getValue());
    }

    public function test_selection_narrows_every_subtotal_but_ledger_balance_stays_full(): void
    {
        $this->actingUserWithArDetailView();
        $other = Customer::query()->create(['customer_code' => 'C002', 'customer_name' => 'Other']);

        $selected = $this->makeReceivable($this->customer, 40000, 0, now(), now()->addDays(30));
        $this->makeReceivable($this->customer, 60000, 0, now(), now()->addDays(30)); // same customer, not selected
        $this->makeReceivable($other, 20000, 0, now(), now()->addDays(30)); // different customer, not selected

        $sheet = $this->downloadXlsx('type=summary&format=xlsx&invoice_ids[]='.$selected->invoice_id);

        // Only the selected customer/invoice appears at all.
        $this->assertEquals('C001', $sheet->getCell('B6')->getValue());
        $this->assertNull($sheet->getCell('B7')->getValue());

        $this->assertEquals(40000, $sheet->getCell('L6')->getValue()); // Total Outstanding — selection-scoped
        $this->assertEquals(100000, $sheet->getCell('N6')->getValue()); // Ledger Balance — customer's full balance (both their receivables), ignores the selection

        $this->assertEquals('Grand Total', $sheet->getCell('A8')->getValue());
        $this->assertEquals(40000, $sheet->getCell('L8')->getValue());
    }

    public function test_csv_uses_raw_numbers_not_comma_formatted_and_ddmmyyyy_dates(): void
    {
        $this->actingUserWithArDetailView();
        $this->makeReceivable($this->customer, 6225000.27, 0, now(), now()->addDays(30));

        $response = $this->get('/api/v1/accounts-receivables/export?type=detail&format=csv');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('6225000.27', $content); // raw number, no thousands separator
        $this->assertStringNotContainsString('6,225,000.27', $content);
        $this->assertStringContainsString(now()->format('d/m/Y'), $content); // date as DD/MM/YYYY text
    }

    public function test_mtd_ytd_aggregates_used_by_the_summary_footer(): void
    {
        $invoiceRepository = app(InvoiceRepository::class);
        $paymentAllocationRepository = app(PaymentAllocationRepository::class);
        $creditNoteRepository = app(CreditNoteRepository::class);

        $ar = $this->makeReceivable($this->customer, 100000, 0, now(), now()->addDays(30));

        $this->seed(ChartOfAccountsSeeder::class);

        $receiptEntry = ReceiptEntry::query()->create([
            'customer_id' => $this->customer->id,
            'receipt_date' => now()->toDateString(),
            'cash_account_id' => ChartOfAccount::query()->where('code', '1100')->firstOrFail()->id,
            'payment_method' => PaymentMethod::BANK_TRANSFER,
            'total_amount' => 40000,
            'allocated_amount' => 0,
        ])->submit();
        app(PaymentAllocationService::class)->allocateBatch($receiptEntry, [
            ['accounts_receivable_id' => $ar->id, 'amount' => 40000],
        ]);

        CreditNote::query()->create([
            'invoice_id' => $ar->invoice_id,
            'customer_id' => $this->customer->id,
            'credit_note_date' => now()->toDateString(),
            'reason' => 'returned_goods',
            'status' => 'submitted',
            'subtotal' => 5000,
            'total_amount' => 5000,
        ]);

        $monthStart = now()->startOfMonth();
        $this->assertEquals(40000, $paymentAllocationRepository->collectionTotal($monthStart, now()));
        $this->assertEquals(100000, $invoiceRepository->salesTotal($monthStart, now()));
        $this->assertEquals(5000, $creditNoteRepository->creditNoteTotal($monthStart, now()));
    }
}
