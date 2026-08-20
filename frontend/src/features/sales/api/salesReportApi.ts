import { apiClient } from '@/shared/services/apiClient'

export interface SalesReportParams {
  search?: string
  status?: string
  date_from?: string
  date_to?: string
  /** Checked rows on the Invoices list — when present, overrides search/status/date_from/date_to entirely (see InvoiceRepository::searchAll()). */
  ids?: string[]
}

/** Sales → Invoices' "Laporan Penjualan" export — Summary or Detail, XLSX or CSV. Blob response (auth is a Bearer header, not a cookie, so a plain window.open link can't carry it) — same shape as exportAccountsReceivables(). */
export async function exportSalesReport(params: SalesReportParams, mode: 'summary' | 'detail', format: 'xlsx' | 'csv'): Promise<Blob> {
  const { data } = await apiClient.get('/invoices/export/sales-report', { params: { ...params, mode, format }, responseType: 'blob' })
  return data as Blob
}
