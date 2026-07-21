/**
 * Administration module types — docs/ADMINISTRATION_DESIGN.md. `Company`
 * here is the full field set (npwp, address, phone, email, logo via
 * attachment) the Administration Company page needs; the lighter
 * `Company` in features/master/types.ts (used by Warehouse's branch
 * lookup) is left untouched.
 */
export interface Company {
  id: string
  name: string
  code: string
  address: string | null
  phone: string | null
  email: string | null
  npwp: string | null
  currency: string
  timezone: string
  fiscal_year_start: string
  created_at: string
  updated_at: string
}

/** App-shell branding only (Header/Sidebar) — deliberately not the full `Company` shape, matches GET /company/branding. */
export interface CompanyBranding {
  name: string | null
  logo_url: string | null
}

export interface CompanyFormValues {
  name: string
  code: string
  address: string | null
  phone: string | null
  email: string | null
  npwp: string | null
  fiscal_year_start: string
}

export interface DocumentAttachment {
  id: string
  attachable_type: string
  attachable_id: string
  disk: string
  file_path: string
  original_filename: string
  extension: string
  mime_type: string
  file_size: number
  uploaded_by: string | null
  created_at: string
}

export interface User {
  id: string
  name: string
  email: string
  is_active: boolean
  roles: string[]
  created_at: string
  updated_at: string
}

export interface UserFormValues {
  name: string
  email: string
  password?: string
  role?: string | null
}

export interface Role {
  id: string
  name: string
  guard_name: string
  permissions: string[]
  created_at: string
  updated_at: string
}

export interface Permission {
  id: string
  name: string
  guard_name: string
}

export interface AuditLog {
  id: string
  user: { id: string; name: string; email: string } | null
  action: string
  module: string
  description: string | null
  ip_address: string | null
  created_at: string
}

export interface AuditLogFilterValues {
  user_id?: string
  module?: string
  date_from?: string
  date_to?: string
  search?: string
}
