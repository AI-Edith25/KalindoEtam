import { Pencil } from 'lucide-react'
import { Separator } from '@/components/ui/separator'
import { DetailDrawerLayout, DetailField, DetailSection } from '@/components/shared/DetailDrawerLayout'
import { StatusBadge } from '@/components/shared/StatusBadge'
import { formatDate, formatNumber } from '@/lib/utils'
import type { TermsOfPayment } from '../types'

interface TermsOfPaymentDetailDrawerProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  termsOfPayment: TermsOfPayment | null
  onEdit: (termsOfPayment: TermsOfPayment) => void
}

export function TermsOfPaymentDetailDrawer({ open, onOpenChange, termsOfPayment, onEdit }: TermsOfPaymentDetailDrawerProps) {
  if (!termsOfPayment) return null

  return (
    <DetailDrawerLayout
      open={open}
      onOpenChange={onOpenChange}
      title={termsOfPayment.name}
      subtitle={termsOfPayment.code}
      primaryAction={{ label: 'Edit Terms of Payment', icon: Pencil, onClick: () => onEdit(termsOfPayment) }}
    >
      <DetailSection>
        <DetailField label="Code" value={termsOfPayment.code} />
        <DetailField label="Name" value={termsOfPayment.name} />
        <DetailField label="Days" value={formatNumber(termsOfPayment.days)} />
        <DetailField label="Status" value={<StatusBadge status={termsOfPayment.is_active ? 'active' : 'inactive'} />} />
      </DetailSection>

      <Separator />

      <DetailSection title="Audit Information">
        <DetailField label="Created" value={formatDate(termsOfPayment.created_at)} />
        <DetailField label="Last Updated" value={formatDate(termsOfPayment.updated_at)} />
      </DetailSection>
    </DetailDrawerLayout>
  )
}
