import {
  LayoutDashboard,
  Database,
  Warehouse,
  ShoppingCart,
  Receipt,
  Wallet,
  BookOpen,
  BarChart3,
  Settings,
  type LucideIcon,
} from 'lucide-react'

export type Action = 'view' | 'create' | 'update' | 'delete' | 'approve' | 'import' | 'edit' | 'override_credit_check' | 'override_stock_check'

export interface NavPage {
  /** Unique within its group — combines with the group key to form the permission name. */
  key: string
  label: string
  path: string
  /** Which actions this page actually has permissions for — drives the Role Management UI's columns per page. */
  actions: Action[]
  /** Permission-name group override — set when a page is displayed under a different nav group
   *  than the one whose permission prefix it must keep (e.g. Chart of Accounts moved into Finance
   *  but stays master.chart_of_accounts.* to avoid a permission migration/role reassignment).
   *  Falls back to the owning group's own key when absent. */
  permissionGroup?: string
}

export interface NavGroup {
  key: string
  label: string
  icon: LucideIcon
  pages: NavPage[]
  /** Excluded from SidebarNav's level-1 items — group still fully resolvable via findGroup() for SectionNav/PermissionMatrixDrawer/route guards. */
  hideFromSidebar?: boolean
}

/**
 * The single source of truth for navigation AND permissions — sidebar,
 * breadcrumb, SectionNav, route guards, and the Role Management permission
 * tree all derive from this one tree instead of five hand-synced copies.
 * Permission names are generated as `{group.key}.{page.key}.{action}`
 * (see permissionName()) — pages are scoped to the application page that
 * owns them, never a shared backend resource, so two pages that happen to
 * read the same API endpoint (e.g. Sales > Deliveries and Reports >
 * Delivery both read /deliveries) never share a permission by accident.
 *
 * Dashboard is deliberately NOT a group here — it has no sibling pages, no
 * SectionNav, and no per-page permission (just standalone `dashboard.view`,
 * checked ad-hoc by its own widgets) — see docs/PERMISSION_MODEL_DESIGN.md.
 */
export const navTree: NavGroup[] = [
  {
    key: 'master',
    label: 'Maintenance',
    icon: Database,
    pages: [
      { key: 'items', label: 'Items', path: '/master/items', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'miscellaneous', label: 'Miscellaneous', path: '/master/miscellaneous', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'suppliers', label: 'Suppliers', path: '/master/suppliers', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'customers', label: 'Customers', path: '/master/customers', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'sales_persons', label: 'Sales Persons', path: '/master/sales-persons', actions: ['view', 'create', 'update', 'delete', 'import'] },
      // Sales Targets — hidden from nav (feature retired, historical data kept in DB, see UpdateSalesTargetRequest/SalesTargetController).
      // { key: 'sales_targets', label: 'Sales Targets', path: '/master/sales-targets', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'terms_of_payment', label: 'Terms of Payment', path: '/master/terms-of-payment', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'warehouses', label: 'Location', path: '/master/warehouses', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'item_groups', label: 'Item Groups', path: '/master/item-groups', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'uoms', label: 'UOMs', path: '/master/uoms', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'taxes', label: 'Taxes', path: '/master/taxes', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'item_prices', label: 'Item Prices', path: '/master/item-prices', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'fleets', label: 'Fleets', path: '/master/fleets', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'driver_commission_rates', label: 'Driver Commission Rates', path: '/master/driver-commission-rates', actions: ['view', 'create', 'delete'] },
    ],
  },
  {
    key: 'inventory',
    label: 'Inventory',
    icon: Warehouse,
    pages: [
      // adjustments is first — SidebarNav's targetPathFor() derives the main "Inventory" link
      // from whichever page here comes first that the user holds .view for, so this order is
      // also what decides that link (Stock Balance/Stock Ledger moved to Reports > Inventory
      // Stock, no longer live here to claim it).
      { key: 'adjustments', label: 'Adjustments', path: '/inventory/adjustments', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'transfers', label: 'Transfer Stock', path: '/inventory/transfers', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'opening_stock', label: 'Opening Stock', path: '/inventory/opening-stock', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'issue_stock', label: 'Issue Stock', path: '/inventory/issue-stock', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'receipt_stock', label: 'Receipt Stock', path: '/inventory/receipt-stock', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'purchase',
    label: 'Purchase',
    icon: ShoppingCart,
    pages: [
      { key: 'orders', label: 'Orders', path: '/purchase/orders', actions: ['view', 'create', 'update', 'delete', 'approve'] },
      { key: 'goods_receipts', label: 'Goods Receipts', path: '/purchase/goods-receipts', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'invoices', label: 'Invoices', path: '/purchase/invoices', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'returns', label: 'Returns', path: '/purchase/returns', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'sales',
    label: 'Sales',
    icon: Receipt,
    pages: [
      {
        key: 'orders',
        label: 'Orders',
        path: '/sales/orders',
        actions: ['view', 'create', 'update', 'delete', 'approve', 'override_credit_check', 'override_stock_check'],
      },
      { key: 'deliveries', label: 'Deliveries', path: '/sales/deliveries', actions: ['view', 'create', 'update', 'delete', 'edit'] },
      { key: 'invoices', label: 'Invoices', path: '/sales/invoices', actions: ['view', 'create', 'update', 'delete', 'approve', 'import', 'edit'] },
      { key: 'credit_notes', label: 'Credit Notes', path: '/sales/credit-notes', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'debit_notes', label: 'Debit Notes', path: '/sales/debit-notes', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'finance',
    label: 'Finance',
    icon: Wallet,
    pages: [
      { key: 'outgoing_payment', label: 'Payment Voucher', path: '/finance/outgoing', actions: ['view', 'create', 'update', 'delete', 'import'] },
      { key: 'incoming_payment', label: 'Official Receipt', path: '/finance/incoming', actions: ['view', 'create', 'update', 'delete', 'import'] },
      {
        key: 'journal_entries',
        label: 'General Journal',
        path: '/finance/general-journal',
        actions: ['view', 'create', 'update', 'delete', 'approve'],
        permissionGroup: 'accounting',
      },
      { key: 'chart_of_accounts', label: 'Chart of Accounts', path: '/finance/chart-of-accounts', actions: ['view', 'create', 'update', 'delete', 'import'], permissionGroup: 'master' },
      { key: 'bank_reconciliation', label: 'Bank Reconciliation', path: '/finance/bank-reconciliation', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'reports',
    label: 'Reports',
    icon: BarChart3,
    pages: [
      { key: 'purchase', label: 'Purchase', path: '/reports/purchase', actions: ['view', 'import'] },
      { key: 'goods_receipts', label: 'Goods Receipt', path: '/reports/goods-receipts', actions: ['view'] },
      { key: 'sales', label: 'Sales', path: '/reports/sales', actions: ['view'] },
      // Split out of Sales Report's old Margin tab (2026-09-19) — its own reports.gross_profit.view
      // permission (Margin never had one of its own; it rode on reports.sales.view). See
      // RolePermissionSeeder's backfill block for how existing roles keep access.
      { key: 'gross_profit', label: 'Gross Profit', path: '/reports/gross-profit', actions: ['view'] },
      { key: 'deliveries', label: 'Delivery', path: '/reports/deliveries', actions: ['view'] },
      { key: 'driver_commission', label: "Driver's Commission", path: '/reports/driver-commission', actions: ['view'] },
      { key: 'inventory_stock', label: 'Inventory Stock', path: '/reports/inventory-stock', actions: ['view'] },
      // "Customer Outstanding Bills" (imported legacy AR export archive, not connected to live
      // data) lives as a view-mode tab INSIDE this page, not its own navTree entry -- see
      // AccountsReceivableDetailReportPage's ViewMode union.
      { key: 'ar_detail', label: 'AR Detail', path: '/reports/ar-detail', actions: ['view'] },
      { key: 'ap_detail', label: 'AP Detail', path: '/reports/ap-detail', actions: ['view'] },
      // permissionGroup keeps this tab gated on the same accounting.general_ledger.view that
      // already governs the content it leads to — see the `accounting` group below.
      { key: 'general_ledger', label: 'General Ledger', path: '/reports/general-ledger', actions: ['view'], permissionGroup: 'accounting' },
      { key: 'tax', label: 'Tax', path: '/reports/tax', actions: ['view'] },
    ],
  },
  {
    key: 'accounting',
    label: 'Accounting Reports',
    icon: BookOpen,
    // Not a level-1 sidebar section — reached via Reports' "General Ledger" tab. Journal
    // Entries itself moved out to the `finance` group above (permissionGroup: 'accounting'
    // keeps its permission string unchanged); these 7 remaining pages keep their own
    // `accounting.*` permission strings untouched too (see 2026-08-09/10 revert history) —
    // only their `path` values moved from /finance/general-journal/* to /reports/general-ledger/*.
    //
    // MUST stay listed after `reports` above: Breadcrumbs.tsx's prefix-match fallback picks
    // the first navTree group with any page path starting with `/{segment}/`, and every page
    // here shares the `/reports/` prefix with the `reports` group — same reason `finance` has
    // to stay listed before this group too (their old shared prefix was `/finance/`).
    hideFromSidebar: true,
    pages: [
      { key: 'general_ledger', label: 'Print Ledger', path: '/reports/general-ledger', actions: ['view', 'import'] },
      { key: 'journal_list', label: 'Journal List', path: '/reports/general-ledger/journal-list', actions: ['view', 'import'] },
      { key: 'trial_balance', label: 'Trial Balance', path: '/reports/general-ledger/trial-balance', actions: ['view', 'import'] },
      { key: 'profit_loss', label: 'Income Statement', path: '/reports/general-ledger/income-statement', actions: ['view', 'import'] },
      { key: 'balance_sheet', label: 'Balance Sheet', path: '/reports/general-ledger/balance-sheet', actions: ['view', 'import'] },
      { key: 'cash_flow', label: 'Cash Flow', path: '/reports/general-ledger/cash-flow', actions: ['view'] },
      { key: 'period_closing', label: 'Period Closing', path: '/reports/general-ledger/period-closing', actions: ['view', 'create', 'update'] },
    ],
  },
  {
    key: 'administration',
    label: 'Administration',
    icon: Settings,
    pages: [
      { key: 'company', label: 'Company', path: '/administration/company', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'branch', label: 'Branches', path: '/administration/branches', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'users', label: 'Users', path: '/administration/users', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'roles', label: 'Roles & Permissions', path: '/administration/roles', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'naming_series', label: 'Naming Series', path: '/administration/naming-series', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'purchase_settings', label: 'Purchase Settings', path: '/administration/purchase-settings', actions: ['view', 'update'] },
      { key: 'audit_log', label: 'Audit Log', path: '/administration/audit-log', actions: ['view'] },
    ],
  },
]

/** Dashboard's own sidebar entry — not a navTree group (no siblings, no SectionNav, no per-page permission). */
export const DASHBOARD_NAV = { label: 'Dashboard', path: '/dashboard', icon: LayoutDashboard }

/**
 * Permissions that exist but don't belong to any single nav page — surfaced
 * as a flat "Other" section in the Role Management permission tree, never
 * in the sidebar/breadcrumb/router since nothing links to them directly.
 * See docs/PERMISSION_MODEL_DESIGN.md for why each one lives where it does.
 */
export interface ExtraPermission {
  key: string
  label: string
  group: string
  actions: Action[]
}

export const extraPermissions: ExtraPermission[] = [
  { key: 'accounts_payable', label: 'Accounts Payable', group: 'finance', actions: ['view'] },
  { key: 'accounts_receivable', label: 'Accounts Receivable', group: 'finance', actions: ['view'] },
  { key: 'payment_allocation', label: 'Payment Allocation (AR)', group: 'finance', actions: ['create', 'update'] },
  // AP-side mirror of Payment Allocation above — a genuinely separate permission, not the same one
  // under a different name (see routes/api.php payment-entries/{paymentEntry}/allocate).
  { key: 'ap_payment_allocation', label: 'Payment Allocation (AP)', group: 'finance', actions: ['create', 'update'] },
  { key: 'document_attachment', label: 'Document Attachment', group: 'system', actions: ['view', 'create', 'delete'] },
  { key: 'document_timeline', label: 'Document Timeline', group: 'system', actions: ['view'] },
  { key: 'currencies', label: 'Currencies', group: 'master', actions: ['view', 'create', 'update', 'delete'] },
  // Neither a CRUD page of its own — see RolePermissionSeeder's own comment on this
  // singular/plural inconsistency (CRUD lives on master.terms_of_payment, Import on
  // master.terms_of_payments).
  { key: 'terms_of_payments', label: 'Terms of Payment Import', group: 'master', actions: ['import'] },
  // Item Prices page's second import action (see RolePermissionSeeder), no CRUD page of its own.
  { key: 'item_standard_rates', label: 'Item Standard Rates Import', group: 'master', actions: ['import'] },
  // Admin trial-and-error of another user's Print Options — see navTree's own comment on
  // administration.print_settings in routes/api.php; not a nav page of its own.
  { key: 'print_settings', label: 'Print Settings (Admin)', group: 'administration', actions: ['view', 'update'] },
  // View-mode tabs INSIDE the AR/AP Detail and Sales report pages (imported legacy archive
  // snapshots), not their own navTree pages — see those pages' ViewMode unions.
  { key: 'ar_archive', label: 'Customer Outstanding Bills Archive', group: 'reports', actions: ['view', 'import'] },
  { key: 'ap_archive', label: 'Supplier Outstanding Bills Archive', group: 'reports', actions: ['view', 'import'] },
  { key: 'sales_archive', label: 'Sales Archive', group: 'reports', actions: ['view', 'import'] },
  // Sales > Invoices' checkbox print flow (Tanda Terima Invoice / Laporan Penagihan Harian) — gates
  // an OR-branch on an existing endpoint, not a page of its own.
  { key: 'tanda_terima_invoice', label: 'Tanda Terima Invoice (Print)', group: 'reports', actions: ['view'] },
  { key: 'penagihan_harian', label: 'Penagihan Harian (Print)', group: 'reports', actions: ['view'] },
]

/** dashboard.view is a genuinely flat, standalone permission — not `group.page.action` like everything
 *  else (Dashboard has no sibling pages to scope it against) — kept separate from extraPermissions
 *  rather than forced through permissionName(), which would wrongly produce "dashboard.dashboard.view". */
export const standalonePermissions: { key: string; label: string; name: string }[] = [
  { key: 'dashboard', label: 'Dashboard', name: 'dashboard.view' },
]

export function permissionName(group: string, page: string, action: Action): string {
  return `${group}.${page}.${action}`
}

export function findGroup(key: string): NavGroup | undefined {
  return navTree.find((group) => group.key === key)
}
