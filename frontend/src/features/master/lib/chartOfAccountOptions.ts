import type { ChartOfAccount } from '../types'

/** A Group/header account (has children) carries no postings of its own — Chart of Accounts
 *  enforces this structurally server-side (see JournalEntryService::assertSubledgerRequirement()),
 *  this is just the single shared place every account picker checks before the user can pick one. */
export const isHeaderAccount = (account: Pick<ChartOfAccount, 'children_count'>) => (account.children_count ?? 0) > 0

/** Shared by every Chart of Account picker (General Journal, Payment Voucher, Official Receipt, …)
 *  so "disable a header/parent account" never drifts out of sync between forms. */
export const toChartOfAccountOptions = (accounts: ChartOfAccount[] | undefined) =>
  (accounts ?? []).map((account) => {
    const isGroup = isHeaderAccount(account)
    return { value: account.id, label: `${account.code} — ${account.name}${isGroup ? ' (Group)' : ''}`, disabled: isGroup }
  })
