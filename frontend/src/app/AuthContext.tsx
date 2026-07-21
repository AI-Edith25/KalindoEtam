import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { AUTH_TOKEN_KEY } from '@/shared/services/apiClient'
import { loginRequest, logoutRequest, meRequest } from '@/shared/services/authApi'
import type { AuthUser } from '@/shared/types/auth'

interface AuthContextValue {
  user: AuthUser | null
  isLoading: boolean
  isAuthenticated: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    const token = localStorage.getItem(AUTH_TOKEN_KEY)

    if (!token) {
      setIsLoading(false)
      return
    }

    meRequest()
      .then(setUser)
      .catch(() => localStorage.removeItem(AUTH_TOKEN_KEY))
      .finally(() => setIsLoading(false))
  }, [])

  const login = async (email: string, password: string) => {
    const result = await loginRequest({ email, password })
    localStorage.setItem(AUTH_TOKEN_KEY, result.token)
    setUser(result.user)
  }

  const logout = async () => {
    try {
      await logoutRequest()
    } finally {
      localStorage.removeItem(AUTH_TOKEN_KEY)
      setUser(null)
    }
  }

  return (
    <AuthContext.Provider value={{ user, isLoading, isAuthenticated: user !== null, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider')
  }

  return context
}
