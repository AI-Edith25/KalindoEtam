/**
 * Payment/Receipt never reference PurchaseOrder/SalesOrder directly — this
 * resolves the display link from whichever id the caller already has
 * (from a client-side lookup-join, same as GoodsReceiptListPage's
 * purchaseOrderNumber()/DeliveryListPage's salesOrderNumber()). Kept as a
 * pure function, same shape as inventory/lib/voucherLinks.ts, so swapping
 * the source document to a future Invoice only touches the caller that
 * decides which kind/id to pass in — this function's contract doesn't change.
 */
export function resolveSourceDocumentLink(kind: 'purchase_order' | 'sales_order' | 'invoice', id: string): string {
  if (kind === 'purchase_order') return `/purchase/orders/${id}`
  if (kind === 'invoice') return `/sales/invoices/${id}`
  return `/sales/orders/${id}`
}
