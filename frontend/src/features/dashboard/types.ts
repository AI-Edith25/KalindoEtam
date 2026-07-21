export interface StockSummary {
  total_items: number
  total_stock_qty: number
  zero_stock_items: number
}

export interface OutstandingSummary {
  total_outstanding: number
  count: number
}

export interface LowStockItem {
  id: string
  item_code: string
  item_name: string
  item_group: { id: string; name: string } | null
  uom: { id: string; name: string; symbol: string | null } | null
  current_stock: number
}

export interface RecentTransaction {
  type: string
  document_number: string | null
  date: string | null
  amount: number
  status: string
  created_at: string
}

export interface FinancialSummary {
  revenue_total: number
  expense_total: number
  net_profit: number
}

export interface PendingTask {
  module: string
  label: string
  count: number
}

export interface TrendPoint {
  date: string
  total: number
  count: number
}

export interface InventoryMovementPoint {
  date: string
  stock_in: number
  stock_out: number
}
