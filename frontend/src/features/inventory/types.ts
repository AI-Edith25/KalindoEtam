export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

export type MovementType = 'in' | 'out' | 'adjustment'

export type VoucherType = 'stock_in' | 'goods_receipt' | 'delivery' | 'stock_adjustment'

export interface StockLedgerEntry {
  id: string
  item_id: string
  item: { id: string; item_code: string; item_name: string } | null
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  transaction_type: MovementType
  voucher_type: VoucherType
  voucher_id: string
  reference_no: string | null
  qty_change: number
  balance_qty: number
  posting_datetime: string
  remarks: string | null
}

export interface StockLedgerFilterValues {
  warehouse_id: string
  item_id: string
  voucher_type: VoucherType | null
  dateFrom: string
  dateTo: string
}

export interface StockBalanceRow {
  item_id: string
  item_code: string
  item_name: string
  warehouse_id: string
  warehouse_name: string
  uom: string
  current_qty: number
  reserved_qty: number | null
  available_qty: number
  reorder_level: number | null
}

export interface StockBalanceFilterValues {
  warehouse_id: string
  item_group_id: string
  item_id: string
}

export interface StockAdjustmentItem {
  id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  system_qty: number
  counted_qty: number
  difference_qty: number
  reason: string
}

export interface StockAdjustment {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  adjustment_date: string
  remarks: string | null
  items: StockAdjustmentItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface StockAdjustmentFormValues {
  warehouse_id: string
  adjustment_date: string
  remarks: string | null
  items: { item_id: string; counted_qty: number; reason: string }[]
}

export interface StockAdjustmentFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}
