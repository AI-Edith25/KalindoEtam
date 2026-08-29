/**
 * Administration module types — docs/ADMINISTRATION_DESIGN.md. `Company`
 * here is the full field set (npwp, address, phone, email, logo via
 * attachment) the Administration Company page needs; the lighter
 * `Company` in features/master/types.ts is left untouched.
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

/** Unguarded (no company.view needed) — address/phone/email/npwp for invoice/document print headers. Never call /companies for this. */
export interface CompanyPrintHeader {
  name: string | null
  address: string | null
  phone: string | null
  email: string | null
  npwp: string | null
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

export interface PurchaseSetting {
  id: string
  /** null/0 = no upper bound on Weight-category over-receipt. */
  weight_over_receipt_tolerance_percent: number | null
  updated_at: string
}

/** Company-wide default for Invoice Print Preview's "Print Options" — see InvoicePrintSettingsPage and printOptions.ts's PrintOptions (the camelCase, session-editable counterpart of this same shape). */
export interface InvoicePrintSetting {
  id: string
  paper_type: 'a4' | 'continuous' | 'half'
  orientation: 'portrait' | 'landscape'
  /** Keyed by paper type — A4 defaults to 12mm all sides, Continuous/Half to 6mm (their pre-existing hardcoded margins), so one flat value can't represent all three without changing at least one. */
  margins: Record<'a4' | 'continuous' | 'half', { top: number; bottom: number; left: number; right: number }>
  scale_percent: number
  font_family: string
  font_size: 'small' | 'medium' | 'large'
  qty_decimals: number
  price_decimals: number
  amount_decimals: number
  number_format: 'id' | 'en'
  show_currency_symbol: boolean
  show_discount: boolean
  visible_columns: string[]
  show_logo: boolean
  show_address: boolean
  show_phone: boolean
  show_email: boolean
  footer_notes: string | null
  show_signature_block: boolean
  signature_left_label: string
  signature_right_label: string
  show_page_number: boolean
  updated_at: string
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

/**
 * document_type is a plain string key, not a fixed enum on either side —
 * the whole point of Sprint 2 (Invoice Numbering) is that a new document
 * type's numbering is configured here, not hardcoded in app code. Existing
 * keys in use: invoice_goods, invoice_transportation, credit_note,
 * debit_note, purchase, goods_receipt, sales, delivery, journal, payment,
 * receipt, stock_adjustment.
 */
export interface NamingSeries {
  id: string
  module: string
  document_type: string
  prefix: string | null
  suffix: string | null
  digit_length: number
  current_number: number
  is_default: boolean
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface NamingSeriesFormValues {
  module: string
  document_type: string
  prefix: string | null
  suffix: string | null
  digit_length: number
  is_default: boolean
  is_active: boolean
}

export interface AuditLogFilterValues {
  user_id?: string
  module?: string
  date_from?: string
  date_to?: string
  search?: string
}
