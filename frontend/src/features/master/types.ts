export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'

export interface ChartOfAccount {
  id: string
  code: string
  name: string
  account_type: AccountType
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface ChartOfAccountFormValues {
  code: string
  name: string
  account_type: AccountType
  is_active?: boolean
}

export type TaxType = 'vat' | 'zero_rated' | 'exempt'

/**
 * Tax Engine (Sprint 21B) — see docs/TAX_ENGINE_DESIGN.md. rate/type only
 * matter for `type = 'vat'`; Zero Rated and Exempt always calculate to
 * Rp 0 regardless of the stored rate (TaxService::calculate() decides
 * this server-side — never reimplemented here).
 */
export interface Tax {
  id: string
  code: string
  name: string
  type: TaxType
  rate: string | number
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface TaxFormValues {
  code: string
  name: string
  type: TaxType
  rate: number
  is_active?: boolean
}

export interface ItemGroup {
  id: string
  name: string
  description: string | null
  created_at: string
  updated_at: string
}

export interface Uom {
  id: string
  name: string
  symbol: string | null
  created_at: string
  updated_at: string
}

export interface Item {
  id: string
  item_code: string
  item_name: string
  item_group_id: string
  item_group: ItemGroup | null
  uom_id: string
  uom: Uom | null
  standard_rate: string | number
  current_stock: number
  created_at: string
  updated_at: string
}

export interface ItemFormValues {
  item_code: string
  item_name: string
  item_group_id: string
  uom_id: string
  standard_rate: number
}

export interface ItemGroupFormValues {
  name: string
  description: string | null
}

export interface UomFormValues {
  name: string
  symbol: string | null
}

export interface Supplier {
  id: string
  supplier_code: string
  supplier_name: string
  phone: string | null
  email: string | null
  address: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface SupplierFormValues {
  supplier_code: string
  supplier_name: string
  phone: string | null
  email: string | null
  address: string | null
  is_active: boolean
}

export interface Customer {
  id: string
  customer_code: string
  customer_name: string
  phone: string | null
  email: string | null
  address: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface CustomerFormValues {
  customer_code: string
  customer_name: string
  phone: string | null
  email: string | null
  address: string | null
  is_active: boolean
}

export interface Branch {
  id: string
  company_id: string
  name: string
  code: string
  address: string | null
  is_head_office: boolean
}

export interface Company {
  id: string
  name: string
  code: string
  fiscal_year_start: string
}

export type WarehouseType = 'main' | 'transit' | 'return'

export interface Warehouse {
  id: string
  branch_id: string
  name: string
  code: string
  warehouse_type: WarehouseType
  created_at: string
  updated_at: string
}

export interface WarehouseFormValues {
  branch_id: string
  name: string
  code: string
  warehouse_type: WarehouseType
}
