import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  description?: string
  /** e.g. "6 items" — the loaded page's total record count, not just what's on screen. */
  count?: string
  actions?: ReactNode
}

/**
 * Breadcrumb is not rendered here — AppLayout already renders one above
 * every page from the current route, so repeating it per-page would be
 * duplicate UI. This is the title/count/actions row underneath it.
 */
export function PageHeader({ title, description, count, actions }: PageHeaderProps) {
  return (
    <div className="flex items-center justify-between gap-4">
      <div>
        <div className="flex items-baseline gap-2">
          <h1 className="text-2xl font-semibold">{title}</h1>
          {count && <span className="text-sm text-muted-foreground">{count}</span>}
        </div>
        {description && <p className="text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  )
}
