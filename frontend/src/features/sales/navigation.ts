import type { SectionNavItem } from '@/components/shared/SectionNav'

/** Sub-navigation shown at the top of the Sales module's list pages — now two sibling document types, mirroring purchaseSectionNav. */
export const salesSectionNav: SectionNavItem[] = [
  { label: 'Orders', path: '/sales/orders', permission: 'sales_order.view' },
  { label: 'Deliveries', path: '/sales/deliveries', permission: 'delivery.view' },
  { label: 'Invoices', path: '/sales/invoices', permission: 'invoice.view' },
  { label: 'Credit Notes', path: '/sales/credit-notes', permission: 'credit_note.view' },
  { label: 'Debit Notes', path: '/sales/debit-notes', permission: 'debit_note.view' },
]
