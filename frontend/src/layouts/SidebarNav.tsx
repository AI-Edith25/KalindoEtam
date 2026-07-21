import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useAuth } from '@/app/AuthContext'
import { navItems, type NavItem } from './navigation'

/** First child destination the user actually holds permission for; falls back to the item's representative path when it has no children (e.g. Dashboard) or the user holds none of them. */
function targetPathFor(item: NavItem, permissions: string[]): string {
  if (!item.children) return item.path
  return item.children.find((child) => permissions.includes(child.permission))?.path ?? item.path
}

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const { user } = useAuth()
  const permissions = user?.permissions ?? []
  const visibleItems = navItems.filter(
    (item) => !item.children || item.children.some((child) => permissions.includes(child.permission)),
  )

  return (
    <nav className="flex flex-col gap-1 p-3">
      {visibleItems.map((item) => (
        <NavLink
          key={item.path}
          to={targetPathFor(item, permissions)}
          onClick={onNavigate}
          className={({ isActive }) =>
            cn(
              'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
              isActive
                ? 'bg-primary text-primary-foreground'
                : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            )
          }
        >
          <item.icon className="size-4 shrink-0" />
          {item.label}
        </NavLink>
      ))}
    </nav>
  )
}
