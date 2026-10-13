import { useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { fetchAccountsReceivablesAll } from '@/features/reports/api/accountsReceivableCustomerReportsApi'

/** Laporan_penagihan.pdf shows en-US grouping (comma thousands, dot decimal) with no currency symbol — same reasoning as Tanda Terima Invoice print's own formatNum. */
function formatNum(value: number | string, decimals: number): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** invoice_date/due_date arrive as plain YYYY-MM-DD strings — split directly, same reasoning as Tanda Terima Invoice print. */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

/** See this page's own doc comment — deliveries is the normal source, reference_1 only ever
    stands in for a historical-imported Invoice, which has no real Delivery to read deliveries
    from. reference_2 is deliberately ignored — this column only ever shows one reference. */
function resolveReference(invoice: { deliveries?: string[]; reference_1?: string | null } | null | undefined): string {
  const deliveries = invoice?.deliveries ?? []
  if (deliveries.length > 0) return deliveries.join(', ')
  return invoice?.reference_1 ?? ''
}

/**
 * Reached from Sales > Invoices' checkbox selection (?ids=uuid,uuid,...), replacing the old
 * /reports/penagihan-harian — same AccountsReceivable list-all endpoint (now filtered by
 * invoice_ids instead of due-date/branch/salesman), deliberately rendered as a flat list (no
 * per-customer grouping/subtotals like the old page —
 * CUSTOMER / CUSTOMER NAME repeat on every row instead), matching Laporan_penagihan.pdf's
 * 7-column layout. REFERENCE reads invoice.deliveries' document numbers (joined with ", " when
 * an invoice merges more than one Delivery/Sales Order), not invoice.reference_1 — a single
 * scalar column that only ever holds the anchor source's own reference (InvoiceService::create()),
 * so a merged invoice's later sources silently disappeared when this read reference_1 instead
 * (regressed by df284d4, only partially caught by 18d532c's own revert — see 83bfe00 for the
 * original fix and reasoning, same field/logic Sales > Invoices' own "Reference" column uses).
 * No # suffix on any column header (Reference 2 dropped entirely).
 *
 * A historical-imported Invoice (CustomerOutstandingArchiveImportService) has no real Delivery/
 * Sales Order at all — deliveries is always empty for it — so REFERENCE falls back to
 * reference_1 (reference_2 deliberately ignored — this column only ever shows one reference)
 * whenever deliveries is empty. reference_1 only ever gets populated for an imported Invoice via
 * the separate "Import Detail" backfill step (SalesInvoiceDetailBackfillService), so an invoice
 * this still shows blank for hasn't had that step run on it yet, or was skipped by it. Never a
 * double-fallback risk for a real invoice — one with at least one real Delivery always has a
 * non-empty deliveries array.
 *
 * OUTSTANDING AMOUNT reads invoice.grand_total (the Invoice's own billed amount), not
 * AccountsReceivable.outstanding_amount — a historical-imported Invoice's AR paid_amount is
 * seeded from the separate Customer Outstanding Bills snapshot and can mark it Paid even though
 * the field still needs to bill off the full invoiced amount (user-confirmed 2026-10-05: this
 * report always shows the SI's own amount, independent of the system's payment/aging status).
 */
export function LaporanPenagihanHarianPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = useMemo(() => searchParams.get('ids')?.split(',').filter(Boolean) ?? [], [searchParams])

  const listQuery = useQuery({
    queryKey: ['accounts-receivables', 'by-invoice-ids', ids],
    queryFn: () => fetchAccountsReceivablesAll({ invoice_ids: ids }),
    enabled: ids.length > 0,
  })

  const rows = listQuery.data ?? []
  const total = rows.reduce((sum, row) => sum + Number(row.invoice?.grand_total ?? 0), 0)
  const today = new Date().toISOString().slice(0, 10)

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-white p-6 text-foreground shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-0 print:shadow-none">
      <style>
        {`@page { size: A4; margin: 12mm; }
          /* A one-time padding on this div only insets the TOP of page 1 / BOTTOM of the last page —
             a table that breaks across pages gets no padding at the interior break, so continuation
             pages started flush against the paper edge. @page margin is the only thing that re-applies
             on every physical page, so it's the sole source of print spacing now (print:p-0 above).
             Still 12mm, so column widths below (sum 186mm = 210mm - 2x12mm) don't need to change. */
          @media print { thead { display: table-row-group; } }`}
      </style>

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Laporan Penagihan Harian Print Preview</h1>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      <div className="flex flex-col text-black text-[13px]" style={{ fontFamily: 'Arial, Helvetica, sans-serif' }}>
        <p className="text-center text-lg font-bold">LAPORAN PENAGIHAN HARIAN</p>
        <p className="text-right">Tanggal : {formatDdMmYyyy(today)}</p>
        <hr className="mt-2 border-black" />

        {/*
          Fixed column widths (table-layout: fixed) instead of browser auto-layout — auto-layout
          sized each column to whatever its WIDEST row happened to need, so columns jittered in
          width from page to page and made the whole table look uneven. Widths below are measured
          against real Arial-13px content (customer names, document/reference numbers from actual
          invoices, AND each bold header's own text — "CUSTOMER"/"DUE DATE" need more room than
          their data ever does, and a column too narrow for its own header's nowrap text spills
          visibly into the next column instead of wrapping) so a typical value fits on one line
          while a long one wraps predictably instead of forcing the row onto one line, stretching
          the column, or overflowing it. Sum = 186mm = A4 content width (210mm - 12mm print padding
          each side). CUSTOMER NAME/DOCUMENT/REFERENCE carry no spaces in the worst case (a document
          number), so they also need overflow-wrap: break-word or a long one would overflow its
          column instead of wrapping.
        */}
        <table className="mt-2 w-full border-collapse text-left" style={{ tableLayout: 'fixed' }}>
          <colgroup>
            <col style={{ width: '23mm' }} />
            <col style={{ width: '40mm' }} />
            <col style={{ width: '19mm' }} />
            <col style={{ width: '24mm' }} />
            <col style={{ width: '32mm' }} />
            <col style={{ width: '21mm' }} />
            <col style={{ width: '27mm' }} />
          </colgroup>
          <thead>
            <tr className="border-b border-black">
              <th className="whitespace-nowrap py-1 pr-2 font-bold">CUSTOMER</th>
              <th className="py-1 pr-2 font-bold">CUSTOMER NAME</th>
              <th className="whitespace-nowrap py-1 pr-2 font-bold">INV DATE</th>
              <th className="py-1 pr-2 font-bold">DOCUMENT</th>
              <th className="py-1 pr-2 font-bold">REFERENCE</th>
              <th className="whitespace-nowrap py-1 pr-2 font-bold">DUE DATE</th>
              <th className="py-1 text-right font-bold leading-tight">
                OUTSTANDING
                <br />
                AMOUNT
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td className="whitespace-nowrap py-1 pr-2 align-top">{row.customer?.customer_code ?? ''}</td>
                <td className="py-1 pr-2 align-top [overflow-wrap:break-word]">{row.customer?.customer_name ?? ''}</td>
                <td className="whitespace-nowrap py-1 pr-2 align-top">{formatDdMmYyyy(row.invoice?.invoice_date)}</td>
                <td className="py-1 pr-2 align-top [overflow-wrap:break-word]">{row.invoice?.document_number ?? ''}</td>
                <td className="py-1 pr-2 align-top [overflow-wrap:break-word]">{resolveReference(row.invoice)}</td>
                <td className="whitespace-nowrap py-1 pr-2 align-top">{formatDdMmYyyy(row.due_date)}</td>
                <td className="whitespace-nowrap py-1 text-right align-top">{formatNum(row.invoice?.grand_total ?? 0, 2)}</td>
              </tr>
            ))}
            {/* A <tfoot> row repeats on every printed page (browser default for table-footer-group) —
                TOTAL must only ever print once, at the very end, so it's a normal last <tbody> row. */}
            <tr className="border-t border-black font-bold">
              <td colSpan={6} className="py-1 pr-2 text-right">
                TOTAL
              </td>
              <td className="py-1 text-right">{formatNum(total, 2)}</td>
            </tr>
          </tbody>
        </table>

        <p className="mt-8 text-center text-xs font-medium">PEMBAYARAN DENGAN CHEQUE / GIRO DIANGGAP LUNAS SETELAH DICAIRKAN</p>

        <div className="mt-10 grid grid-cols-2 gap-8 text-center">
          <p>Dibuat Oleh,</p>
          <p>Diterima Oleh,</p>
        </div>
        <div className="mt-16 grid grid-cols-2 gap-8 text-center">
          <p>( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</p>
          <p>( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</p>
        </div>
      </div>
    </div>
  )
}
