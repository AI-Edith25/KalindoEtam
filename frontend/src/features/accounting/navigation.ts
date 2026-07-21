import type { SectionNavItem } from '@/components/shared/SectionNav'

/** Sub-navigation shown at the top of the Accounting module's pages — mirrors salesSectionNav's growth pattern. */
export const accountingSectionNav: SectionNavItem[] = [
  { label: 'Journal Entries', path: '/accounting/journal-entries', permission: 'journal_entry.view' },
  { label: 'General Ledger', path: '/accounting/general-ledger', permission: 'journal_entry.view' },
  { label: 'Trial Balance', path: '/accounting/trial-balance', permission: 'journal_entry.view' },
  { label: 'Profit & Loss', path: '/accounting/profit-loss', permission: 'journal_entry.view' },
  { label: 'Balance Sheet', path: '/accounting/balance-sheet', permission: 'journal_entry.view' },
  { label: 'Cash Flow', path: '/accounting/cash-flow', permission: 'journal_entry.view' },
  { label: 'Period Closing', path: '/accounting/period-closing', permission: 'journal_entry.view' },
]
