<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SalesPerson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * One-time backfill: invoices.sales_person_id is null for virtually every historical invoice
 * (2157/2158 customers have no Sales Person anywhere in this schema — AR.salesOrder,
 * invoice.salesPerson, customer.salesPerson all empty) because the Customer Outstanding Bills
 * import that created most of them never carried a Sales Person column at all. The legacy
 * system's own "Customers Detail Aging - By Sales Person, Customer" export is the only surviving
 * source of this assignment, and it carries it per INVOICE (document number), not per customer —
 * confirmed against the real file: the same customer can appear under more than one Sales Person
 * group when their different invoices were handled by different salesmen, with zero document
 * number ever mapping to two different names.
 *
 * Parses "Sales Person : <code> - [<name>]" group headers and "Customer : <code> - <name>, Sales
 * Person : <name>" lines to track the currently-active Sales Person, then reads every data row's
 * column A (a document number) under it. Only ever sets invoices.sales_person_id where it is
 * currently null — never overwrites an existing assignment.
 */
class BackfillInvoiceSalesPersonFromAgingFileCommand extends Command
{
    protected $signature = 'invoices:backfill-sales-person-from-aging-file {path} {--dry-run : Compute and report without saving any changes}';

    protected $description = 'Backfill invoices.sales_person_id from the legacy "Customers Detail Aging - By Sales Person, Customer" export.';

    public function handle(): int
    {
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $documentToSalesPersonName = $this->parse($path);
        $this->info('Document numbers found in file: ' . count($documentToSalesPersonName));

        $salesPersonIdsByName = SalesPerson::query()->pluck('id', 'name')->all();
        $invoicesByDocumentNumber = Invoice::query()
            ->whereIn('document_number', array_keys($documentToSalesPersonName))
            ->get(['id', 'document_number', 'sales_person_id'])
            ->keyBy('document_number');

        $updated = 0;
        $alreadySet = 0;
        $notFoundInDb = 0;
        $unknownSalesPersonNames = [];

        DB::beginTransaction();

        foreach ($documentToSalesPersonName as $documentNumber => $salesPersonName) {
            $salesPersonId = $salesPersonIdsByName[$salesPersonName] ?? null;
            if ($salesPersonId === null) {
                $unknownSalesPersonNames[$salesPersonName] = true;

                continue;
            }

            $invoice = $invoicesByDocumentNumber->get($documentNumber);
            if ($invoice === null) {
                $notFoundInDb++;

                continue;
            }

            if ($invoice->sales_person_id !== null) {
                $alreadySet++;

                continue;
            }

            $invoice->update(['sales_person_id' => $salesPersonId]);
            $updated++;
        }

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('--dry-run: no changes were saved.');
        } else {
            DB::commit();
        }

        $this->info("Invoices updated: {$updated}");
        $this->info("Already had a Sales Person (skipped): {$alreadySet}");
        $this->info("Document number not found in this system: {$notFoundInDb}");
        if ($unknownSalesPersonNames !== []) {
            $this->warn('Sales Person names in file with no matching SalesPerson row: ' . implode(', ', array_keys($unknownSalesPersonNames)));
        }

        return self::SUCCESS;
    }

    /** @return array<string, string> document_number => sales_person_name */
    private function parse(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $highestRow = $sheet->getHighestRow();

        $currentSalesPerson = null;
        $map = [];

        for ($row = 1; $row <= $highestRow; $row++) {
            $colA = $sheet->getCellByColumnAndRow(1, $row)->getValue();
            if (! is_string($colA)) {
                continue;
            }

            if (str_starts_with($colA, 'Sales Person :')) {
                if (preg_match('/^Sales Person : \S+ - \[(.+)\]$/', $colA, $m)) {
                    $currentSalesPerson = trim($m[1]);
                }

                continue;
            }

            if (str_starts_with($colA, 'Customer :')) {
                // The customer line's own trailing "Sales Person : <name>" is authoritative for
                // every document row beneath it, overriding the group header it happens to sit in.
                if (preg_match('/^Customer : \S+ - .+?, Sales Person : (.*)$/', $colA, $m)) {
                    $currentSalesPerson = trim($m[1]);
                }

                continue;
            }

            // A document data row: col A is a document number, col B a dd/mm/yyyy date. Subtotal
            // rows (col A null) and every other preamble/header row are skipped by this same check.
            $colB = $sheet->getCellByColumnAndRow(2, $row)->getValue();
            if ($currentSalesPerson !== null && is_string($colB) && preg_match('#^\d{2}/\d{2}/\d{4}$#', $colB)) {
                $map[$colA] = $currentSalesPerson;
            }
        }

        return $map;
    }
}
