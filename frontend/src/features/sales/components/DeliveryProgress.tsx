import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatNumber } from '@/lib/utils'
import { computeDeliveryStatus, computeDeliveryTotals } from '../lib/deliveryProgress'
import type { SalesOrder } from '../types'

interface DeliveryProgressProps {
  order: SalesOrder
  /** 'sm' for table cells (Sales Order List), 'lg' for the Detail page's prominent summary. */
  size?: 'sm' | 'lg'
}

/**
 * Badge + exact numbers only — no progress bar (per this sprint's
 * explicit instruction: exact values over decorative visualizations,
 * same simplification already applied to Purchase Order's
 * ReceivingProgress). Draft/cancelled orders render a plain dash since
 * delivery is structurally impossible for either.
 */
export function DeliveryProgress({ order, size = 'sm' }: DeliveryProgressProps) {
  const status = computeDeliveryStatus(order)

  if (!status) {
    return <span className="text-sm text-muted-foreground">—</span>
  }

  const { ordered, delivered } = computeDeliveryTotals(order)

  return (
    <div className={size === 'lg' ? 'flex items-center gap-3' : 'flex items-center gap-2'}>
      <StatusBadge status={status} />
      <span className={size === 'lg' ? 'text-base font-medium tabular-nums' : 'text-sm tabular-nums text-muted-foreground'}>
        {formatNumber(delivered)} / {formatNumber(ordered)}
      </span>
    </div>
  )
}
