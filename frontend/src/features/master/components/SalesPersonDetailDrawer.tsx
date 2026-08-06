import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { SalesPerson } from '../types'

interface SalesPersonDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  salesPerson: SalesPerson | null
  onEdit: (salesPerson: SalesPerson) => void
}

export function SalesPersonDetailDrawer({ open, onOpenChange, salesPerson, onEdit }: SalesPersonDetailDrawerProps) {
  if (!salesPerson) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={salesPerson.name}
      subtitle={salesPerson.code}
      badge={<StatusBadge status={salesPerson.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Sales Person', icon: Pencil, onClick: () => onEdit(salesPerson) }}
    >
      <DetailSection>
        <DetailField label="Code" value={salesPerson.code} />
        <DetailField label="Name" value={salesPerson.name} />
        <DetailField label="Phone" value={salesPerson.phone ?? '—'} />
        <DetailField label="Email" value={salesPerson.email ?? '—'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(salesPerson.created_at)} />
        <DetailField label="Last Updated" value={formatDate(salesPerson.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
