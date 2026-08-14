import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { formatDate, formatNumber } from '@/lib/utils'
import { CHARGE_TYPE_LABELS } from '../pages/MiscellaneousItemListPage'
import type { MiscellaneousItem } from '../types'

interface MiscellaneousItemDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  miscellaneousItem: MiscellaneousItem | null
  onEdit: (miscellaneousItem: MiscellaneousItem) => void
}

export function MiscellaneousItemDetailDrawer({ open, onOpenChange, miscellaneousItem, onEdit }: MiscellaneousItemDetailDrawerProps) {
  if (!miscellaneousItem) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={miscellaneousItem.misc_code}
      subtitle={miscellaneousItem.description}
      primaryAction={{ label: 'Edit Miscellaneous Item', icon: Pencil, onClick: () => onEdit(miscellaneousItem) }}
    >
      <DetailSection>
        <DetailField label="Misc Code" value={miscellaneousItem.misc_code} />
        <DetailField label="Description" value={miscellaneousItem.description} />
        <DetailField label="Rate" value={formatNumber(Number(miscellaneousItem.rate))} />
        <DetailField
          label="UOM"
          value={miscellaneousItem.uom ? `${miscellaneousItem.uom.name}${miscellaneousItem.uom.symbol ? ` (${miscellaneousItem.uom.symbol})` : ''}` : '—'}
        />
        <DetailField label="Charge Type" value={CHARGE_TYPE_LABELS[miscellaneousItem.charge_type]} />
        <DetailField label="Unit Cost" value={formatNumber(Number(miscellaneousItem.unit_cost))} />
        <DetailField
          label="Sales Account"
          value={miscellaneousItem.sales_account ? `${miscellaneousItem.sales_account.code} — ${miscellaneousItem.sales_account.name}` : '—'}
        />
        <DetailField
          label="Purchase Account"
          value={miscellaneousItem.purchase_account ? `${miscellaneousItem.purchase_account.code} — ${miscellaneousItem.purchase_account.name}` : '—'}
        />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(miscellaneousItem.created_at)} />
        <DetailField label="Last Updated" value={formatDate(miscellaneousItem.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
