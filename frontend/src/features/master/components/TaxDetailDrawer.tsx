import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate } from '@/lib/utils'
import type { Tax } from '../types'

const TYPE_LABELS: Record<Tax['type'], string> = {
  vat: 'VAT',
  zero_rated: 'Zero Rated',
  exempt: 'Tax Exempt',
}

interface TaxDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  tax: Tax | null
  onEdit: (tax: Tax) => void
}

export function TaxDetailDrawer({ open, onOpenChange, tax, onEdit }: TaxDetailDrawerProps) {
  if (!tax) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={tax.name}
      subtitle={tax.code}
      badge={<StatusBadge status={tax.is_active ? 'active' : 'inactive'} />}
      primaryAction={{ label: 'Edit Tax', icon: Pencil, onClick: () => onEdit(tax) }}
    >
      <DetailSection>
        <DetailField label="Code" value={tax.code} />
        <DetailField label="Name" value={tax.name} />
        <DetailField label="Type" value={TYPE_LABELS[tax.type]} />
        <DetailField label="Rate" value={tax.type === 'vat' ? `${tax.rate}%` : 'Not applicable'} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(tax.created_at)} />
        <DetailField label="Last Updated" value={formatDate(tax.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
