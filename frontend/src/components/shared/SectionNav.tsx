import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useAuth } from '@/app/AuthContext'

export interface SectionNavItem {
  label: string
  path: string
  permission?: string
}

/**
 * Horizontal sub-navigation between sibling pages within one sidebar
 * section (e.g. Master Data's six entities). Real routes, not
 * client-only tab state — each item is a NavLink, so the URL and
 * breadcrumb stay correct and the page is bookmarkable/refreshable.
 */
export function SectionNav({ items }: { items: SectionNavItem[] }) {
  const { user } = useAuth()
  const visibleItems = items.filter((item) => !item.permission || user?.permissions.includes(item.permission))

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
