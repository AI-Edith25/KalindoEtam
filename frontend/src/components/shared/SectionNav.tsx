import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useAuth } from '@/app/AuthContext'
import { findGroup, permissionName } from '@/config/navTree'

/**
 * Horizontal sub-navigation between sibling pages within one sidebar
 * section (e.g. Master Data's eight entities). Real routes, not
 * client-only tab state — each item is a NavLink, so the URL and
 * breadcrumb stay correct and the page is bookmarkable/refreshable.
 * `group` is a navTree group key — its pages (and each page's view
 * permission) are looked up from the single navTree source of truth.
 */
export function SectionNav({ group }: { group: string }) {
  const { user } = useAuth()
  const navGroup = findGroup(group)
  const items = (navGroup?.pages ?? []).map((page) => ({
    label: page.label,
    path: page.path,
    permission: permissionName(group, page.key, 'view'),
  }))
  const visibleItems = items.filter((item) => user?.permissions.includes(item.permission))

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
