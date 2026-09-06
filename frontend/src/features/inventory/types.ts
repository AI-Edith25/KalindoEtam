export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

export type MovementType = 'in' | 'out' | 'adjustment'

export type VoucherType =
  | 'stock_in'
  | 'goods_receipt'
  | 'delivery'
  | 'stock_adjustment'
  | 'stock_transfer'
  | 'purchase_return'
  | 'credit_note'
  | 'opening_stock'
  | 'issue_stock'
  | 'receipt_stock'

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
  qty_change: string | number
  balance_qty: string | number
  posting_datetime: string
  remarks: string | null
  unit_cost: number | null
  value_in: number | null
  value_out: number | null
  balance_value: number | null
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
  total_value: number
  avg_cost: number
}

export interface StockBalanceSummary {
  total_value: number
  item_count: number
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
  system_qty: string | number
  counted_qty: string | number
  difference_qty: string | number
  qty_category: 'unit' | 'weight'
  unit_cost: string | number | null
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
  items: { item_id: string; counted_qty: number; unit_cost: number | null; reason: string }[]
}

export interface StockAdjustmentFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface OpeningStockItem {
  id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty_category: 'unit' | 'weight'
  qty: string | number
  unit_cost: string | number
  amount: string | number
}

export interface OpeningStock {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  cutoff_date: string
  remarks: string | null
  import_batch_id: string | null
  import_batch_filename: string | null
  items: OpeningStockItem[]
  line_count: number | null
  total_value: number | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface OpeningStockFormValues {
  warehouse_id: string
  cutoff_date: string
  remarks: string | null
  items: { item_id: string; qty: number; unit_cost: number }[]
}

export interface OpeningStockFilterValues {
  warehouse_id: string
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
  importBatchId: string
}

export interface IssueStockItem {
  id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty_category: 'unit' | 'weight'
  qty: string | number
  // Null while Draft — filled from FIFO consumption's weighted-average cost only once Submitted.
  unit_cost: string | number | null
  amount: string | number | null
}

export interface IssueStock {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  issue_date: string
  remarks: string | null
  items: IssueStockItem[]
  line_count: number | null
  total_value: number | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface IssueStockFormValues {
  warehouse_id: string
  issue_date: string
  remarks: string | null
  items: { item_id: string; qty: number }[]
}

export interface IssueStockFilterValues {
  warehouse_id: string
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface ReceiptStockItem {
  id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty_category: 'unit' | 'weight'
  qty: string | number
  unit_cost: string | number
  amount: string | number
}

export interface ReceiptStock {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  receipt_date: string
  remarks: string | null
  items: ReceiptStockItem[]
  line_count: number | null
  total_value: number | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface ReceiptStockFormValues {
  warehouse_id: string
  receipt_date: string
  remarks: string | null
  items: { item_id: string; qty: number; unit_cost: number }[]
}

export interface ReceiptStockFilterValues {
  warehouse_id: string
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface StockTransferItem {
  id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty: string | number
  qty_category: 'unit' | 'weight'
}

export interface StockTransfer {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  source_warehouse_id: string
  source_warehouse: { id: string; name: string; code: string } | null
  destination_warehouse_id: string
  destination_warehouse: { id: string; name: string; code: string } | null
  transfer_date: string
  remarks: string | null
  items: StockTransferItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface StockTransferFormValues {
  source_warehouse_id: string
  destination_warehouse_id: string
  transfer_date: string
  remarks: string | null
  items: { item_id: string; qty: number }[]
}

export interface StockTransferFilterValues {
  status: DocumentStatus | null
  warehouse_id: string
  dateFrom: string
  dateTo: string
}

export interface FifoLayerDetail {
  id: string
  source_type: VoucherType
  source_id: string
  source_document_number: string | null
  received_date: string
  qty_in: string | number
  qty_remaining: string | number
  unit_cost: string | number
  remaining_value: number
}

export interface FifoValuationGroup {
  item_id: string
  warehouse_id: string
  item_code: string
  item_name: string
  warehouse_name: string
  qty_remaining: number
  total_value: number
  weighted_average_cost: number
  layers: FifoLayerDetail[]
}

export interface FifoValuationSummary {
  total_value: number
  item_count: number
  total_qty: number
}

export interface FifoValuationFilterValues {
  warehouse_id: string
  item_group_id: string
  item_id: string
  dateFrom: string
  dateTo: string
  hideExhausted: boolean
}
