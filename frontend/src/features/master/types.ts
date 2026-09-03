export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense'

export type CashBankCategory = 'petty_cash' | 'cash_book'

export interface ChartOfAccount {
  id: string
  code: string
  name: string
  account_type: AccountType
  is_active: boolean
  is_cash_bank: boolean
  cash_bank_category: CashBankCategory | null
  created_at: string
  updated_at: string
}

export interface ChartOfAccountFormValues {
  code: string
  name: string
  account_type: AccountType
  is_active?: boolean
  is_cash_bank?: boolean
  cash_bank_category?: CashBankCategory | null
}

export type TaxType = 'vat' | 'zero_rated' | 'exempt'

export type TaxTransactionType = 'purchase' | 'sales'

export type TaxCalculationMode = 'inclusive' | 'exclusive'

/**
 * Tax Engine (Sprint 21B) — see docs/TAX_ENGINE_DESIGN.md. rate/type only
 * matter for `type = 'vat'`; Zero Rated and Exempt always calculate to
 * Rp 0 regardless of the stored rate (TaxService::calculate() decides
 * this server-side — never reimplemented here). transaction_type/
 * calculation_mode added for per-line Purchase/Sales tax — a Tax record
 * applies to exactly one side of a transaction, never both.
 */
export interface Tax {
  id: string
  code: string
  name: string
  type: TaxType
  transaction_type: TaxTransactionType
  rate: string | number
  calculation_mode: TaxCalculationMode
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface TaxFormValues {
  code: string
  name: string
  type: TaxType
  transaction_type: TaxTransactionType
  rate: number
  calculation_mode: TaxCalculationMode
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
  current_stock: string | number
  purchase_tax_id: string | null
  purchase_tax: Tax | null
  sales_tax_id: string | null
  sales_tax: Tax | null
  /** Lets a Goods Receipt line for this item exceed the PO's outstanding qty — bulk items (cement) received by truck-scale weight. */
  allow_over_receipt: boolean
  /** Decides qty input type everywhere this item appears — 'unit' (integer, e.g. zak) or 'weight' (2 decimals, e.g. bulk cement). */
  qty_category: 'unit' | 'weight'
  /** Only meaningful when the /items request carried a price_zone_id and/or warehouse_id — otherwise equals standard_rate. */
  effective_rate: string | number
  /** "Samakan dengan Main WH" — when true, every non-Main-warehouse price for this item resolves live from the Main warehouse's own price. */
  sync_to_main_wh: boolean
  created_at: string
  updated_at: string
}

export interface ItemFormValues {
  item_code: string
  item_name: string
  item_group_id: string
  uom_id: string
  standard_rate: number
  purchase_tax_id?: string | null
  sales_tax_id?: string | null
  qty_category: 'unit' | 'weight'
}

export interface ItemGroupFormValues {
  name: string
  description: string | null
}

export interface PriceZone {
  id: string
  name: string
  description: string | null
  created_at: string
  updated_at: string
}

export interface PriceZoneFormValues {
  name: string
  description: string | null
}

export interface ItemPrice {
  id: string
  item_id: string
  item: Item | null
  price_zone_id: string
  price_zone: PriceZone | null
  rate: string | number
  created_at: string
  updated_at: string
}

export interface ItemPriceFormValues {
  item_id: string
  price_zone_id: string
  rate: number
}

export interface ItemWarehousePrice {
  id: string
  item_id: string
  item: Item | null
  warehouse_id: string
  warehouse: Warehouse | null
  rate: string | number
  created_at: string
  updated_at: string
}

export interface ItemWarehousePriceCell {
  item_id: string
  warehouse_id: string
  /** null = delete the override, falls back to Standard Rate (or Main WH's price when sync_to_main_wh is on). */
  rate: number | null
}

export interface ItemWarehousePriceCellResult extends ItemWarehousePriceCell {
  status: 'saved' | 'error'
}

export interface UomFormValues {
  name: string
  symbol: string | null
}

export type MiscellaneousChargeType = 'addition' | 'deduction' | 'addition_percent' | 'deduction_percent'

export interface MiscellaneousItem {
  id: string
  misc_code: string
  description: string
  rate: string | number
  uom_id: string | null
  uom: Uom | null
  charge_type: MiscellaneousChargeType
  unit_cost: string | number
  sales_account_id: string
  sales_account: ChartOfAccount | null
  purchase_account_id: string
  purchase_account: ChartOfAccount | null
  created_at: string
  updated_at: string
}

export interface MiscellaneousItemFormValues {
  misc_code: string
  description: string
  rate: number
  uom_id: string | null
  charge_type: MiscellaneousChargeType
  unit_cost: number
  sales_account_id: string
  purchase_account_id: string
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
  credit_limit: string | number | null
  terms_of_payment_id: string | null
  price_zone_id: string | null
  price_zone: PriceZone | null
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
  credit_limit: number | null
  terms_of_payment_id: string | null
  price_zone_id: string | null
  is_active: boolean
}

export interface Branch {
  id: string
  company_id: string
  name: string
  code: string
  address: string | null
  is_head_office: boolean
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface BranchFormValues {
  company_id: string
  name: string
  code: string
  address: string | null
  is_head_office?: boolean
  is_active: boolean
}

export interface SalesPerson {
  id: string
  code: string
  name: string
  phone: string | null
  email: string | null
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface SalesPersonFormValues {
  code: string
  name: string
  phone: string | null
  email: string | null
  is_active: boolean
}

export interface SalesTarget {
  id: string
  sales_person_id: string
  sales_person: { id: string; code: string; name: string } | null
  branch_id: string | null
  branch: { id: string; code: string; name: string } | null
  period_month: number
  period_year: number
  target_amount: string | number
  created_at: string
  updated_at: string
}

export interface SalesTargetFormValues {
  sales_person_id: string
  branch_id: string | null
  period_month: number
  period_year: number
  target_amount: number
}

export interface TermsOfPayment {
  id: string
  code: string
  name: string
  days: number
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface TermsOfPaymentFormValues {
  code: string
  name: string
  days: number
  is_active: boolean
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
  name: string
  code: string
  warehouse_type: WarehouseType
  created_at: string
  updated_at: string
}

export interface WarehouseFormValues {
  name: string
  code: string
  warehouse_type: WarehouseType
}
