import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { formatDate } from '@/lib/utils'
import type { PriceZone } from '../types'

interface PriceZoneDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  priceZone: PriceZone | null
  onEdit: (priceZone: PriceZone) => void
}

export function PriceZoneDetailDrawer({ open, onOpenChange, priceZone, onEdit }: PriceZoneDetailDrawerProps) {
  if (!priceZone) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={priceZone.name}
      primaryAction={{ label: 'Edit Price Zone', icon: Pencil, onClick: () => onEdit(priceZone) }}
    >
      <DetailSection>
        <DetailField label="Name" value={priceZone.name} />
        <DetailField label="Description" value={priceZone.description ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(priceZone.created_at)} />
        <DetailField label="Last Updated" value={formatDate(priceZone.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
