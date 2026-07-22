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

export interface NavItem {
  label: string
  /** Representative path — breadcrumb label lookup and the fallback link target for items with no `children`. */
  path: string
  icon: LucideIcon
  /**
   * Ordered candidate destinations. The sidebar shows this item if the user holds at least one
   * of these permissions, and links to the *first* one they hold — parent groups are navigation
   * containers, not protected modules, so the group must never assume a fixed default child.
   * Omit for items with a single, always-available destination (e.g. Dashboard).
   */
  children?: { path: string; permission: string }[]
}

/**
 * "Administration" (docs/ADMINISTRATION_DESIGN.md) replaces the old dead
 * "Settings" entry, same sidebar position and item count — Company, Users,
 * Roles & Permissions, and Audit Log now have real pages under it.
 */
export const navItems: NavItem[] = [
  { label: 'Dashboard', path: '/dashboard', icon: LayoutDashboard },
  {
    label: 'Master Data',
    path: '/master/items',
    icon: Database,
    children: [
      { path: '/master/items', permission: 'item.view' },
      { path: '/master/suppliers', permission: 'supplier.view' },
      { path: '/master/customers', permission: 'customer.view' },
      { path: '/master/warehouses', permission: 'warehouse.view' },
      { path: '/master/item-groups', permission: 'item_group.view' },
      { path: '/master/uoms', permission: 'uom.view' },
      { path: '/master/chart-of-accounts', permission: 'chart_of_account.view' },
      { path: '/master/taxes', permission: 'tax.view' },
    ],
  },
  {
    label: 'Inventory',
    path: '/inventory/stock-balance',
    icon: Warehouse,
    children: [{ path: '/inventory/stock-balance', permission: 'stock.view' }],
  },
  {
    label: 'Purchase',
    path: '/purchase/orders',
    icon: ShoppingCart,
    children: [
      { path: '/purchase/orders', permission: 'purchase_order.view' },
      { path: '/purchase/goods-receipts', permission: 'goods_receipt.view' },
    ],
  },
  {
    label: 'Sales',
    path: '/sales/orders',
    icon: Receipt,
    children: [
      { path: '/sales/orders', permission: 'sales_order.view' },
      { path: '/sales/deliveries', permission: 'delivery.view' },
      { path: '/sales/invoices', permission: 'invoice.view' },
      { path: '/sales/credit-notes', permission: 'credit_note.view' },
      { path: '/sales/debit-notes', permission: 'debit_note.view' },
    ],
  },
  {
    label: 'Finance',
    path: '/finance/outgoing',
    icon: Wallet,
    children: [
      { path: '/finance/outgoing', permission: 'payment_entry.view' },
      { path: '/finance/incoming', permission: 'receipt_entry.view' },
    ],
  },
  {
    label: 'Accounting Reports',
    path: '/accounting/journal-entries',
    icon: BookOpen,
    children: [{ path: '/accounting/journal-entries', permission: 'journal_entry.view' }],
  },
  {
    label: 'Reports',
    path: '/reports/purchase',
    icon: BarChart3,
    children: [
      { path: '/reports/purchase', permission: 'purchase_order.view' },
      { path: '/reports/goods-receipts', permission: 'goods_receipt.view' },
      { path: '/reports/sales', permission: 'sales_order.view' },
      { path: '/reports/deliveries', permission: 'delivery.view' },
      { path: '/reports/inventory-movement', permission: 'stock.view' },
    ],
  },
  {
    label: 'Administration',
    path: '/administration/company',
    icon: Settings,
    children: [
      { path: '/administration/company', permission: 'company.view' },
      { path: '/administration/users', permission: 'user.view' },
      { path: '/administration/roles', permission: 'role.view' },
      { path: '/administration/audit-log', permission: 'audit_log.view' },
    ],
  },
]
