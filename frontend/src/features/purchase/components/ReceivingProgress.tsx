import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatNumber } from '@/lib/utils'
import { computeReceivingStatus, computeReceivingTotals } from '../lib/receivingProgress'
import type { PurchaseOrder } from '../types'

interface ReceivingProgressProps {
  order: PurchaseOrder
  /** 'sm' for table cells (Purchase Order List), 'lg' for the Detail page's prominent summary. */
  size?: 'sm' | 'lg'
}

/**
 * The numbers (Received / Ordered) always render, per this sprint's
 * requirement — the badge is an addition, not a replacement. For a draft
 * or cancelled order (nothing to receive, ever) this renders a plain dash
 * rather than a fabricated "Waiting" status.
 *
 * No progress bar — exact numbers already communicate progress; a bar was
 * decorative and added row height for no scanning benefit.
 */
export function ReceivingProgress({ order, size = 'sm' }: ReceivingProgressProps) {
  const status = computeReceivingStatus(order)

  if (!status) {
    return <span className="text-sm text-muted-foreground">—</span>
  }

  const { ordered, received } = computeReceivingTotals(order)

  return (
    <div className={size === 'lg' ? 'flex items-center gap-3' : 'flex items-center gap-2'}>
      <StatusBadge status={status} />
      <span className={size === 'lg' ? 'text-base font-medium tabular-nums' : 'text-sm tabular-nums text-muted-foreground'}>
        {formatNumber(received)} / {formatNumber(ordered)}
      </span>
    </div>
  )
}
