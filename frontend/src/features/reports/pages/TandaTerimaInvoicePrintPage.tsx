import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, loadPaperTypePreference, PRINT_FONT_SIZE_PX, PRINT_PAPER_PAGE_CSS, savePaperTypePreference, type PrintOptions } from '@/shared/lib/printOptions'
import { useBrandingLogoObjectUrl, useCompanyBranding, useCompanyPrintHeader } from '@/features/administration/hooks/useCompany'
import { fetchAccountsReceivablesAll } from '../api/accountsReceivableCustomerReportsApi'

/** F1 (UAT review 2026-08-12) — printable "Tanda Terima Invoice" handed to a store: company header + logo, To. <STORE>, invoice table, TOTAL, cheque/giro note, Dibuat Oleh / Diterima Oleh. */
export function TandaTerimaInvoicePrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(() => ({ ...defaultPrintOptions, paperType: loadPaperTypePreference() }))
  const [optionsOpen, setOptionsOpen] = useState(false)

  const customerId = searchParams.get('customer_id') ?? undefined
  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined

  const brandingQuery = useCompanyBranding()
  const printHeaderQuery = useCompanyPrintHeader()
  const logoObjectUrl = useBrandingLogoObjectUrl(brandingQuery.data?.logo_url)

  const listQuery = useQuery({
    queryKey: ['tanda-terima-invoice-print', customerId, dateFrom, dateTo],
    queryFn: () =>
      fetchAccountsReceivablesAll({
        ...(customerId ? { customer_id: customerId } : {}),
        ...(dateFrom ? { invoice_date_from: dateFrom } : {}),
        ...(dateTo ? { invoice_date_to: dateTo } : {}),
      }),
    enabled: !!customerId,
  })

  const rows = listQuery.data ?? []
  const total = rows.reduce((sum, row) => sum + Number(row.outstanding_amount), 0)
  const customerName = rows[0]?.customer?.customer_name ?? '—'
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
        <h1 className="text-xl font-semibold">Tanda Terima Invoice Print Preview</h1>
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
            <p>{formatDate(new Date().toISOString())}</p>
          </div>
        </div>

        <div className={`border-b-2 border-foreground/80 ${compact ? 'p-2' : 'p-3'}`}>
          <p>To. {customerName.toUpperCase()}</p>
          <h2 className={compact ? 'text-base font-bold' : 'text-lg font-bold'}>TANDA TERIMA INVOICE</h2>
        </div>

        <table className="w-full border-collapse">
          <thead>
            <tr className="border-b-2 border-foreground/80 text-left">
              <th className="border-r-2 border-foreground/80 p-2">Inv Date</th>
              <th className="border-r-2 border-foreground/80 p-2">Document #</th>
              <th className="border-r-2 border-foreground/80 p-2">Reference 1 #</th>
              <th className="border-r-2 border-foreground/80 p-2">Reference 2 #</th>
              <th className="border-r-2 border-foreground/80 p-2">Due Date</th>
              <th className="p-2 text-right">Amount</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} className="border-b border-foreground/30">
                <td className="border-r-2 border-foreground/80 p-2">{row.invoice?.invoice_date ? formatDate(row.invoice.invoice_date) : '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.invoice?.document_number ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.invoice?.reference_1 ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{row.invoice?.reference_2 ?? '—'}</td>
                <td className="border-r-2 border-foreground/80 p-2">{formatDate(row.due_date)}</td>
                <td className="p-2 text-right">{formatMoney(row.outstanding_amount, printOptions.amountDecimals)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="border-t-2 border-foreground/80 font-semibold">
              <td colSpan={5} className="border-r-2 border-foreground/80 p-2 text-right">
                TOTAL
              </td>
              <td className="p-2 text-right">{formatMoney(total, printOptions.amountDecimals)}</td>
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
