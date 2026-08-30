import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { CashBookPanel } from '../components/CashBookPanel'
import { GeneralJournalPanel } from '../components/GeneralJournalPanel'
import { SalesJournalPanel } from '../components/SalesJournalPanel'
import { PurchaseJournalPanel } from '../components/PurchaseJournalPanel'
import type {
  CashBookFilterValues,
  CashBookView,
  JournalEntryFilterValues,
  PurchaseJournalFilterValues,
  PurchaseJournalView,
  SalesJournalFilterValues,
  SalesJournalView,
} from '../types'

type JournalKey = 'cashbook' | 'general-journal' | 'sales-journal' | 'purchase-journal'

const JOURNALS: { value: JournalKey; label: string; enabled: boolean }[] = [
  { value: 'cashbook', label: 'Cash Book', enabled: true },
  { value: 'general-journal', label: 'General Journal', enabled: true },
  { value: 'sales-journal', label: 'Sales Journal', enabled: true },
  { value: 'purchase-journal', label: 'Purchase Journal', enabled: true },
]

/**
 * Journal List — 4 journals (Cash Book, General Journal, Sales Journal,
 * Purchase Journal), each its own paginated + exportable read-only report.
 * All 4 are built — Sales/Purchase Journal each carry an inner sub-tab
 * (Sales Invoice/Credit Note, Purchase Invoice/Purchase Return) via the same
 * `view` URL slot Cash Book already established, owned by their own Panel
 * component exactly the way Cash Book's own "all/receipt/payment" toggle is.
 *
 * Every bit of state that should survive a refresh or be shareable — active
 * journal, the active sub-view, filters, page — lives in the URL, read and
 * written directly via useSearchParams (this is the only page with this
 * need, so no shared "useUrlState" abstraction).
 */
export function JournalListPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  const journal = (searchParams.get('journal') as JournalKey) || 'cashbook'
  const rawView = searchParams.get('view')
  const view = (rawView as CashBookView) || 'all'
  const salesJournalView = (rawView as SalesJournalView) || 'invoice'
  const purchaseJournalView = (rawView as PurchaseJournalView) || 'purchase_invoice'
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

  const setJournal = (next: JournalKey) => update({ journal: next === 'cashbook' ? null : next, view: null, page: null })
  const setView = (next: CashBookView) => update({ view: next === 'all' ? null : next, page: null })
  const setSalesJournalView = (next: SalesJournalView) => update({ view: next === 'invoice' ? null : next, page: null })
  const setPurchaseJournalView = (next: PurchaseJournalView) => update({ view: next === 'purchase_invoice' ? null : next, page: null })
  const setSearch = (next: string) => update({ search: next || null })
  const setPage = (next: number) => update({ page: next > 1 ? String(next) : null })

  const setCashBookFilters = (next: CashBookFilterValues) =>
    update({ branch_id: next.branchId, status: next.status, date_from: next.dateFrom || null, date_to: next.dateTo || null })

  const setGeneralJournalFilters = (next: JournalEntryFilterValues) =>
    update({
      branch_id: next.branchId,
      status: next.status,
      reference_type: next.referenceType,
      account_id: next.accountId,
      date_from: next.dateFrom || null,
      date_to: next.dateTo || null,
    })

  const setSalesJournalFilters = (next: SalesJournalFilterValues) =>
    update({ branch_id: next.branchId, date_from: next.dateFrom || null, date_to: next.dateTo || null })

  const setPurchaseJournalFilters = (next: PurchaseJournalFilterValues) =>
    update({ date_from: next.dateFrom || null, date_to: next.dateTo || null })

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="accounting" />

      <PageHeader
        title="Journal List"
        description="Every posted journal, by source — read-only reports derived from posted Journal Entries."
      />

      <div className="flex items-center gap-1 rounded-md border p-1">
        {JOURNALS.map((option) => (
          <Button
            key={option.value}
            size="sm"
            variant={journal === option.value ? 'default' : 'ghost'}
            disabled={!option.enabled}
            onClick={() => setJournal(option.value)}
          >
            {option.label}
          </Button>
        ))}
      </div>

      {journal === 'general-journal' ? (
        <GeneralJournalPanel
          search={search}
          onSearchChange={setSearch}
          filters={generalJournalFilters}
          onFiltersChange={setGeneralJournalFilters}
          page={page}
          onPageChange={setPage}
        />
      ) : journal === 'sales-journal' ? (
        <SalesJournalPanel
          view={salesJournalView}
          onViewChange={setSalesJournalView}
          search={search}
          onSearchChange={setSearch}
          filters={salesJournalFilters}
          onFiltersChange={setSalesJournalFilters}
          page={page}
          onPageChange={setPage}
        />
      ) : journal === 'purchase-journal' ? (
        <PurchaseJournalPanel
          view={purchaseJournalView}
          onViewChange={setPurchaseJournalView}
          search={search}
          onSearchChange={setSearch}
          filters={purchaseJournalFilters}
          onFiltersChange={setPurchaseJournalFilters}
          page={page}
          onPageChange={setPage}
        />
      ) : (
        <CashBookPanel
          view={view}
          onViewChange={setView}
          search={search}
          onSearchChange={setSearch}
          filters={cashBookFilters}
          onFiltersChange={setCashBookFilters}
          page={page}
          onPageChange={setPage}
        />
      )}
    </div>
  )
}
