import type { LucideIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import type { buttonVariants } from '@/components/ui/button'
import type { VariantProps } from 'class-variance-authority'

export interface ActionBarAction {
  label: string
  icon?: LucideIcon
  onClick?: () => void
  disabled?: boolean
  variant?: VariantProps<typeof buttonVariants>['variant']
}

interface ActionBarProps {
  /** Rendered first, in order, as outline buttons unless overridden (Refresh, Export, Import, ...). */
  actions?: ActionBarAction[]
  /** Rendered last, as the solid/default-variant button (New Item, ...). */
  primary?: ActionBarAction
}

/**
 * Standard action row for every CRUD page's toolbar. Disabled actions
 * (e.g. Export/Import before they're implemented) are passed the same
 * way as active ones — the bar doesn't care why something is disabled.
 */
export function ActionBar({ actions = [], primary }: ActionBarProps) {
  return (
    <div className="flex items-center gap-2">
      {actions.map((action) => (
        <Button key={action.label} variant={action.variant ?? 'outline'} onClick={action.onClick} disabled={action.disabled}>
          {action.icon && <action.icon className="size-4" />}
          {action.label}
        </Button>
      ))}
      {primary && (
        <Button variant={primary.variant} onClick={primary.onClick} disabled={primary.disabled}>
          {primary.icon && <primary.icon className="size-4" />}
          {primary.label}
        </Button>
      )}
    </div>
  )
}
