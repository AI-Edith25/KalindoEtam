import { Fragment, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, loadPaperTypePreference, PRINT_FONT_SIZE_PX, PRINT_PAPER_PAGE_CSS, savePaperTypePreference, type PrintOptions } from '@/shared/lib/printOptions'
import { useBrandingLogoObjectUrl, useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchAccountsReceivablesGroupedByCustomer } from '../api/accountsReceivableCustomerReportsApi'

/** F2 (UAT review 2026-08-12) — printable "Laporan Penagihan Harian": company header + logo, Tanggal, grouped-by-customer table, TOTAL, cheque/giro note, Dibuat Oleh / Diterima Oleh. */
export function LaporanPenagihanHarianPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({ ...defaultPrintOptions, paperType: loadPaperTypePreference() }))
  const [optionsOpen, setOptionsOpen] = useState(false)

  const dateTo = searchParams.get('date_to') ?? undefined
  const branchId = searchParams.get('branch_id') ?? undefined
  const salesPersonId = searchParams.get('sales_person_id') ?? undefined

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()
  const logoObjectUrl = useBrandingLogoObjectUrl(brandingQuery.data?.logo_url)

  const groupedQuery = useQuery({
    queryKey: ['laporan-penagihan-harian-print', dateTo, branchId, salesPersonId],
    queryFn: () =>
      fetchAccountsReceivablesGroupedByCustomer({
        ...(dateTo ? { date_to: dateTo } : {}),
        ...(branchId ? { branch_id: branchId } : {}),
        ...(salesPersonId ? { sales_person_id: salesPersonId } : {}),
      }),
  })

  const report = groupedQuery.data
  const compact = printOptions.paperType === 'continuous'
  const pageCss = PRINT_PAPER_PAGE_CSS[printOptions.paperType]

  const handlePrintOptionsChange = (next: PrintOptions) => {
    setPrintOptions(next)
    savePaperTypePreference(next.paperType)
  }

  return (
    <div className={`mx-auto flex flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0 ${compact ? 'max-w-[9.5in]' : 'max-w-4xl'}`}>
      {pageCss && <style>{pageCss}</style>}

      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">Laporan Penagihan Harian Print Preview</h1>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={() => setOptionsOpen(true)}>
            <Settings2 className="size-4" />
            Print Options
          </Button>
          <Button onClick={() => window.print()}>
            <Printer className="size-4" />
            Print
          </Button>
        </div>
      </div>

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className={`flex items-start justify-between border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <div className="flex items-start gap-3">
            {logoObjectUrl && <img src={logoObjectUrl} alt="Logo" className="size-12 object-contain" />}
            <div>
              <p className="font-semibold">{brandingQuery.data?.name ?? 'PT. KALINDO ETAM'}</p>
              {printHeaderQuery.data?.address && <p className="text-xs">{printHeaderQuery.data.address}</p>}
              {(printHeaderQuery.data?.phone || printHeaderQuery.data?.email) && (
                <p className="text-xs">{[printHeaderQuery.data?.phone, printHeaderQuery.data?.email].filter(Boolean).join(' · ')}</p>
              )}
            </div>
          </div>
          <div className="text-right">
            <p>Tanggal : {formatDate(new Date().toISOString())}</p>
          </div>
        </div>

        <div className={`border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <h2 className={compact ? 'text-base font-bold' : 'text-lg font-bold'}>LAPORAN PENAGIHAN HARIAN</h2>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Inv Date</th>
              <th className="border-r-2 border-foreground/80 p-2">Document #</th>
              <th className="border-r-2 border-foreground/80 p-2">Reference 1 #</th>
              <th className="border-r-2 border-foreground/80 p-2">Reference 2 #</th>
              <th className="border-r-2 border-foreground/80 p-2">Due Date</th>
              <th className="p-2 text-right">Outstanding Amount</th>
            </tr>
          </thead>
          <tbody>
            {report?.groups.map((group) => (
              <Fragment key={group.customer_name}>
                <tr className="border-b border-foreground/30 bg-muted/30">
                  <td colSpan={6} className="p-2 font-semibold">
                    {group.customer_code ? `${group.customer_code} — ` : ''}
                    {group.customer_name}
                  </td>
                </tr>
                {group.rows.map((row, index) => (
                  <tr key={index} className="border-b border-foreground/30">
                    <td className="border-r-2 border-foreground/80 p-2">{row.date ? formatDate(row.date) : '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.document_no ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.reference_1 ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.reference_2 ?? '—'}</td>
                    <td className="border-r-2 border-foreground/80 p-2">{row.due_date ? formatDate(row.due_date) : '—'}</td>
                    <td className="p-2 text-right">{formatMoney(row.outstanding_amount, printOptions.amountDecimals)}</td>
                  </tr>
                ))}
              </Fragment>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-foreground/80 font-semibold">
              <td colSpan={5} className="border-r-2 border-foreground/80 p-2 text-right">
                TOTAL
              </td>
              <td className="p-2 text-right">{formatMoney(report?.grand_total ?? 0, printOptions.amountDecimals)}</td>
            </tr>
          </tfoot>
        </table>

        <div className={`border-t-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <p className="text-xs font-medium">PEMBAYARAN DENGAN CHEQUE / GIRO DIANGGAP LUNAS SETELAH DICAIRKAN</p>
        </div>

        <div className={`grid grid-cols-2 gap-8 border-t-2 border-foreground/80 ${compact ? 'gap-4 p-2 pt-6' : 'p-3 pt-10'}`}>
          <div className="text-center">
            <div className="mt-10 border-t border-foreground/80 pt-1">Dibuat Oleh</div>
          </div>
          <div className="text-center">
            <div className="mt-10 border-t border-foreground/80 pt-1">Diterima Oleh</div>
          </div>
        </div>
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={handlePrintOptionsChange} showPaperType />
    </div>
  )
}
