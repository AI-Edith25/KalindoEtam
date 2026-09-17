import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { CashBookPanel } from '../components/CashBookPanel'
import { GeneralJournalPanel } from '../components/GeneralJournalPanel'
import { SalesJournalPanel } from '../components/SalesJournalPanel'
import { PurchaseJournalPanel } from '../components/PurchaseJournalPanel'
import { JournalTypeSelect } from '../components/JournalTypeSelect'
import { DEFAULT_JOURNAL_LIST_TYPE, type JournalListType } from '../lib/journalListType'
import type { CashBookFilterValues, JournalEntryFilterValues, PurchaseJournalFilterValues, SalesJournalFilterValues } from '../types'

/**
 * Journal List — one flat "Journal Type" dropdown (in the filter row, not a
 * tab) picks one of 8 combinations that used to be a 2-level pill-tab +
 * sub-tab (Cash Book/General Journal/Sales Journal/Purchase Journal, the
 * first/third/fourth each with their own inner view toggle). Each panel's
 * own data/columns/filters are unchanged — journalType is split back into
 * the same (journal, view) pair the old tabs produced, below.
 *
 * Every bit of state that should survive a refresh or be shareable —
 * journal type, filters, page — lives in the URL, read and written directly
 * via useSearchParams (this is the only page with this need, so no shared
 * "useUrlState" abstraction).
 */
export function JournalListPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const journalType = (searchParams.get('type') as JournalListType) || DEFAULT_JOURNAL_LIST_TYPE
  const search = searchParams.get('search') ?? ''
  const page = Number(searchParams.get('page') ?? '1')

  const cashBookFilters: CashBookFilterValues = {
    branchId: searchParams.get('branch_id'),
    status: searchParams.get('status'),
    dateFrom: searchParams.get('date_from') ?? '',
    dateTo: searchParams.get('date_to') ?? '',
  }

  const generalJournalFilters: JournalEntryFilterValues = {
    status: searchParams.get('status') as JournalEntryFilterValues['status'],
    referenceType: searchParams.get('reference_type'),
    accountId: searchParams.get('account_id'),
    branchId: searchParams.get('branch_id'),
    dateFrom: searchParams.get('date_from') ?? '',
    dateTo: searchParams.get('date_to') ?? '',
  }

  const salesJournalFilters: SalesJournalFilterValues = {
    branchId: searchParams.get('branch_id'),
    dateFrom: searchParams.get('date_from') ?? '',
    dateTo: searchParams.get('date_to') ?? '',
  }

  const purchaseJournalFilters: PurchaseJournalFilterValues = {
    dateFrom: searchParams.get('date_from') ?? '',
    dateTo: searchParams.get('date_to') ?? '',
  }

  const update = (patch: Record<string, string | null>) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev)
      for (const [key, value] of Object.entries(patch)) {
        if (value === null || value === '') next.delete(key)
        else next.set(key, value)
      }
      return next
    })
  }

  const setJournalType = (next: JournalListType) => update({ type: next === DEFAULT_JOURNAL_LIST_TYPE ? null : next, page: null })
  const setSearch = (next: string) => update({ search: next || null, page: null })
  const setPage = (next: number) => update({ page: next > 1 ? String(next) : null })

  // page:null is merged into the same update() call as the filter patch — react-router's
  // setSearchParams builds the next URL from a render-time snapshot, so a second, separate
  // setSearchParams call (e.g. a trailing onPageChange(1)) would clobber this one instead of
  // composing with it. Callers must not pair these with their own page reset.
  const setCashBookFilters = (next: CashBookFilterValues) =>
    update({ branch_id: next.branchId, status: next.status, date_from: next.dateFrom || null, date_to: next.dateTo || null, page: null })

  const setGeneralJournalFilters = (next: JournalEntryFilterValues) =>
    update({
      branch_id: next.branchId,
      status: next.status,
      reference_type: next.referenceType,
      account_id: next.accountId,
      date_from: next.dateFrom || null,
      date_to: next.dateTo || null,
      page: null,
    })

  const setSalesJournalFilters = (next: SalesJournalFilterValues) =>
    update({ branch_id: next.branchId, date_from: next.dateFrom || null, date_to: next.dateTo || null, page: null })

  const setPurchaseJournalFilters = (next: PurchaseJournalFilterValues) =>
    update({ date_from: next.dateFrom || null, date_to: next.dateTo || null, page: null })

  const journalTypeSelect = <JournalTypeSelect value={journalType} onChange={setJournalType} />

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader
        title="Journal List"
        description="Every posted journal, by source — read-only reports derived from posted Journal Entries."
      />

      <SectionNav group="accounting" variant="pills" end />

      {journalType === 'general_journal' ? (
        <GeneralJournalPanel
          search={search}
          onSearchChange={setSearch}
          filters={generalJournalFilters}
          onFiltersChange={setGeneralJournalFilters}
          page={page}
          onPageChange={setPage}
          journalTypeSelect={journalTypeSelect}
        />
      ) : journalType === 'sales_invoice' || journalType === 'sales_credit_note' ? (
        <SalesJournalPanel
          view={journalType === 'sales_credit_note' ? 'credit_note' : 'invoice'}
          search={search}
          onSearchChange={setSearch}
          filters={salesJournalFilters}
          onFiltersChange={setSalesJournalFilters}
          page={page}
          onPageChange={setPage}
          journalTypeSelect={journalTypeSelect}
        />
      ) : journalType === 'purchase_invoice' || journalType === 'purchase_return' ? (
        <PurchaseJournalPanel
          view={journalType === 'purchase_return' ? 'purchase_return' : 'purchase_invoice'}
          search={search}
          onSearchChange={setSearch}
          filters={purchaseJournalFilters}
          onFiltersChange={setPurchaseJournalFilters}
          page={page}
          onPageChange={setPage}
          journalTypeSelect={journalTypeSelect}
        />
      ) : (
        <CashBookPanel
          view={journalType === 'cashbook_receipt' ? 'receipt' : journalType === 'cashbook_payment' ? 'payment' : 'all'}
          search={search}
          onSearchChange={setSearch}
          filters={cashBookFilters}
          onFiltersChange={setCashBookFilters}
          page={page}
          onPageChange={setPage}
          journalTypeSelect={journalTypeSelect}
        />
      )}
    </div>
  )
}
