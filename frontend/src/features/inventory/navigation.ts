import type { SectionNavItem } from '@/components/shared/SectionNav'

/** All three Inventory pages ship in this sprint, so SectionNav is added from day one — unlike Purchase/Sales, where it was retrofitted once a second sibling document type arrived later. */
export const inventorySectionNav: SectionNavItem[] = [
  { label: 'Stock Balance', path: '/inventory/stock-balance', permission: 'stock.view' },
  { label: 'Stock Ledger', path: '/inventory/stock-ledger', permission: 'stock.view' },
  { label: 'Adjustments', path: '/inventory/adjustments', permission: 'stock.view' },
]
