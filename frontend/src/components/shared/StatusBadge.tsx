import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

/**
 * Maps every status string used across the API (document lifecycle:
 * draft/submitted/cancelled, settlement: unpaid/partially_paid/paid) to
 * a color. Unknown values still render — just with a neutral color —
 * so this never needs updating when a new status value shows up.
 */
const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-muted text-muted-foreground border-transparent',
  submitted: 'bg-blue-100 text-blue-700 border-transparent dark:bg-blue-950 dark:text-blue-300',
  cancelled: 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300',
  open: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  closed: 'bg-muted text-muted-foreground border-transparent',
  unpaid: 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300',
  partially_paid: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-950 dark:text-amber-300',
  paid: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  active: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  inactive: 'bg-muted text-muted-foreground border-transparent',
  in_stock: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  out_of_stock: 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300',
  main: 'bg-blue-100 text-blue-700 border-transparent dark:bg-blue-950 dark:text-blue-300',
  transit: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-950 dark:text-amber-300',
  return: 'bg-muted text-muted-foreground border-transparent',
  waiting: 'bg-blue-100 text-blue-700 border-transparent dark:bg-blue-950 dark:text-blue-300',
  partial: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-950 dark:text-amber-300',
  completed: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  in: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  out: 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300',
  adjustment: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-950 dark:text-amber-300',
  // Approval Workflow (Sprint 24B) — reuses ApprovalStatus's own three values as-is.
  pending: 'bg-amber-100 text-amber-700 border-transparent dark:bg-amber-950 dark:text-amber-300',
  approved: 'bg-green-100 text-green-700 border-transparent dark:bg-green-950 dark:text-green-300',
  rejected: 'bg-red-100 text-red-700 border-transparent dark:bg-red-950 dark:text-red-300',
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
