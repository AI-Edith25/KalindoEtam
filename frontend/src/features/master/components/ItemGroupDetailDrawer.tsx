import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { formatDate } from '@/lib/utils'
import type { ItemGroup } from '../types'

interface ItemGroupDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  itemGroup: ItemGroup | null
  onEdit: (itemGroup: ItemGroup) => void
}

/** No header badge — Item Group has no status-like concept worth a placeholder (unlike Item's stock or Supplier's is_active). */
export function ItemGroupDetailDrawer({ open, onOpenChange, itemGroup, onEdit }: ItemGroupDetailDrawerProps) {
  if (!itemGroup) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={itemGroup.name}
      primaryAction={{ label: 'Edit Item Group', icon: Pencil, onClick: () => onEdit(itemGroup) }}
    >
      <DetailSection>
        <DetailField label="Name" value={itemGroup.name} />
        <DetailField label="Description" value={itemGroup.description ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(itemGroup.created_at)} />
        <DetailField label="Last Updated" value={formatDate(itemGroup.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
