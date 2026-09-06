import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/shared/PageHeader'
import { SectionNav } from '@/components/shared/SectionNav'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { StockBalancePanel } from '@/features/inventory/components/StockBalancePanel'
import { StockLedgerPanel } from '@/features/inventory/components/StockLedgerPanel'

type InventoryStockTab = 'balance' | 'ledger' | 'valuation'

const TABS: { value: InventoryStockTab; label: string; enabled: boolean }[] = [
  { value: 'balance', label: 'Balance', enabled: true },
  { value: 'ledger', label: 'Ledger', enabled: true },
  { value: 'valuation', label: 'Valuation', enabled: false },
]

/**
 * Reports > Inventory Stock — Stock Balance and Stock Ledger moved here as
 * tabs of one page (previously separate Inventory sub-nav pages); Valuation
 * is a placeholder for a page not yet built. Same button-row + `?tab=` URL
 * pattern as Accounting > Journal List (JournalListPage.tsx).
 */
export function InventoryStockReportPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = (searchParams.get('tab') as InventoryStockTab) || 'balance'

  const setTab = (next: InventoryStockTab) => {
    setSearchParams((prev) => {
      const params = new URLSearchParams(prev)
      if (next === 'balance') params.delete('tab')
      else params.set('tab', next)
      return params
    })
  }

  return (
    <div className="flex flex-col gap-4">
      <SectionNav group="reports" />

      <PageHeader title="Inventory Stock" description="Current on-hand quantity and every movement, across every item and warehouse." />

      <TooltipProvider>
        <div className="flex items-center gap-1 rounded-md border p-1">
          {TABS.map((option) =>
            option.enabled ? (
              <Button key={option.value} size="sm" variant={tab === option.value ? 'default' : 'ghost'} onClick={() => setTab(option.value)}>
                {option.label}
              </Button>
            ) : (
              <Tooltip key={option.value}>
                <TooltipTrigger asChild>
                  <span tabIndex={0}>
                    <Button size="sm" variant="ghost" disabled className="pointer-events-none">
                      {option.label}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent>Coming soon</TooltipContent>
              </Tooltip>
            ),
          )}
        </div>
      </TooltipProvider>

      {tab === 'ledger' ? <StockLedgerPanel /> : <StockBalancePanel />}
    </div>
  )
}
