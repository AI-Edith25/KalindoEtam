import { useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { terbilangUsdWithCents } from '@/shared/lib/numberToWords'
import { useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { fetchJournalEntriesByIds } from '../api/journalEntryApi'
import type { JournalEntry } from '../types'

/** GJ print's own en-US grouping (comma thousands, dot decimal), no currency symbol in the table — same reasoning as the SO/DO/SI classic-print family's own formatNum. */
function formatNum(value: number | string, decimals = 2): string {
  return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals }).format(Number(value))
}

/** posting_date arrives as a plain YYYY-MM-DD string — split directly rather than re-parsing through a Date object, which shifts the calendar date in any timezone ahead of UTC (same pitfall the SO/DO/SI print family already documents). */
function formatDdMmYyyy(dateStr: string | null | undefined): string {
  if (!dateStr) return ''
  const [year, month, day] = dateStr.split('-')
  return `${day}/${month}/${year}`
}

// Account Code / Account Name / Description / Prj-Dept / Debit / Credit — must match across thead/tbody/tfoot
// below since each is forced to its own independent `display: table` (see GeneralJournalPage's docblock),
// so column widths can no longer come from a single shared <colgroup>.
const COLUMN_WIDTHS = ['12%', '14%', '37%', '10%', '13.5%', '13.5%']

/**
 * One GJ, one A4 page — shared verbatim between the single-print and multi-select-print entry points
 * (single is just a list of one).
 *
 * TOTAL/terbilang/signature must sit at the bottom of the sheet regardless of how many lines are
 * above them, while TOTAL itself stays a real <tfoot> row of the same <table> as the lines (for
 * border/column consistency). Plain flex-grow on the page div can't reach inside the table, and
 * flex-growing the lines' own <tbody> stretches gaps *between* every row (verified against a plain
 * `<tbody style="flex:1">` — Chrome's table row-height algorithm spreads extra space across all rows,
 * not just after the last one). The fix: split the table into independently-`display:table` sections
 * stacked in a flex column (thead/tbody/tbody/tfoot), with a second, empty <tbody> as a dedicated
 * spacer between the lines and TOTAL — as the *only* row in its own row-stretch context, it alone
 * absorbs 100% of the leftover height instead of sharing it with the data rows.
 */
function GeneralJournalPage({ entry, companyName, isLast }: { entry: JournalEntry; companyName: string; isLast: boolean }) {
  return (
    <div
      className="mx-auto flex w-full max-w-[210mm] flex-col bg-background p-6 text-black print:max-w-none print:p-[12mm]"
      style={{ fontFamily: 'Arial, Helvetica, sans-serif', fontSize: '13px', pageBreakAfter: isLast ? 'auto' : 'always', height: '297mm' }}
    >
      <div className="grid grid-cols-3 items-start">
        <p className="text-[14pt] font-bold">{companyName}</p>
        <p className="text-center font-bold" style={{ letterSpacing: '0.3em' }}>
          GENERAL JOURNAL
        </p>
        <div className="text-right font-bold">
          <p>NO. : {entry.document_number ?? '—'}</p>
          <p>Date : {formatDdMmYyyy(entry.posting_date)}</p>
        </div>
      </div>

      <div className="mt-3 flex border border-black px-2 py-1">
        <span className="w-28 shrink-0">Description</span>
        <span className="shrink-0">:</span>
        <span className="pl-2">{entry.description ?? ''}</span>
      </div>

      <table className="mt-2 flex w-full flex-1 flex-col border-collapse">
        <thead style={{ display: 'table', width: '100%', tableLayout: 'fixed' }}>
          <tr className="border-y border-black">
            <th style={{ width: COLUMN_WIDTHS[0] }} className="py-1 px-2 text-center font-bold">Account Code</th>
            <th style={{ width: COLUMN_WIDTHS[1] }} className="py-1 px-2 text-center font-bold">Account Name</th>
            <th style={{ width: COLUMN_WIDTHS[2] }} className="py-1 px-2 text-center font-bold">Description</th>
            <th style={{ width: COLUMN_WIDTHS[3] }} className="py-1 px-2 text-center font-bold">Prj /Dept</th>
            <th style={{ width: COLUMN_WIDTHS[4] }} className="py-1 px-2 text-center font-bold">Debit</th>
            <th style={{ width: COLUMN_WIDTHS[5] }} className="py-1 px-2 text-center font-bold">Credit</th>
          </tr>
        </thead>
        <tbody style={{ display: 'table', width: '100%', tableLayout: 'fixed' }}>
          {entry.lines.map((line) => (
            <tr key={line.id}>
              <td style={{ width: COLUMN_WIDTHS[0] }} className="py-1 px-2 text-left align-top">{line.chart_of_account?.code ?? ''}</td>
              <td style={{ width: COLUMN_WIDTHS[1] }} className="py-1 px-2 text-left align-top">{line.chart_of_account?.name ?? ''}</td>
              <td style={{ width: COLUMN_WIDTHS[2] }} className="py-1 px-2 text-left align-top whitespace-pre-wrap">{line.description ?? ''}</td>
              <td style={{ width: COLUMN_WIDTHS[3] }} className="py-1 px-2 text-center align-top">{line.branch?.name || '/'}</td>
              <td style={{ width: COLUMN_WIDTHS[4] }} className="py-1 px-2 text-right align-top">{formatNum(line.debit)}</td>
              <td style={{ width: COLUMN_WIDTHS[5] }} className="py-1 px-2 text-right align-top">{formatNum(line.credit)}</td>
            </tr>
          ))}
        </tbody>
        <tbody aria-hidden style={{ display: 'table', width: '100%', flex: '1 1 auto' }}>
          <tr>
            <td colSpan={6} style={{ padding: 0, border: 0 }} />
          </tr>
        </tbody>
        <tfoot style={{ display: 'table', width: '100%', tableLayout: 'fixed' }}>
          <tr className="border-y border-black font-bold">
            <td colSpan={4} style={{ width: '73%' }} className="py-1 px-2 text-right">
              TOTAL
            </td>
            <td style={{ width: COLUMN_WIDTHS[4] }} className="py-1 px-2 text-right">{formatNum(entry.total_debit)}</td>
            <td style={{ width: COLUMN_WIDTHS[5] }} className="py-1 px-2 text-right">{formatNum(entry.total_credit)}</td>
          </tr>
        </tfoot>
      </table>

      <p className="mt-2 font-bold uppercase">RP : {terbilangUsdWithCents(entry.total_debit)}</p>

      <div className="mt-16 grid grid-cols-3 gap-8 text-center">
        <div className="border-t border-black pt-1">PREPARED BY</div>
        <div className="border-t border-black pt-1">APPROVED BY</div>
        <div className="border-t border-black pt-1">RECEIVED BY</div>
      </div>
    </div>
  )
}

/**
 * Reached from Journal Entry detail's "Print" button (?ids=<one-id>) or General Journal list's
 * checkbox "Print Selected" (?ids=uuid,uuid,...) — one shared route/page/render function for both,
 * per spec. Each entry renders as its own full A4 page via page-break-after (none on the last),
 * so a multi-select print never puts two GJs on one sheet.
 */
export function JournalEntryPrintPage() {
  const [searchParams] = useSearchParams()
  const ids = useMemo(() => searchParams.get('ids')?.split(',').filter(Boolean) ?? [], [searchParams])

  const brandingQuery = useCompanyBranding()
  const entriesQuery = useQuery({
    queryKey: ['journal-entries', 'print', ids],
    queryFn: () => fetchJournalEntriesByIds(ids),
    enabled: ids.length > 0,
  })

  const companyName = brandingQuery.data?.name ?? 'PT. KALINDO ETAM'
  const entries = entriesQuery.data ?? []

  if (entriesQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4 bg-background print:gap-0">
      {/* margin: 0 on @page suppresses the browser's own print header/footer chrome — document
          margins come from each page's own print:p-[12mm] instead. */}
      <style>{'@page { size: A4; margin: 0; }'}</style>

      <div className="flex items-start justify-between p-6 pb-0 print:hidden">
        <h1 className="text-xl font-semibold">General Journal Print Preview</h1>
        <Button onClick={() => window.print()} disabled={entries.length === 0}>
          <Printer className="size-4" />
          Print
        </Button>
      </div>

      {entries.length === 0 ? (
        <p className="p-6 text-muted-foreground print:hidden">No journal entries found for this selection.</p>
      ) : (
        entries.map((entry, index) => (
          <GeneralJournalPage key={entry.id} entry={entry} companyName={companyName} isLast={index === entries.length - 1} />
        ))
      )}
    </div>
  )
}
