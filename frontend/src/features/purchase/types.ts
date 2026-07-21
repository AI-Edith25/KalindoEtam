import type { ApprovalFlow } from '../approval/types'

export type DocumentStatus = 'draft' | 'submitted' | 'cancelled'

export interface PurchaseOrderItem {
  id: string
  item_id: string
  item_code: string | null
  item_name: string | null
  qty: number
  rate: string | number
  amount: string | number
  received_qty: number
  outstanding_qty: number
}

export interface PurchaseOrder {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  supplier_id: string
  supplier: { id: string; supplier_code: string; supplier_name: string } | null
  order_date: string
  expected_delivery_date: string | null
  total_amount: string | number
  tax_id: string | null
  tax: { id: string; code: string; name: string; type: string; rate: string | number } | null
  tax_amount: string | number
  grand_total: string | number
  remarks: string | null
  items: PurchaseOrderItem[]
  is_fully_received: boolean | null
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
  requires_approval: boolean
  latest_approval: ApprovalFlow | null
}

export interface PurchaseOrderLineFormValues {
  item_id: string
  qty: string
  rate: string
}

export interface PurchaseOrderFormValues {
  supplier_id: string
  order_date: string
  expected_delivery_date: string | null
  tax_id: string | null
  remarks: string | null
  items: { item_id: string; qty: number; rate: number }[]
}

export interface PurchaseOrderFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}

export interface GoodsReceiptItem {
  id: string
  purchase_order_item_id: string
  item_id: string
  item_code: string
  item_name: string
  uom: string
  qty: number
  rate: string | number
  amount: string | number
}

export interface GoodsReceipt {
  id: string
  document_number: string | null
  status: DocumentStatus
  revision: number
  purchase_order_id: string
  supplier_id: string
  supplier: { id: string; supplier_code: string; supplier_name: string } | null
  warehouse_id: string
  warehouse: { id: string; name: string; code: string } | null
  receipt_date: string
  due_date: string
  remarks: string | null
  items: GoodsReceiptItem[]
  submitted_at: string | null
  cancelled_at: string | null
  created_at: string
}

export interface GoodsReceiptFormValues {
  purchase_order_id: string
  warehouse_id: string
  receipt_date: string
  due_date: string
  remarks: string | null
  items: { purchase_order_item_id: string; qty: number }[]
}

export interface GoodsReceiptFilterValues {
  status: DocumentStatus | null
  dateFrom: string
  dateTo: string
}
