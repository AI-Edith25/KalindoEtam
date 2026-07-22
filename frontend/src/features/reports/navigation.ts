import type { SectionNavItem } from '@/components/shared/SectionNav'

export const reportsSectionNav: SectionNavItem[] = [
  { label: 'Purchase', path: '/reports/purchase', permission: 'purchase_order.view' },
  { label: 'Goods Receipt', path: '/reports/goods-receipts', permission: 'goods_receipt.view' },
  { label: 'Sales', path: '/reports/sales', permission: 'sales_order.view' },
  { label: 'Delivery', path: '/reports/deliveries', permission: 'delivery.view' },
  { label: 'Inventory Movement', path: '/reports/inventory-movement', permission: 'stock.view' },
  { label: 'Inventory Balance', path: '/reports/inventory-balance', permission: 'stock.view' },
]
