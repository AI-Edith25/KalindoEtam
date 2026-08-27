import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils'
import type { Item } from '../types'

interface ItemDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  item: Item | null
  onEdit: (item: Item) => void
}

/**
 * Built on the shared DetailDrawerLayout — the reference for every
 * module's detail view. Item has no lifecycle status of its own (it's
 * master data, not a workflow document), so the header badge stands in
 * for one using real stock data rather than a fabricated "Active".
 */
export function ItemDetailDrawer({ open, onOpenChange, item, onEdit }: ItemDetailDrawerProps) {
  if (!item) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={item.item_name}
      subtitle={item.item_code}
      badge={<StatusBadge status={item.current_stock > 0 ? 'in_stock' : 'out_of_stock'} />}
      primaryAction={{ label: 'Edit Item', icon: Pencil, onClick: () => onEdit(item) }}
    >
      <DetailSection>
        <DetailField label="Item Code" value={item.item_code} />
        <DetailField label="Item Name" value={item.item_name} />
        <DetailField label="Item Group" value={item.item_group?.name ?? '—'} />
        <DetailField label="UOM" value={item.uom ? `${item.uom.name}${item.uom.symbol ? ` (${item.uom.symbol})` : ''}` : '—'} />
        <DetailField label="Standard Rate" value={formatCurrency(item.standard_rate)} />
        <DetailField
          label="Current Stock"
          value={`${formatNumber(item.current_stock)} ${item.uom?.symbol ?? item.uom?.name ?? ''}`}
        />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(item.created_at)} />
        <DetailField label="Last Updated" value={formatDate(item.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
