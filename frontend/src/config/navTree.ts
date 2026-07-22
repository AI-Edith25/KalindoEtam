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

export type Action = 'view' | 'create' | 'update' | 'delete' | 'approve'

export interface NavPage {
  /** Unique within its group — combines with the group key to form the permission name. */
  key: string
  label: string
  path: string
  /** Which actions this page actually has permissions for — drives the Role Management UI's columns per page. */
  actions: Action[]
}

export interface NavGroup {
  key: string
  label: string
  icon: LucideIcon
  pages: NavPage[]
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
    label: 'Master Data',
    icon: Database,
    pages: [
      { key: 'items', label: 'Items', path: '/master/items', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'suppliers', label: 'Suppliers', path: '/master/suppliers', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'customers', label: 'Customers', path: '/master/customers', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'warehouses', label: 'Warehouses', path: '/master/warehouses', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'item_groups', label: 'Item Groups', path: '/master/item-groups', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'uoms', label: 'UOMs', path: '/master/uoms', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'chart_of_accounts', label: 'Chart of Accounts', path: '/master/chart-of-accounts', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'taxes', label: 'Taxes', path: '/master/taxes', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'inventory',
    label: 'Inventory',
    icon: Warehouse,
    pages: [
      { key: 'stock_balance', label: 'Stock Balance', path: '/inventory/stock-balance', actions: ['view'] },
      { key: 'stock_ledger', label: 'Stock Ledger', path: '/inventory/stock-ledger', actions: ['view', 'create'] },
      { key: 'adjustments', label: 'Adjustments', path: '/inventory/adjustments', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'purchase',
    label: 'Purchase',
    icon: ShoppingCart,
    pages: [
      { key: 'orders', label: 'Orders', path: '/purchase/orders', actions: ['view', 'create', 'update', 'delete', 'approve'] },
      { key: 'goods_receipts', label: 'Goods Receipts', path: '/purchase/goods-receipts', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'sales',
    label: 'Sales',
    icon: Receipt,
    pages: [
      { key: 'orders', label: 'Orders', path: '/sales/orders', actions: ['view', 'create', 'update', 'delete', 'approve'] },
      { key: 'deliveries', label: 'Deliveries', path: '/sales/deliveries', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'invoices', label: 'Invoices', path: '/sales/invoices', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'credit_notes', label: 'Credit Notes', path: '/sales/credit-notes', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'debit_notes', label: 'Debit Notes', path: '/sales/debit-notes', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'finance',
    label: 'Finance',
    icon: Wallet,
    pages: [
      { key: 'outgoing_payment', label: 'Outgoing Payment', path: '/finance/outgoing', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'incoming_payment', label: 'Incoming Payment', path: '/finance/incoming', actions: ['view', 'create', 'update', 'delete'] },
    ],
  },
  {
    key: 'accounting',
    label: 'Accounting Reports',
    icon: BookOpen,
    pages: [
      { key: 'journal_entries', label: 'Journal Entries', path: '/accounting/journal-entries', actions: ['view', 'create', 'update', 'delete', 'approve'] },
      { key: 'general_ledger', label: 'General Ledger', path: '/accounting/general-ledger', actions: ['view'] },
      { key: 'trial_balance', label: 'Trial Balance', path: '/accounting/trial-balance', actions: ['view'] },
      { key: 'profit_loss', label: 'Profit & Loss', path: '/accounting/profit-loss', actions: ['view'] },
      { key: 'balance_sheet', label: 'Balance Sheet', path: '/accounting/balance-sheet', actions: ['view'] },
      { key: 'cash_flow', label: 'Cash Flow', path: '/accounting/cash-flow', actions: ['view'] },
      { key: 'period_closing', label: 'Period Closing', path: '/accounting/period-closing', actions: ['view', 'create', 'update'] },
    ],
  },
  {
    key: 'reports',
    label: 'Reports',
    icon: BarChart3,
    pages: [
      { key: 'purchase', label: 'Purchase', path: '/reports/purchase', actions: ['view'] },
      { key: 'goods_receipts', label: 'Goods Receipt', path: '/reports/goods-receipts', actions: ['view'] },
      { key: 'sales', label: 'Sales', path: '/reports/sales', actions: ['view'] },
      { key: 'deliveries', label: 'Delivery', path: '/reports/deliveries', actions: ['view'] },
      { key: 'inventory_movement', label: 'Inventory Movement', path: '/reports/inventory-movement', actions: ['view'] },
      { key: 'inventory_balance', label: 'Inventory Balance', path: '/reports/inventory-balance', actions: ['view'] },
    ],
  },
  {
    key: 'administration',
    label: 'Administration',
    icon: Settings,
    pages: [
      { key: 'company', label: 'Company', path: '/administration/company', actions: ['view', 'create', 'update', 'delete'] },
      { key: 'users', label: 'Users', path: '/administration/users', actions: ['view', 'create', 'update'] },
      { key: 'roles', label: 'Roles & Permissions', path: '/administration/roles', actions: ['view', 'create', 'update', 'delete'] },
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
  { key: 'payment_allocation', label: 'Payment Allocation', group: 'finance', actions: ['create', 'update'] },
  { key: 'document_attachment', label: 'Document Attachment', group: 'system', actions: ['view', 'create', 'delete'] },
  { key: 'document_timeline', label: 'Document Timeline', group: 'system', actions: ['view'] },
  { key: 'naming_series', label: 'Naming Series', group: 'administration', actions: ['view', 'create', 'update', 'delete'] },
  { key: 'branch', label: 'Branch', group: 'administration', actions: ['view', 'create', 'update', 'delete'] },
  { key: 'currencies', label: 'Currencies', group: 'master', actions: ['view', 'create', 'update', 'delete'] },
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
