import { useMemo } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Printer } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { terbilangUsdWithCents } from '@/shared/lib/numberToWords'
import { useCompanyBranding } from '@/features/administration/hooks/useCompany'
import { legacyCompanyName } from '@/features/sales/pages/invoicePrintConstants'
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

// Account Code / Account Name / Description / Prj-Dept / Debit / Credit — measured directly off the
// legacy template's own vector grid lines (generaljournal_jtpq2k2v5rr81rnq5dgrd65gkr.pdf, extracted
// via PyMuPDF: column edges at x=28.7/108.8/189.5/351.1/426.5/496.5/566.6pt on a 537.9pt-wide A4 body
// → 80.1/80.0/160.8/74.6/69.2/69.3pt ≈ 15/15/30/14/13/13%), not eyeballed. Shared by both the lines
// table and the standalone TOTAL table below it via <colgroup>, so the two stay column-aligned
// without needing a single <table> to span both (see GeneralJournalPage's docblock).
const COLUMN_WIDTHS = ['15%', '15%', '30%', '14%', '13%', '13%']

function JournalColGroup() {
  return (
    <colgroup>
      {COLUMN_WIDTHS.map((width, i) => (
        <col key={i} style={{ width }} />
      ))}
    </colgroup>
  )
}

/**
 * One GJ, one A4 page — shared verbatim between the single-print and multi-select-print entry points
 * (single is just a list of one).
 *
 * The lines table is a genuine `<table>` (table-fixed + <colgroup>) so Account Code/Prj-Dept headers
 * never wrap under print width — no flex on <table> this time, that's what broke column alignment at
 * actual A4 width before. TOTAL can't be a <tfoot> of that same table and still sit pinned to the
 * bottom of the page when there are only 1-2 lines: a table's own row-stretch algorithm spreads any
 * extra height across every row, not just after the last one (verified earlier). Instead TOTAL is its
 * own small `<table>` with the identical <colgroup>, and a plain `<div style={{flex:1}}>` spacer
 * between the two tables absorbs 100% of the leftover vertical space — same visual result (TOTAL +
 * terbilang + signatures pushed to the bottom), without ever needing non-table display on a <table>.
 */
function GeneralJournalPage({ entry, companyName, isLast }: { entry: JournalEntry; companyName: string; isLast: boolean }) {
  return (
    <div
      className="mx-auto flex w-full max-w-[210mm] flex-col bg-white p-6 text-black shadow-[0_2px_8px_rgba(0,0,0,0.15)] print:max-w-none print:p-[12mm] print:shadow-none"
      style={{ fontFamily: 'Arial, Helvetica, sans-serif', pageBreakAfter: isLast ? 'auto' : 'always', height: '297mm' }}
    >
      <div className="grid grid-cols-3 items-start">
        <p className="text-[13.5pt] font-bold">{companyName}</p>
        <p className="whitespace-nowrap text-center font-bold text-[13pt]" style={{ letterSpacing: '0.1em' }}>
          GENERAL JOURNAL
        </p>
        <div className="text-right font-bold text-[10.5pt]">
          <p>NO. : {entry.document_number ?? '—'}</p>
          <p>Date : {formatDdMmYyyy(entry.posting_date)}</p>
        </div>
      </div>

      <div className="mt-4 flex border border-black px-2 py-1.5 text-[10.5pt]">
        <span className="w-28 shrink-0">Description</span>
        <span className="shrink-0">:</span>
        <span className="pl-2">{entry.description ?? ''}</span>
      </div>

      <table className="mt-3 w-full table-fixed border-collapse">
        <JournalColGroup />
        <thead>
          <tr className="text-[10.5pt]">
            <th className="whitespace-nowrap border border-black py-1.5 px-2.5 text-center font-bold">Account Code</th>
            <th className="whitespace-nowrap border border-black py-1.5 px-2.5 text-center font-bold">Account Name</th>
            <th className="whitespace-nowrap border border-black py-1.5 px-2.5 text-center font-bold">Description</th>
            <th className="whitespace-nowrap border border-black py-1.5 px-2.5 text-center font-bold">Prj /Dept</th>
            <th className="whitespace-nowrap border border-black py-1.5 px-2.5 text-center font-bold">Debit</th>
            <th className="whitespace-nowrap border border-black py-1.5 px-2.5 text-center font-bold">Credit</th>
          </tr>
        </thead>
        <tbody className="text-[9pt]">
          {entry.lines.map((line) => (
            <tr key={line.id}>
              {/* No vertical rules anywhere in the data rows — column separation is padding/
                  alignment only, per spec. */}
              <td className="py-1.5 px-2 text-left align-top">{line.chart_of_account?.code ?? ''}</td>
              <td className="py-1.5 px-2 text-left align-top">{line.chart_of_account?.name ?? ''}</td>
              <td className="py-1.5 px-2 text-left align-top whitespace-pre-wrap">{line.description ?? ''}</td>
              <td className="py-1.5 px-2 text-center align-top">{line.branch?.name || '/'}</td>
              <td className="py-1.5 px-2 text-right align-top">{formatNum(line.debit)}</td>
              <td className="py-1.5 px-2 text-right align-top">{formatNum(line.credit)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {/* Absorbs all leftover page height so TOTAL + terbilang + signatures land at the bottom of
          the sheet regardless of how many lines are above, instead of hugging the last data row. */}
      <div style={{ flex: '1 1 auto' }} />

      <table className="w-full table-fixed border-collapse">
        <JournalColGroup />
        <tbody>
          {/* TOTAL label and both totals each get a full 1px box — the template draws top+bottom+
              right on every cell here (confirmed from its vector lines), not just a top rule; no
              box around the data rows above, where the only vertical rule is Account Code|Account
              Name. */}
          <tr className="font-bold text-[9pt]">
            <td colSpan={4} className="border border-black py-1.5 px-2 text-right">
              TOTAL
            </td>
            <td className="border border-black py-1.5 px-2 text-right">{formatNum(entry.total_debit)}</td>
            <td className="border border-black py-1.5 px-2 text-right">{formatNum(entry.total_credit)}</td>
          </tr>
        </tbody>
      </table>

      <p className="mt-3 text-[10.5pt] font-bold uppercase">RP : {terbilangUsdWithCents(entry.total_debit)}</p>

      {/* Template's 3 signature lines are each ~20% of the content width with ~20% gaps between them
          (measured off the template's own line coordinates) — a plain 3-col grid with a small fixed
          gap packs them much closer together than that, so this uses justify-between on 20%-wide
          blocks instead, which reproduces the even column/gap/column/gap/column split exactly. */}
      <div className="mt-20 flex justify-between text-center text-[9pt]">
        <div className="w-1/5 border-t border-black pt-1">PREPARED BY</div>
        <div className="w-1/5 border-t border-black pt-1">APPROVED BY</div>
        <div className="w-1/5 border-t border-black pt-1">RECEIVED BY</div>
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

  // The stored name is plain title case ("PT Kalindo Etam") — the legacy GJ template shows it
  // "PT. KALINDO ETAM" (all caps, period after "PT"), same transform SO/DO/SI print already share.
  const companyName = legacyCompanyName(brandingQuery.data?.name ?? 'PT Kalindo Etam')
  const entries = entriesQuery.data ?? []

  if (entriesQuery.isLoading) {
    return (
      <div className="flex min-h-64 items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4 print:gap-0">
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
