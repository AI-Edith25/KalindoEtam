import type { SectionNavItem } from '@/components/shared/SectionNav'

/** Sub-navigation shown at the top of the Purchase module's list pages — now two sibling document types. */
export const purchaseSectionNav: SectionNavItem[] = [
  { label: 'Orders', path: '/purchase/orders', permission: 'purchase_order.view' },
  { label: 'Goods Receipts', path: '/purchase/goods-receipts', permission: 'goods_receipt.view' },
]
