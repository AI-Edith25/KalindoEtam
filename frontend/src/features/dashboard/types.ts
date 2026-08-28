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
  current_stock: string | number
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

export interface SalesAchievementRow {
  sales_person_id: string
  sales_person_name: string
  /** null = no target set for this sales person/period. */
  target_amount: number | null
  achieved_amount: number
  /** null when target_amount is null — "no target" is not the same as "0 shortfall". */
  shortfall_amount: number | null
  /** null when target_amount is null or 0 — percent-of-target is undefined either way. */
  achievement_percent: number | null
}

export interface SalesAchievement {
  period: { month: number; year: number }
  rows: SalesAchievementRow[]
  /** Revenue from documents with no sales_person_id at all — shown separately, never folded into any row's target/percent math. */
  unassigned: { achieved_amount: number } | null
}
