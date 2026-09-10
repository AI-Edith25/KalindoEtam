import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/**
 * Maps every status string used across the API (document lifecycle:
 * draft/submitted/cancelled, settlement: unpaid/partially_paid/paid) to
 * a color. Unknown values still render — just with a neutral color —
 * so this never needs updating when a new status value shows up.
 */
const SUCCESS = 'bg-success/15 text-success-foreground border-success-border'
const WARNING = 'bg-warning/15 text-warning-foreground border-warning-border'
const ERROR = 'bg-destructive/15 text-destructive border-destructive/40'
const INFO = 'bg-info/15 text-info-foreground border-info-border'
const NEUTRAL = 'bg-muted text-muted-foreground border-transparent'

const STATUS_STYLES: Record<string, string> = {
  draft: NEUTRAL,
  submitted: INFO,
  cancelled: ERROR,
  open: SUCCESS,
  closed: NEUTRAL,
  unpaid: ERROR,
  partially_paid: WARNING,
  paid: SUCCESS,
  active: SUCCESS,
  inactive: NEUTRAL,
  in_stock: SUCCESS,
  out_of_stock: ERROR,
  main: INFO,
  transit: WARNING,
  return: NEUTRAL,
  waiting: INFO,
  partial: WARNING,
  completed: SUCCESS,
  complete: SUCCESS,
  in: SUCCESS,
  out: ERROR,
  adjustment: WARNING,
  // Approval Workflow (Sprint 24B) — reuses ApprovalStatus's own three values as-is.
  pending: WARNING,
  approved: SUCCESS,
  rejected: ERROR,
  // Outgoing Payment's Payment Type (Supplier vs. General Expense) — the list's type indicator.
  supplier: INFO,
  general_expense: WARNING,
  // Outstanding view (Sales Order / Delivery inline badges) — self-describing rows on the "Semua" view.
  outstanding: WARNING,
  fully_delivered: SUCCESS,
  not_invoiced: WARNING,
  invoiced: SUCCESS,
  // Open Orders tab (Sales Report rework) — delivery/invoice status shown as two separate badges per row.
  not_delivered: WARNING,
  partially_delivered: WARNING,
  partially_invoiced: WARNING,
  fully_invoiced: SUCCESS,
}

function formatLabel(status: string): string {
  return status
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function StatusBadge({ status }: { status: string }) {
  const style = STATUS_STYLES[status] ?? 'bg-secondary text-secondary-foreground border-transparent'

  return <Badge className={cn(style)}>{formatLabel(status)}</Badge>
}
