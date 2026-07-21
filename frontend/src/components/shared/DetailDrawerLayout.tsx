import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import type { buttonVariants } from '@/components/ui/button'
import type { VariantProps } from 'class-variance-authority'

interface DetailDrawerAction {
  label: string
  icon?: LucideIcon
  onClick: () => void
  variant?: VariantProps<typeof buttonVariants>['variant']
}

interface DetailDrawerLayoutProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  subtitle?: string
  badge?: ReactNode
  children: ReactNode
  primaryAction?: DetailDrawerAction
  secondaryAction?: DetailDrawerAction
}

/**
 * The standard read-only Drawer shell — header (title + subtitle +
 * status badge), scrollable body of caller-supplied sections, footer
 * (primary/secondary actions). Every module's "View Details" drawer
 * should be built on this rather than a one-off Sheet.
 */
export function DetailDrawerLayout({
  open,
  onOpenChange,
  title,
  subtitle,
  badge,
  children,
  primaryAction,
  secondaryAction,
}: DetailDrawerLayoutProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-md">
        <SheetHeader>
          <div className="flex items-center gap-2">
            <SheetTitle>{title}</SheetTitle>
            {badge}
          </div>
          {subtitle && <SheetDescription>{subtitle}</SheetDescription>}
        </SheetHeader>

        <div className="flex flex-col gap-4 overflow-y-auto px-4">{children}</div>

        {(primaryAction || secondaryAction) && (
          <SheetFooter>
            {primaryAction && (
              <Button variant={primaryAction.variant} onClick={primaryAction.onClick}>
                {primaryAction.icon && <primaryAction.icon className="size-4" />}
                {primaryAction.label}
              </Button>
            )}
            {secondaryAction && (
              <Button variant={secondaryAction.variant ?? 'outline'} onClick={secondaryAction.onClick}>
                {secondaryAction.icon && <secondaryAction.icon className="size-4" />}
                {secondaryAction.label}
              </Button>
            )}
          </SheetFooter>
        )}
      </SheetContent>
    </Sheet>
  )
}

/** One labeled value inside a DetailSection grid. */
export function DetailField({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-sm font-medium">{value}</span>
    </div>
  )
}

/** One logical information group (optional heading + a 2-column grid of DetailFields). */
export function DetailSection({ title, children }: { title?: string; children: ReactNode }) {
  return (
    <div>
      {title && <p className="mb-2 text-xs font-medium text-muted-foreground">{title}</p>}
      <div className="grid grid-cols-2 gap-4">{children}</div>
    </div>
  )
}
