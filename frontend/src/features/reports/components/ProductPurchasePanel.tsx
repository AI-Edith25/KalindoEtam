import { useState } from 'react'
import { Upload } from 'lucide-react'
import { ActionBar } from '@/components/shared/ActionBar'
import { Button } from '@/components/ui/button'
import { useHasPermission } from '@/shared/hooks/usePermission'
import { PurchaseHistoryImportDialog } from './PurchaseHistoryImportDialog'
import { PurchaseBySupplierPanel } from './PurchaseBySupplierPanel'
import { PurchaseByItemPanel } from './PurchaseByItemPanel'
import type { PurchaseReportFilterValues } from '../types'

interface ProductPurchasePanelProps {
  filters: PurchaseReportFilterValues
  onFiltersChange: (filters: PurchaseReportFilterValues) => void
  page: number
  onPageChange: (page: number) => void
}

type ProductPurchaseView = 'supplier' | 'item'

/**
 * Purchase Report's "Product Purchase" tab — By Supplier and By Item stay two fully independent
 * reports (different backend endpoints/row shapes, each with its own KPIs/filters/export) — this
 * is a navigation-level merge only, per the user's own request: co-locate them under one tab with
 * a view switch, same pill-toggle pattern ProductSalesPanel already uses for its item/item_group
 * switch. Owns this tab's own Import button (Product Purchase Report / File B) — shown regardless
 * of which view is active, since By Supplier never receives import data either way.
 */
export function ProductPurchasePanel({ filters, onFiltersChange, page, onPageChange }: ProductPurchasePanelProps) {
  const canImport = useHasPermission('reports.purchase.import')
  const [view, setView] = useState<ProductPurchaseView>('supplier')
  const [importDialogOpen, setImportDialogOpen] = useState(false)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-1 rounded-md border p-1">
          <Button size="sm" variant={view === 'supplier' ? 'default' : 'ghost'} onClick={() => setView('supplier')}>
            Per Supplier
          </Button>
          <Button size="sm" variant={view === 'item' ? 'default' : 'ghost'} onClick={() => setView('item')}>
            Per Item
          </Button>
        </div>
        <ActionBar actions={[{ label: 'Import', icon: Upload, disabled: !canImport, onClick: () => setImportDialogOpen(true) }]} />
      </div>

      <PurchaseHistoryImportDialog open={importDialogOpen} expectedType="product_purchase_report" onClose={() => setImportDialogOpen(false)} />

      {view === 'supplier' ? (
        <PurchaseBySupplierPanel filters={filters} onFiltersChange={onFiltersChange} page={page} onPageChange={onPageChange} />
      ) : (
        <PurchaseByItemPanel filters={filters} onFiltersChange={onFiltersChange} page={page} onPageChange={onPageChange} />
      )}
    </div>
  )
}
