import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Printer, Settings2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { PrintOptionsDialog } from '@/components/shared/PrintOptionsDialog'
import { formatDate } from '@/lib/utils'
import { defaultPrintOptions, formatMoney, formatQty, PRINT_FONT_SIZE_PX, type PrintOptions } from '@/shared/lib/printOptions'
import { fetchProductSales } from '../api/productSalesApi'
import { fetchCustomerSales } from '../api/customerSalesApi'

/**
 * Read-only print view of the active Sales Report tab's current filters. Product Sales + Customer
 * Sales are built — Open Orders/Sales Listing print views land alongside their own tabs.
 */
export function SalesReportPrintPage() {
  const [searchParams] = useSearchParams()
  const [printOptions, setPrintOptions] = useState<PrintOptions>(defaultPrintOptions)
  const [optionsOpen, setOptionsOpen] = useState(false)

  const tab = searchParams.get('tab') === 'customers' ? 'customers' : 'products'
  const dateFrom = searchParams.get('date_from') ?? undefined
  const dateTo = searchParams.get('date_to') ?? undefined
  const params = {
    customer_id: searchParams.get('customer_id') ?? undefined,
    item_id: searchParams.get('item_id') ?? undefined,
    item_group_id: searchParams.get('item_group_id') ?? undefined,
    sales_person_id: searchParams.get('sales_person_id') ?? undefined,
    branch_id: searchParams.get('branch_id') ?? undefined,
    status: searchParams.get('status') ?? undefined,
    date_from: dateFrom,
    date_to: dateTo,
  }

  const productsQuery = useQuery({
    queryKey: ['product-sales-print', params],
    queryFn: () => fetchProductSales({ page: 1, per_page: 500, ...params }),
    enabled: tab === 'products',
  })

  const customersQuery = useQuery({
    queryKey: ['customer-sales-print', params],
    queryFn: () => fetchCustomerSales({ page: 1, per_page: 500, ...params }),
    enabled: tab === 'customers',
  })

  const total = tab === 'products' ? (productsQuery.data?.meta.total ?? 0) : (customersQuery.data?.meta.total ?? 0)

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-4 bg-background p-6 text-foreground print:max-w-none print:p-0">
      <div className="flex items-start justify-between print:hidden">
        <h1 className="text-xl font-semibold">{tab === 'products' ? 'Product' : 'Customer'} Sales Print Preview</h1>
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

      {total > 500 && (
        <p className="rounded border border-amber-500/50 bg-amber-500/10 p-2 text-sm print:hidden">
          Showing 500 of {total} rows — narrow the filters to print everything.
        </p>
      )}

      <div className="border-2 border-foreground/80" style={{ fontSize: PRINT_FONT_SIZE_PX[printOptions.fontSize] }}>
        <div className="border-b-2 border-foreground/80 p-3">
          <h2 className="text-lg font-bold">{tab === 'products' ? 'PRODUCT SALES REPORT' : 'CUSTOMER SALES REPORT'}</h2>
          {(dateFrom || dateTo) && (
            <p>
              {dateFrom ? formatDate(dateFrom) : '—'} to {dateTo ? formatDate(dateTo) : '—'}
            </p>
          )}
          {tab === 'products' && productsQuery.data?.meta.kpis && (
            <p>
              Total Qty: {formatQty(productsQuery.data.meta.kpis.total_qty, printOptions.qtyDecimals)} — Total Revenue:{' '}
              {formatMoney(productsQuery.data.meta.kpis.total_revenue, printOptions.amountDecimals)}
            </p>
          )}
          {tab === 'customers' && customersQuery.data?.meta.kpis && (
            <p>
              Active Customers: {customersQuery.data.meta.kpis.total_customers} — Total Revenue:{' '}
              {formatMoney(customersQuery.data.meta.kpis.total_revenue, printOptions.amountDecimals)}
            </p>
          )}
        </div>

        {tab === 'products' ? (
          <table className="w-full border-collapse">
            <thead>
              <tr className="border-b-2 border-foreground/80 text-left">
                <th className="border-r-2 border-foreground/80 p-2">Item Code</th>
                <th className="border-r-2 border-foreground/80 p-2">Description</th>
                <th className="border-r-2 border-foreground/80 p-2">Item Group</th>
                <th className="border-r-2 border-foreground/80 p-2 text-right">Qty</th>
                <th className="border-r-2 border-foreground/80 p-2 text-right">Amount Excl. Tax</th>
                <th className="p-2 text-right">Amount Incl. Tax</th>
              </tr>
            </thead>
            <tbody>
              {(productsQuery.data?.data ?? []).map((row) => (
                <tr key={row.id} className="border-b border-foreground/30">
                  <td className="border-r-2 border-foreground/80 p-2">{row.item_code ?? '—'}</td>
                  <td className="border-r-2 border-foreground/80 p-2">{row.item_name}</td>
                  <td className="border-r-2 border-foreground/80 p-2">{row.item_group_name ?? 'Unassigned'}</td>
                  <td className="border-r-2 border-foreground/80 p-2 text-right">{formatQty(row.qty, printOptions.qtyDecimals)}</td>
                  <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.amount, printOptions.amountDecimals)}</td>
                  <td className="p-2 text-right">{formatMoney(row.amount_incl_tax, printOptions.amountDecimals)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <table className="w-full border-collapse">
            <thead>
              <tr className="border-b-2 border-foreground/80 text-left">
                <th className="border-r-2 border-foreground/80 p-2">Customer Code</th>
                <th className="border-r-2 border-foreground/80 p-2">Customer Name</th>
                <th className="border-r-2 border-foreground/80 p-2 text-right"># Trx</th>
                <th className="border-r-2 border-foreground/80 p-2 text-right">Qty</th>
                <th className="border-r-2 border-foreground/80 p-2 text-right">Amount Excl. Tax</th>
                <th className="p-2 text-right">Amount Incl. Tax</th>
              </tr>
            </thead>
            <tbody>
              {(customersQuery.data?.data ?? []).map((row) => (
                <tr key={row.id} className="border-b border-foreground/30">
                  <td className="border-r-2 border-foreground/80 p-2">{row.customer_code}</td>
                  <td className="border-r-2 border-foreground/80 p-2">{row.customer_name}</td>
                  <td className="border-r-2 border-foreground/80 p-2 text-right">{row.transaction_count}</td>
                  <td className="border-r-2 border-foreground/80 p-2 text-right">{formatQty(row.qty, printOptions.qtyDecimals)}</td>
                  <td className="border-r-2 border-foreground/80 p-2 text-right">{formatMoney(row.amount, printOptions.amountDecimals)}</td>
                  <td className="p-2 text-right">{formatMoney(row.amount_incl_tax, printOptions.amountDecimals)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <PrintOptionsDialog open={optionsOpen} onOpenChange={setOptionsOpen} options={printOptions} onChange={setPrintOptions} />
    </div>
  )
}
