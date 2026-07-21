import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { formatDate } from '@/lib/utils'
import type { Uom } from '../types'

interface UomDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  uom: Uom | null
  onEdit: (uom: Uom) => void
}

export function UomDetailDrawer({ open, onOpenChange, uom, onEdit }: UomDetailDrawerProps) {
  if (!uom) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={uom.name}
      subtitle={uom.symbol ?? undefined}
      primaryAction={{ label: 'Edit UOM', icon: Pencil, onClick: () => onEdit(uom) }}
    >
      <DetailSection>
        <DetailField label="Name" value={uom.name} />
        <DetailField label="Symbol" value={uom.symbol ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(uom.created_at)} />
        <DetailField label="Last Updated" value={formatDate(uom.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
