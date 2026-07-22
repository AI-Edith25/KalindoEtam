import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/utils'
import { useAuth } from '@/app/AuthContext'
import { navTree, permissionName, DASHBOARD_NAV, type NavGroup } from '@/config/navTree'

/** First page the user actually holds view permission for. */
function targetPathFor(group: NavGroup, permissions: string[]): string | null {
  return group.pages.find((page) => permissions.includes(permissionName(group.key, page.key, 'view')))?.path ?? null
}

export function SidebarNav({ onNavigate }: { onNavigate?: () => void }) {
  const { user } = useAuth()
  const permissions = user?.permissions ?? []

  const items = [
    { label: DASHBOARD_NAV.label, path: DASHBOARD_NAV.path, icon: DASHBOARD_NAV.icon },
    ...navTree
      .map((group) => ({ group, path: targetPathFor(group, permissions) }))
      .filter((entry): entry is { group: NavGroup; path: string } => entry.path !== null)
      .map(({ group, path }) => ({ label: group.label, path, icon: group.icon })),
  ]

  return (
    <nav className="flex flex-col gap-1 p-3">
      {items.map((item) => (
        <NavLink
          key={item.path}
          to={item.path}
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
