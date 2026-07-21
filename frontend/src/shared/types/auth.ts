export interface AuthUser {
  id: string
  name: string
  email: string
  roles: string[]
  /** Reused for dashboard widget visibility (docs/DASHBOARD_DESIGN.md §4) — Spatie's own aggregate permission list, not a new concept. */
  permissions: string[]
}

export interface LoginPayload {
  email: string
  password: string
}

export interface LoginResult {
  token: string
  user: AuthUser
}
