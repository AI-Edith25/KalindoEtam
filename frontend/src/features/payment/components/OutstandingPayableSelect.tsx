import { useQuery } from '@tanstack/react-query'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { formatCurrency } from '@/lib/utils'
import { fetchAccountsPayables } from '../api/accountsPayableApi'
import { fetchPurchaseOrders } from '@/features/purchase/api/purchaseOrderApi'
import type { AccountsPayable } from '../types'

interface OutstandingPayableSelectProps {
  supplierId: string | null
  value: string | null
  onChange: (accountsPayable: AccountsPayable) => void
}

/**
 * Source Document picker for Outgoing Payment. Fetches every Accounts
 * Payable for the chosen supplier and filters out already-paid rows
 * client-side — IndexAccountsPayableRequest.status only matches one exact
 * value, "not paid" (unpaid OR partially_paid) needs an OR, cheaper done
 * here than adding a second backend filter shape for one dropdown.
 */
export function OutstandingPayableSelect({ supplierId, value, onChange }: OutstandingPayableSelectProps) {
  const payablesQuery = useQuery({
    queryKey: ['accounts-payables', supplierId],
    queryFn: () => fetchAccountsPayables({ supplier_id: supplierId!, per_page: 100 }),
    enabled: !!supplierId,
  })
  const outstanding = (payablesQuery.data?.data ?? []).filter((ap) => ap.status !== 'paid')

  // AccountsPayableResource exposes purchase_order_id only, not a nested object — same lookup-join pattern as GoodsReceiptListPage.
  const purchaseOrdersLookup = useQuery({
    queryKey: ['purchase-orders-lookup'],
    queryFn: () => fetchPurchaseOrders({ page: 1, per_page: 100 }),
  })
  const purchaseOrderNumber = (purchaseOrderId: string) =>
    purchaseOrdersLookup.data?.data.find((po) => po.id === purchaseOrderId)?.document_number ?? '—'

  return (
    <Select
      value={value ?? ''}
      onValueChange={(next) => {
        const selected = outstanding.find((ap) => ap.id === next)
        if (selected) onChange(selected)
      }}
      disabled={!supplierId || payablesQuery.isLoading}
    >
      <SelectTrigger className="w-full">
        <SelectValue
          placeholder={
            !supplierId
              ? 'Select a supplier first'
              : payablesQuery.isLoading
                ? 'Loading…'
                : outstanding.length === 0
                  ? 'No outstanding payables for this supplier'
                  : 'Select source document'
          }
        />
      </SelectTrigger>
      <SelectContent>
        {outstanding.map((ap) => (
          <SelectItem key={ap.id} value={ap.id}>
            {purchaseOrderNumber(ap.purchase_order_id)} — Outstanding: {formatCurrency(ap.outstanding_amount)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
