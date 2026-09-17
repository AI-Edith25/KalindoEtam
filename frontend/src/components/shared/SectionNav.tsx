import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useAuth } from '@/app/AuthContext'
import { findGroup, permissionName } from '@/config/navTree'
import { buttonVariants } from '@/components/ui/button'

/**
 * Horizontal sub-navigation between sibling pages within one sidebar
 * section (e.g. Maintenance's ten entities). Real routes, not
 * client-only tab state — each item is a NavLink, so the URL and
 * breadcrumb stay correct and the page is bookmarkable/refreshable.
 * `group` is a navTree group key — its pages (and each page's view
 * permission) are looked up from the single navTree source of truth.
 *
 * `variant="pills"` renders the same pill-tab look used by the report
 * pages' client-side tabs (Button default/ghost in a bordered strip)
 * instead of the default underline style — for a route-backed sub-tab
 * row that needs to look identical to those. `end` forces exact-path
 * matching, needed when one item's path is itself a prefix of a sibling's
 * (e.g. /reports/general-ledger vs. /reports/general-ledger/trial-balance)
 * so only one tab lights up at a time.
 */
export function SectionNav({ group, variant = 'underline', end = false }: { group: string; variant?: 'underline' | 'pills'; end?: boolean }) {
  const { user } = useAuth()
  const navGroup = findGroup(group)
  const items = (navGroup?.pages ?? []).map((page) => ({
    label: page.label,
    path: page.path,
    permission: permissionName(page.permissionGroup ?? group, page.key, 'view'),
  }))
  const visibleItems = items.filter((item) => user?.permissions.includes(item.permission))

  if (variant === 'pills') {
    return (
      <nav className="flex items-center gap-1 rounded-md border p-1">
        {visibleItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            end={end}
            className={({ isActive }) => cn(buttonVariants({ variant: isActive ? 'default' : 'ghost', size: 'sm' }))}
          >
            {item.label}
          </NavLink>
        ))}
      </nav>
    )
  }

  return (
    <nav className="flex items-center gap-1 border-b">
      {visibleItems.map((item) => (
        <NavLink
          key={item.path}
          to={item.path}
          className={({ isActive }) =>
            cn(
              'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
              isActive
                ? 'border-primary text-foreground'
                : 'border-transparent text-muted-foreground hover:text-foreground',
            )
          }
        >
          {item.label}
        </NavLink>
      ))}
    </nav>
  )
}
