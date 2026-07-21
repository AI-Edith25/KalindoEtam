import type { SectionNavItem } from '@/components/shared/SectionNav'

export const financeSectionNav: SectionNavItem[] = [
  { label: 'Incoming Payment', path: '/finance/incoming', permission: 'receipt_entry.view' },
  { label: 'Outgoing Payment', path: '/finance/outgoing', permission: 'payment_entry.view' },
]
