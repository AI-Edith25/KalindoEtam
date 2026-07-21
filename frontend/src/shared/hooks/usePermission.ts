import { useAuth } from '@/app/AuthContext'

/** Gates dashboard widgets (and anything else) by the logged-in user's actual permissions — reuses Sprint 22B's {module}.{action} names, never a role-name check. See docs/DASHBOARD_DESIGN.md §4. */
export function useHasPermission(permission: string): boolean {
  const { user } = useAuth()
  return user?.permissions.includes(permission) ?? false
}
