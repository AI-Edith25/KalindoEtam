import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { Loader2 } from 'lucide-react'
import { useAuth } from './AuthContext'
import { AccessDeniedPage } from './AccessDeniedPage'

export function ProtectedRoute({ children, permission }: { children: ReactNode; permission?: string }) {
  const { isAuthenticated, isLoading, user } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return (
      <div className="flex min-h-svh items-center justify-center">
        <Loader2 className="size-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" state={{ from: location.pathname }} replace />
  }

  if (permission && !user?.permissions.includes(permission)) {
    return <AccessDeniedPage />
  }

  return <>{children}</>
}
