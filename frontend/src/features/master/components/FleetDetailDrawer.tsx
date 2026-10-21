import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { Fleet } from '../types'

interface FleetDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  fleet: Fleet | null
  onEdit: (fleet: Fleet) => void
}

export function FleetDetailDrawer({ open, onOpenChange, fleet, onEdit }: FleetDetailDrawerProps) {
  if (!fleet) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={fleet.plat_number}
      subtitle={fleet.default_driver_name ?? undefined}
      badge={<StatusBadge status={fleet.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Fleet', icon: Pencil, onClick: () => onEdit(fleet) }}
    >
      <DetailSection>
        <DetailField label="Plat Number" value={fleet.plat_number} />
        <DetailField label="Default Driver" value={fleet.default_driver_name ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(fleet.created_at)} />
        <DetailField label="Last Updated" value={formatDate(fleet.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
