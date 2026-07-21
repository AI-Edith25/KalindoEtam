import type { SectionNavItem } from '@/components/shared/SectionNav'

/** Sub-navigation shown at the top of every Master Data page. */
export const masterDataNav: SectionNavItem[] = [
  { label: 'Items', path: '/master/items', permission: 'item.view' },
  { label: 'Suppliers', path: '/master/suppliers', permission: 'supplier.view' },
  { label: 'Customers', path: '/master/customers', permission: 'customer.view' },
  { label: 'Warehouses', path: '/master/warehouses', permission: 'warehouse.view' },
  { label: 'Item Groups', path: '/master/item-groups', permission: 'item_group.view' },
  { label: 'UOMs', path: '/master/uoms', permission: 'uom.view' },
  { label: 'Chart of Accounts', path: '/master/chart-of-accounts', permission: 'chart_of_account.view' },
  { label: 'Taxes', path: '/master/taxes', permission: 'tax.view' },
]
