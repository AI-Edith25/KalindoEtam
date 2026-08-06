import type { DocumentStatus as PurchaseDocumentStatus } from '@/features/purchase/types'
import type { DocumentStatus as SalesDocumentStatus } from '@/features/sales/types'
import type { SettlementStatus } from '@/features/payment/types'

/**
 * Reports is read-only and consumes Purchase/Sales/Inventory data directly
 * (their types + api functions), rather than duplicating entity shapes a
 * third time. Inventory Movement and Inventory Balance reports reuse
 * StockLedgerFilterValues / StockBalanceFilterValues and their existing
 * FiltersBar components as-is — only the 4 document reports below need new
 * filter shapes, since none of the 4 existing document FiltersBars expose
 * a supplier/customer dropdown today.
 */

export interface PurchaseReportFilterValues {
  supplier_id: string
  status: PurchaseDocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface GoodsReceiptReportFilterValues {
  warehouse_id: string
  dateFrom: string
  dateTo: string
}

export interface SalesReportFilterValues {
  customer_id: string
  item_id: string
  status: SalesDocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface DeliveryReportFilterValues {
  customer_id: string
  item_id: string
  warehouse_id: string
  dateFrom: string
  dateTo: string
}

export interface ArDetailReportFilterValues {
  customer_id: string
  status: SettlementStatus | null
  dateFrom: string
  dateTo: string
}

export interface ArAgingReportFilterValues {
  customer_id: string
  asOfDate: string
}
